(() => {
    'use strict';

    const CHUNK_SIZE = 2 * 1024 * 1024; // 2 MB per chunk for universal shared-hosting compatibility

    const state = {
        appName: window.CLOUD_BOOT?.app_name || 'PureCloud',
        user: window.CLOUD_BOOT?.user || null,
        csrfToken: window.CLOUD_BOOT?.csrf_token || '',
        baseUrl: window.CLOUD_BOOT?.base_url || '',
        webdavUrl: window.CLOUD_BOOT?.webdav_url || '',
        view: 'files',          // 'files' | 'shared' | 'my_shares' | 'vault' | 'trash'
        currentFolderId: null,
        permission: 'owner',
        search: '',
        layout: localStorage.getItem('cloud_layout') || 'list', // 'list' | 'grid' | 'split'
        theme: localStorage.getItem('cloud_theme') || 'dark',
        breadcrumbs: [],
        folders: [],
        files: [],
        selected: new Map(),    // key: "folder:1" or "file:5" -> {type, id, name, pane}
        // Right pane state for Split-View ('split')
        rightFolderId: null,
        rightBreadcrumbs: [],
        rightFolders: [],
        rightFiles: [],
        splitDropMode: 'move',  // 'move' | 'copy'
        clipboard: null,        // { mode: 'copy' | 'cut', items: [{type, id, name}] }
        undoStack: [],          // Stack of undoable file operations
        sortBy: 'name',         // 'name' | 'size' | 'date'
        sortDir: 'asc',         // 'asc' | 'desc'
        filterType: 'all',      // 'all' | 'folder' | 'doc' | 'image' | 'video' | 'audio' | 'zip' | 'encrypted' | 'shared'
        filterText: '',         // Substring filter on item name
        filterMenuOpen: false,
        calendarMonth: new Date().toISOString().slice(0, 7),
        calendarColorFilter: 'all',
        memberStatusFilter: 'all',
        memberDeptFilter: 'all',
        memberSearch: '',
        memberSortBy: 'name',
        memberSortDir: 'asc',
    };

    // Apply saved theme immediately
    document.documentElement.setAttribute('data-theme', state.theme);

    // DOM Helpers
    const $ = (sel, root = document) => root.querySelector(sel);
    const $$ = (sel, root = document) => Array.from(root.querySelectorAll(sel));

    function esc(str) {
        const div = document.createElement('div');
        div.textContent = str ?? '';
        return div.innerHTML;
    }

    function formatBytes(bytes) {
        const b = Number(bytes) || 0;
        if (b <= 0) return '0 B';
        const units = ['B', 'KB', 'MB', 'GB', 'TB'];
        const i = Math.min(Math.floor(Math.log(b) / Math.log(1024)), units.length - 1);
        const val = b / Math.pow(1024, i);
        return (i > 1 ? val.toFixed(2) : Math.round(val)) + ' ' + units[i];
    }

    function toast(msg, type = 'success') {
        const container = $('#toast-container');
        if (!container) return;
        const el = document.createElement('div');
        el.className = `toast toast-${type}`;
        el.innerHTML = `<span>${type === 'error' ? '⚠️' : '✅'}</span><span>${esc(msg)}</span>`;
        container.appendChild(el);
        setTimeout(() => {
            el.style.opacity = '0';
            setTimeout(() => el.remove(), 250);
        }, 3600);
    }

    async function parseJsonResponse(res) {
        const text = await res.text();
        try {
            return JSON.parse(text);
        } catch (_) {
            if (res.status === 404 && window.location.pathname.startsWith('/files')) {
                window.location.href = '/';
                throw new Error('Weiterleitung auf Hauptdomain…');
            }
            throw new Error(`Server-Antwort ungültig (HTTP ${res.status}). Bitte Seite neu laden.`);
        }
    }

    async function apiGet(params) {
        const qs = new URLSearchParams(params).toString();
        const res = await fetch(`api.php?${qs}`, {
            credentials: 'same-origin',
            headers: { 'Accept': 'application/json' },
        });
        const data = await parseJsonResponse(res);
        if (!res.ok || !data.ok) {
            if (data.auth_required) {
                showAuthScreen();
            }
            throw new Error(data.error || 'Anfrage fehlgeschlagen.');
        }
        return data;
    }

    function summarizeItemsDetail(list) {
        if (!Array.isArray(list) || list.length === 0) return '';
        if (list.length === 1 && list[0].name) return `„${list[0].name}“`;
        if (list[0]?.name) return `„${list[0].name}“ (+${list.length - 1})`;
        return `${list.length} Element(e)`;
    }

    function pushUndo(entry) {
        const now = new Date();
        const timeStr = now.toTimeString().slice(0, 5);
        state.undoStack.push({
            uid: `${Date.now()}_${Math.random().toString(36).slice(2, 7)}`,
            time: timeStr,
            ...entry,
        });
        if (state.undoStack.length > 20) {
            state.undoStack.shift();
        }
        updateUndoButton();
    }

    function updateUndoButton() {
        const btn = $('#btn-undo');
        const menu = $('#undo-history-menu');
        if (!btn) return;
        const hasUndo = state.undoStack.length > 0;
        btn.disabled = !hasUndo;
        btn.style.opacity = hasUndo ? '1' : '0.4';
        if (hasUndo) {
            const top = state.undoStack[state.undoStack.length - 1];
            btn.title = `Verlauf (${state.undoStack.length}) – Letzte Aktion: ${top.label} ${top.detail || ''} (Strg+Z)`;
        } else {
            btn.title = 'Keine Aktion zum Rückgängigmachen (Strg+Z)';
            menu?.classList.add('hidden');
        }
        if (menu && !menu.classList.contains('hidden')) {
            renderUndoHistoryMenu();
        }
    }

    function renderUndoHistoryMenu() {
        const menu = $('#undo-history-menu');
        if (!menu) return;
        if (state.undoStack.length === 0) {
            menu.classList.add('hidden');
            return;
        }

        // Show newest first, allow clicking ANY entry independently of chronology
        const reversed = [...state.undoStack].reverse();
        const rows = reversed.map((e) => `
            <button type="button" class="dropdown-item" data-undo-uid="${esc(e.uid)}" style="justify-content:space-between;gap:0.6rem">
                <span style="min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                    ${e.icon || '↩️'} <strong>${esc(e.label)}</strong>
                    <span class="muted small">${esc(e.detail || '')}</span>
                </span>
                <span class="muted small" style="flex-shrink:0">↩️</span>
            </button>
        `).join('');

        menu.innerHTML = `
            <div class="context-menu-header" style="display:flex;justify-content:space-between;align-items:center">
                <span>Rückgängig machen (${state.undoStack.length})</span>
                <button type="button" class="btn btn-ghost btn-sm" id="btn-clear-undo-stack" style="padding:0.1rem 0.4rem;font-size:0.72rem">Leeren</button>
            </div>
            <div style="max-height:280px;overflow-y:auto;display:flex;flex-direction:column;gap:0.15rem">
                ${rows}
            </div>
        `;

        $$('[data-undo-uid]', menu).forEach((itemBtn) => {
            itemBtn.addEventListener('click', (ev) => {
                ev.stopPropagation();
                const uid = itemBtn.getAttribute('data-undo-uid');
                executeUndoByUid(uid);
            });
        });

        $('#btn-clear-undo-stack', menu)?.addEventListener('click', (ev) => {
            ev.stopPropagation();
            state.undoStack = [];
            updateUndoButton();
        });
    }

    async function executeUndoByUid(uid) {
        const idx = state.undoStack.findIndex((x) => x.uid === uid);
        if (idx === -1) return;
        const [entry] = state.undoStack.splice(idx, 1);
        updateUndoButton();

        try {
            if (entry.type === 'move') {
                await apiPost('undo_moves', { moves: entry.moves, _skip_undo: true });
            } else if (entry.type === 'trash') {
                await apiPost('restore', { items: entry.items, _skip_undo: true });
            } else if (entry.type === 'copy') {
                await apiPost('delete_permanent', { items: entry.created_items, _skip_undo: true });
            } else if (entry.type === 'rename') {
                await apiPost('rename', {
                    type: entry.item_type,
                    id: entry.id,
                    name: entry.old_name,
                    _skip_undo: true,
                });
            }
            toast(`↩️ Rückgängig: ${entry.label} ${entry.detail || ''}`);
            loadCurrentView();
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    async function executeUndo() {
        if (state.undoStack.length === 0) return;
        const newest = state.undoStack[state.undoStack.length - 1];
        await executeUndoByUid(newest.uid);
    }

    $('#btn-undo')?.addEventListener('click', (e) => {
        e.stopPropagation();
        if (state.undoStack.length === 0) return;
        $('#new-action-menu')?.classList.add('hidden');
        const menu = $('#undo-history-menu');
        if (!menu) return;
        const willOpen = menu.classList.contains('hidden');
        if (willOpen) {
            renderUndoHistoryMenu();
            menu.classList.remove('hidden');
        } else {
            menu.classList.add('hidden');
        }
    });

    async function apiPost(action, payload = {}) {
        const res = await fetch(`api.php?action=${encodeURIComponent(action)}`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-CSRF-Token': state.csrfToken,
            },
            body: JSON.stringify({ action, ...payload }),
        });
        const data = await parseJsonResponse(res);
        if (!res.ok || !data.ok) {
            if (data.auth_required) {
                showAuthScreen();
            }
            throw new Error(data.error || 'Aktion fehlgeschlagen.');
        }

        // Automatically record undoable file operations
        if (!payload._skip_undo) {
            if (action === 'move' && Array.isArray(data.undo_moves) && data.undo_moves.length > 0) {
                pushUndo({
                    type: 'move',
                    icon: '✂️',
                    moves: data.undo_moves,
                    label: 'Verschoben',
                    detail: summarizeItemsDetail(data.undo_moves),
                });
            } else if (action === 'trash' && Array.isArray(payload.items) && payload.items.length > 0 && data.count > 0) {
                pushUndo({
                    type: 'trash',
                    icon: '🗑️',
                    items: payload.items,
                    label: 'Gelöscht',
                    detail: summarizeItemsDetail(payload.items),
                });
            } else if (action === 'copy' && Array.isArray(data.created_items) && data.created_items.length > 0) {
                pushUndo({
                    type: 'copy',
                    icon: '📋',
                    created_items: data.created_items,
                    label: 'Kopiert',
                    detail: summarizeItemsDetail(data.created_items),
                });
            } else if (action === 'rename' && payload.old_name && payload.old_name !== payload.name) {
                pushUndo({
                    type: 'rename',
                    icon: '✏️',
                    item_type: payload.type,
                    id: payload.id,
                    old_name: payload.old_name,
                    label: 'Umbenannt',
                    detail: `„${payload.old_name}“ → „${payload.name}“`,
                });
            }
        }

        return data;
    }

    // Modal System
    function openModal(title, htmlContent, large = false) {
        const backdrop = $('#modal-backdrop');
        const dialog = $('#modal-dialog');
        $('#modal-title').textContent = title;
        $('#modal-body').innerHTML = htmlContent;
        dialog.classList.toggle('modal-lg', large);
        backdrop.classList.remove('hidden');
    }

    function closeModal() {
        $('#modal-backdrop').classList.add('hidden');
        $('#modal-body').innerHTML = '';
    }

    $('#modal-close')?.addEventListener('click', closeModal);
    $('#modal-backdrop')?.addEventListener('click', (e) => {
        if (e.target.id === 'modal-backdrop') closeModal();
    });
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape' && !$('#modal-backdrop').classList.contains('hidden')) {
            closeModal();
        }
    });

    // Auth & Boot
    let active2faMethod = 'totp';
    let pending2faMaskedEmail = '';

    function b64urlToUint8Array(base64url) {
        const padding = '='.repeat((4 - (base64url.length % 4)) % 4);
        const base64 = (base64url + padding).replace(/-/g, '+').replace(/_/g, '/');
        const raw = window.atob(base64);
        const out = new Uint8Array(raw.length);
        for (let i = 0; i < raw.length; i++) {
            out[i] = raw.charCodeAt(i);
        }
        return out;
    }

    function bufferToB64url(buffer) {
        const bytes = new Uint8Array(buffer);
        let bin = '';
        for (let i = 0; i < bytes.byteLength; i++) {
            bin += String.fromCharCode(bytes[i]);
        }
        return window.btoa(bin).replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');
    }

    function showAuthScreen() {
        state.user = null;
        $('#app-shell').classList.add('hidden');
        $('#auth-screen').classList.remove('hidden');
        $('#setup-card').classList.add('hidden');
        $('#twofactor-card')?.classList.add('hidden');
        $('#login-card').classList.remove('hidden');
    }

    function enterAppShell() {
        $('#auth-screen').classList.add('hidden');
        $('#twofactor-card')?.classList.add('hidden');
        $('#app-shell').classList.remove('hidden');
        updateUserSidebar();
        loadCurrentView();
    }

    function showTwoFactorCard(res) {
        const methods = res.methods || ['totp'];
        active2faMethod = res.default_method || methods[0] || 'totp';
        pending2faMaskedEmail = res.masked_email || '';

        $('#login-card').classList.add('hidden');
        $('#twofactor-card').classList.remove('hidden');
        $('#twofactor-error').classList.add('hidden');

        const tabsWrap = $('#twofactor-method-tabs');
        const labels = {
            totp: '📱 Authenticator',
            passkey: '🔑 Biometrie',
            email: '✉️ E-Mail',
        };
        tabsWrap.innerHTML = methods.map((m) => `
            <button type="button" class="tab-btn ${m === active2faMethod ? 'active' : ''}" data-2fa-m="${m}">
                ${labels[m] || m}
            </button>
        `).join('');

        $$('[data-2fa-m]', tabsWrap).forEach((btn) => {
            btn.addEventListener('click', () => {
                active2faMethod = btn.getAttribute('data-2fa-m') || 'totp';
                $$('[data-2fa-m]', tabsWrap).forEach((b) => b.classList.toggle('active', b === btn));
                updateTwoFactorMethodUI();
            });
        });

        updateTwoFactorMethodUI();
    }

    function updateTwoFactorMethodUI() {
        const isPasskey = active2faMethod === 'passkey';
        const isEmail = active2faMethod === 'email';

        $('#twofactor-code-form').classList.toggle('hidden', isPasskey);
        $('#twofactor-passkey-box').classList.toggle('hidden', !isPasskey);
        $('#btn-resend-email-2fa').classList.toggle('hidden', !isEmail);

        if (isPasskey) {
            $('#twofactor-subtitle').textContent = 'Bestätigen Sie Ihre Identität per Fingerabdruck, FaceID oder Sicherheitsschlüssel.';
        } else if (isEmail) {
            $('#twofactor-subtitle').textContent = pending2faMaskedEmail
                ? `Geben Sie den 6-stelligen Code ein, der an ${pending2faMaskedEmail} gesendet wurde.`
                : 'Fordern Sie einen 6-stelligen Einmalcode per E-Mail an.';
            $('#twofactor-code-label').textContent = '6-stelliger E-Mail-Code';
            $('#twofactor-code-input').value = '';
            $('#twofactor-code-input').focus();
        } else {
            $('#twofactor-subtitle').textContent = 'Geben Sie den 6-stelligen Code aus Ihrer Authenticator-App ein.';
            $('#twofactor-code-label').textContent = '6-stelliger Authenticator-Code (TOTP)';
            $('#twofactor-code-input').value = '';
            $('#twofactor-code-input').focus();
        }
    }

    function updateUserSidebar() {
        if (!state.user) return;
        $('#user-display-name').textContent = state.user.display_name || state.user.username;
        $('#user-role-badge').textContent = state.user.role === 'admin' ? 'Administrator' : 'Benutzer';
        $('#user-avatar').textContent = (state.user.display_name || state.user.username || 'U').charAt(0).toUpperCase();
        const showAdmin = !!(state.user.role === 'admin' || state.user.has_admin_panel);
        $('#btn-admin-modal')?.classList.toggle('hidden', !showAdmin);
        const showMembers = !!(state.user.role === 'admin' || state.user.perm_members);
        $('#nav-item-members')?.classList.toggle('hidden', !showMembers);
        updateQuotaBar(state.user.used_bytes, state.user.quota_bytes);
    }

    function updateQuotaBar(usedBytes, quotaBytes) {
        const used = Number(usedBytes) || 0;
        const quota = Number(quotaBytes) || 0;
        if (quota <= 0) {
            $('#quota-text').textContent = `${formatBytes(used)} / ∞`;
            $('#quota-fill').style.width = '8%';
        } else {
            const pct = Math.min(100, Math.round((used / quota) * 100));
            $('#quota-text').textContent = `${formatBytes(used)} / ${formatBytes(quota)}`;
            $('#quota-fill').style.width = `${pct}%`;
            $('#quota-fill').style.background = pct > 90 ? 'var(--danger)' : 'var(--primary)';
        }
    }

    // Setup Form
    $('#setup-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const errBox = $('#setup-error');
        errBox.classList.add('hidden');
        try {
            const res = await apiPost('setup_admin', Object.fromEntries(fd.entries()));
            state.user = res.user;
            state.csrfToken = res.csrf_token;
            enterAppShell();
            toast('Willkommen! Ihr Administrator-Konto wurde eingerichtet.');
        } catch (err) {
            errBox.textContent = err.message;
            errBox.classList.remove('hidden');
        }
    });

    // Login Form
    $('#login-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const fd = new FormData(e.target);
        const errBox = $('#login-error');
        errBox.classList.add('hidden');
        try {
            const res = await apiPost('login', Object.fromEntries(fd.entries()));
            if (res.two_factor_required) {
                showTwoFactorCard(res);
                return;
            }
            state.user = res.user;
            state.csrfToken = res.csrf_token;
            e.target.reset();
            enterAppShell();
        } catch (err) {
            errBox.textContent = err.message;
            errBox.classList.remove('hidden');
        }
    });

    // 2FA Verification Form (TOTP / Email)
    $('#twofactor-code-form')?.addEventListener('submit', async (e) => {
        e.preventDefault();
        const errBox = $('#twofactor-error');
        errBox.classList.add('hidden');
        try {
            const code = $('#twofactor-code-input').value.trim();
            const res = await apiPost('verify_2fa', { method: active2faMethod, code });
            state.user = res.user;
            state.csrfToken = res.csrf_token;
            $('#login-form')?.reset();
            enterAppShell();
        } catch (err) {
            errBox.textContent = err.message;
            errBox.classList.remove('hidden');
        }
    });

    $('#btn-resend-email-2fa')?.addEventListener('click', async () => {
        try {
            const res = await apiPost('send_email_2fa');
            pending2faMaskedEmail = res.masked_email || pending2faMaskedEmail;
            updateTwoFactorMethodUI();
            toast(`Neuer Sicherheitscode an ${pending2faMaskedEmail} gesendet.`);
        } catch (err) {
            toast(err.message, 'error');
        }
    });

    $('#btn-cancel-2fa')?.addEventListener('click', () => {
        showAuthScreen();
    });

    // Biometric / Passkey Login (Direct or as 2FA step)
    async function executePasskeyLogin(usernameHint = '') {
        if (!window.PublicKeyCredential) {
            throw new Error('Ihr Browser oder Gerät unterstützt keine WebAuthn-Biometrie / Passkeys.');
        }
        const opts = await apiPost('passkey_login_options', { username: usernameHint });
        const publicKey = {
            challenge: b64urlToUint8Array(opts.challengeB64),
            rpId: opts.rpId,
            userVerification: 'preferred',
            timeout: 60000,
        };
        if (opts.allowCredentials && opts.allowCredentials.length > 0) {
            publicKey.allowCredentials = opts.allowCredentials.map((c) => ({
                type: 'public-key',
                id: b64urlToUint8Array(c.id),
            }));
        }

        const assertion = await navigator.credentials.get({ publicKey });
        if (!assertion) {
            throw new Error('Biometrie-Anmeldung abgebrochen.');
        }

        const res = await apiPost('passkey_login_verify', {
            credential_id: bufferToB64url(assertion.rawId),
            client_data_json: bufferToB64url(assertion.response.clientDataJSON),
            authenticator_data: bufferToB64url(assertion.response.authenticatorData),
            signature: bufferToB64url(assertion.response.signature),
            remember: !!$('#login-remember-chk')?.checked,
        });

        state.user = res.user;
        state.csrfToken = res.csrf_token;
        $('#login-form')?.reset();
        enterAppShell();
        toast('Per Biometrie / Passkey angemeldet!');
    }

    $('#btn-passkey-direct-login')?.addEventListener('click', async () => {
        const errBox = $('#login-error');
        errBox.classList.add('hidden');
        try {
            const uname = $('#login-username-input')?.value.trim() || '';
            await executePasskeyLogin(uname);
        } catch (err) {
            errBox.textContent = err.message;
            errBox.classList.remove('hidden');
        }
    });

    $('#btn-verify-passkey-2fa')?.addEventListener('click', async () => {
        const errBox = $('#twofactor-error');
        errBox.classList.add('hidden');
        try {
            await executePasskeyLogin('');
        } catch (err) {
            errBox.textContent = err.message;
            errBox.classList.remove('hidden');
        }
    });

    // Logout
    $('#btn-logout')?.addEventListener('click', async () => {
        try {
            await apiPost('logout');
        } catch (_) {}
        showAuthScreen();
    });

    // Theme Toggle
    $('#btn-theme-toggle')?.addEventListener('click', () => {
        state.theme = state.theme === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', state.theme);
        localStorage.setItem('cloud_theme', state.theme);
    });

    // Mobile Sidebar Toggle & Close
    function setMobileSidebarOpen(open) {
        $('#sidebar')?.classList.toggle('open', open);
        $('#sidebar-backdrop')?.classList.toggle('hidden', !open);
    }

    $('#btn-mobile-sidebar')?.addEventListener('click', () => {
        const isOpen = $('#sidebar')?.classList.contains('open');
        setMobileSidebarOpen(!isOpen);
    });

    $('#btn-close-sidebar')?.addEventListener('click', () => setMobileSidebarOpen(false));
    $('#sidebar-backdrop')?.addEventListener('click', () => setMobileSidebarOpen(false));

    // Layout Toggle (List vs Grid vs Split)
    function updateLayoutButtons() {
        $('#btn-layout-list').classList.toggle('active', state.layout === 'list');
        $('#btn-layout-grid').classList.toggle('active', state.layout === 'grid');
        $('#btn-layout-split')?.classList.toggle('active', state.layout === 'split');
    }
    updateLayoutButtons();

    $('#btn-layout-list')?.addEventListener('click', () => {
        state.layout = 'list';
        localStorage.setItem('cloud_layout', 'list');
        updateLayoutButtons();
        loadCurrentView();
    });

    $('#btn-layout-grid')?.addEventListener('click', () => {
        state.layout = 'grid';
        localStorage.setItem('cloud_layout', 'grid');
        updateLayoutButtons();
        loadCurrentView();
    });

    $('#btn-layout-split')?.addEventListener('click', () => {
        state.layout = 'split';
        localStorage.setItem('cloud_layout', 'split');
        if (state.view !== 'files') {
            state.view = 'files';
            $$('.sidebar-nav .nav-item').forEach((b) => b.classList.toggle('active', b.dataset.view === 'files'));
        }
        updateLayoutButtons();
        loadCurrentView();
    });

    // Collapsible sub-menu under "📁 Dateien"
    $('#btn-toggle-files-sub')?.addEventListener('click', (e) => {
        e.stopPropagation();
        const sub = $('#nav-files-sub');
        const chev = $('#btn-toggle-files-sub');
        if (!sub || !chev) return;
        const isCollapsed = sub.classList.toggle('hidden');
        chev.classList.toggle('collapsed', isCollapsed);
    });

    // Sidebar Navigation & Drag-and-Drop Targets (Tresor, Papierkorb, Hauptordner)
    $$('.sidebar-nav .nav-item').forEach((btn) => {
        const navView = btn.dataset.view || 'files';

        btn.addEventListener('click', () => {
            $$('.sidebar-nav .nav-item').forEach((b) => b.classList.remove('active'));
            btn.classList.add('active');
            state.view = navView;
            state.currentFolderId = null;
            state.search = '';
            $('#search-input').value = '';
            setMobileSidebarOpen(false);
            loadCurrentView();
        });

        if (['vault', 'trash', 'files'].includes(navView)) {
            btn.addEventListener('dragover', (e) => {
                if (e.dataTransfer.types.includes('application/x-cloud-item')) {
                    e.preventDefault();
                    btn.classList.add('drag-over');
                }
            });
            btn.addEventListener('dragleave', () => btn.classList.remove('drag-over'));
            btn.addEventListener('drop', async (e) => {
                btn.classList.remove('drag-over');
                const raw = e.dataTransfer.getData('application/x-cloud-item');
                if (!raw) return;
                e.preventDefault();
                e.stopPropagation();

                const items = JSON.parse(raw);
                if (!items || items.length === 0) return;

                if (navView === 'vault') {
                    openBatchVaultModal(items);
                } else if (navView === 'trash') {
                    try {
                        const res = await apiPost('trash', { items });
                        toast(`${res.count} Element(e) in den Papierkorb verschoben.`);
                        loadCurrentView();
                    } catch (err) {
                        toast(err.message, 'error');
                    }
                } else if (navView === 'files') {
                    try {
                        const res = await apiPost('move', { items, target_folder_id: null });
                        toast(`${res.moved} Element(e) in den Hauptordner verschoben.`);
                        loadCurrentView();
                    } catch (err) {
                        toast(err.message, 'error');
                    }
                }
            });
        }
    });

    function openBatchVaultModal(items) {
        if (items.length === 1 && items[0].type === 'folder') {
            openVaultModal({ type: 'folder', id: items[0].id, name: items[0].name });
            return;
        }
        const namesList = items.slice(0, 5).map((x) => `${x.type === 'folder' ? '📁' : '📄'} ${esc(x.name)}`).join('<br>');
        const moreText = items.length > 5 ? `<br><span class="muted">… und ${items.length - 5} weitere</span>` : '';

        openModal(`🔒 Im Tresor verschlüsseln (${items.length} Element${items.length > 1 ? 'e' : ''})`, `
            <p class="muted small" style="margin-top:0">
                Folgende Elemente (inkl. etwaiger Unterordner) werden mit <strong>AES-256-GCM</strong> (Zero-Knowledge) verschlüsselt:
            </p>
            <div class="code-box small" style="margin-bottom:0.9rem">${namesList}${moreText}</div>
            <form id="batch-vault-form">
                <div class="form-group">
                    <label>Tresor-Passwort festlegen (mind. 4 Zeichen)</label>
                    <input type="password" name="password" class="input" placeholder="Tresor-Passwort…" required minlength="4">
                </div>
                <div class="form-group">
                    <label>Passwort wiederholen</label>
                    <input type="password" name="password2" class="input" placeholder="Passwort bestätigen…" required minlength="4">
                </div>
                <button type="submit" class="btn btn-primary btn-block">🔒 Jetzt im Tresor verschlüsseln</button>
            </form>
        `);
        $('#batch-vault-form')?.querySelector('input')?.focus();
        $('#batch-vault-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const pw1 = e.target.password.value;
            const pw2 = e.target.password2.value;
            if (pw1 !== pw2) {
                toast('Die Passwörter stimmen nicht überein.', 'error');
                return;
            }
            const btn = e.target.querySelector('button[type="submit"]');
            btn.disabled = true;
            btn.textContent = 'Verschlüssele…';
            try {
                const res = await apiPost('vault_encrypt_items', { items, password: pw1 });
                closeModal();
                toast(`${res.encrypted_count} Datei(en) erfolgreich im Tresor verschlüsselt!`);
                loadCurrentView();
            } catch (err) {
                toast(err.message, 'error');
                btn.disabled = false;
                btn.textContent = '🔒 Jetzt im Tresor verschlüsseln';
            }
        });
    }

    // Search Input (Debounced)
    let searchTimer = null;
    $('#search-input')?.addEventListener('input', (e) => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => {
            state.search = e.target.value.trim();
            if (state.view === 'my_shares') {
                state.view = 'files';
                $$('.sidebar-nav .nav-item').forEach((b) => b.classList.toggle('active', b.dataset.view === 'files'));
            }
            loadCurrentView();
        }, 250);
    });

    // Main View Loader
    async function loadCurrentView() {
        state.selected.clear();
        updateBulkToolbar();

        if (state.view === 'my_shares') {
            await loadMySharesView();
            return;
        }
        if (state.view === 'calendar') {
            await loadCalendarView();
            return;
        }
        if (state.view === 'members') {
            await loadMembersView();
            return;
        }

        try {
            const params = {
                action: 'list',
                view: state.view,
            };
            if (state.currentFolderId !== null) {
                params.folder_id = state.currentFolderId;
            }
            if (state.search !== '') {
                params.search = state.search;
            }

            const isSplit = state.layout === 'split' && state.view === 'files' && state.search === '';
            const rightPromise = isSplit
                ? apiGet({
                    action: 'list',
                    view: 'files',
                    ...(state.rightFolderId !== null ? { folder_id: state.rightFolderId } : {}),
                }).catch(() => {
                    state.rightFolderId = null;
                    return apiGet({ action: 'list', view: 'files' });
                })
                : Promise.resolve(null);

            const [res, rightRes] = await Promise.all([apiGet(params), rightPromise]);
            const data = res.data;
            state.permission = data.permission || 'owner';
            state.isGroupTree = Boolean(data.is_group_tree);
            state.breadcrumbs = data.breadcrumbs || [];
            state.folders = data.folders || [];
            state.files = data.files || [];

            if (rightRes && rightRes.data) {
                state.rightBreadcrumbs = rightRes.data.breadcrumbs || [];
                state.rightFolders = rightRes.data.folders || [];
                state.rightFiles = rightRes.data.files || [];
            }

            if (res.user) {
                state.user.used_bytes = res.user.used_bytes;
                state.user.quota_bytes = res.user.quota_bytes;
                updateQuotaBar(res.user.used_bytes, res.user.quota_bytes);
            }

            const canWriteHere = (state.view === 'files' || (state.view === 'shared' && state.currentFolderId !== null))
                && ['owner', 'write'].includes(state.permission)
                && state.search === '';

            $('#folder-write-actions').classList.toggle('hidden', !canWriteHere);
            $('#trash-toolbar').classList.toggle('hidden', state.view !== 'trash' || (state.folders.length === 0 && state.files.length === 0));

            renderBreadcrumbs();
            renderItems();
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function renderBreadcrumbs() {
        const nav = $('#breadcrumbs');
        nav.innerHTML = '';
        state.breadcrumbs.forEach((crumb, idx) => {
            if (idx > 0) {
                const sep = document.createElement('span');
                sep.className = 'crumb-sep';
                sep.textContent = '/';
                nav.appendChild(sep);
            }
            const span = document.createElement('span');
            span.className = 'crumb' + (idx === state.breadcrumbs.length - 1 ? ' active' : '');
            span.dataset.crumbId = crumb.id === null ? '' : String(crumb.id);
            span.dataset.crumbName = crumb.name;
            span.textContent = crumb.name;
            span.addEventListener('click', () => {
                state.currentFolderId = crumb.id;
                state.search = '';
                $('#search-input').value = '';
                loadCurrentView();
            });

            // Allow dropping items onto a breadcrumb to move them up!
            if (state.view === 'files') {
                span.addEventListener('dragover', (e) => {
                    if (e.dataTransfer.types.includes('application/x-cloud-item')) {
                        e.preventDefault();
                        span.classList.add('drag-over');
                    }
                });
                span.addEventListener('dragleave', () => span.classList.remove('drag-over'));
                span.addEventListener('drop', async (e) => {
                    span.classList.remove('drag-over');
                    const raw = e.dataTransfer.getData('application/x-cloud-item');
                    if (!raw) return;
                    e.preventDefault();
                    try {
                        const items = JSON.parse(raw);
                        await apiPost('move', { items, target_folder_id: crumb.id });
                        toast('Verschoben.');
                        loadCurrentView();
                    } catch (err) {
                        toast(err.message, 'error');
                    }
                });
            }

            nav.appendChild(span);
        });
    }

    function fileIcon(file) {
        if (file.is_encrypted) return '🔒';
        switch (file.category) {
            case 'image': return '🖼️';
            case 'video': return '🎬';
            case 'audio': return '🎵';
            case 'pdf':   return '📕';
            case 'zip':   return '📦';
            case 'text':  return '📝';
            default:      return '📄';
        }
    }

    function filterLabel(ft) {
        switch (ft) {
            case 'folder':    return '📁 Ordner';
            case 'doc':       return '📄 Dokumente';
            case 'image':     return '🖼️ Bilder';
            case 'video':     return '🎬 Videos';
            case 'audio':     return '🎵 Audio';
            case 'zip':       return '📦 ZIP';
            case 'encrypted': return '🔒 Tresor';
            case 'shared':    return '🔗 Geteilt';
            default:          return 'Filter';
        }
    }

    function activeFilterSummary() {
        const ft = state.filterType || 'all';
        const q = (state.filterText || '').trim();
        if (ft !== 'all' && q !== '') {
            return `${filterLabel(ft)} · „${q}“`;
        }
        if (q !== '') {
            return `🔍 „${q}“`;
        }
        return filterLabel(ft);
    }

    function hasActiveFilter() {
        return (state.filterType && state.filterType !== 'all') || (state.filterText || '').trim() !== '';
    }

    function filterAndSortItems(folders, files) {
        const ft = state.filterType || 'all';
        const q = (state.filterText || '').trim().toLowerCase();
        const matchItem = (it) => {
            if (q !== '' && !String(it.name || '').toLowerCase().includes(q)) {
                return false;
            }
            if (ft === 'all') return true;
            if (ft === 'folder') return it.type === 'folder';
            if (ft === 'encrypted') return Boolean(it.is_encrypted || it.encrypted_count > 0);
            if (ft === 'shared') return Boolean(it.share_count > 0 || it.group_name);
            if (it.type === 'folder') return false;
            if (ft === 'doc') return ['pdf', 'text', 'other'].includes(it.category);
            return it.category === ft;
        };

        const dir = state.sortDir === 'desc' ? -1 : 1;
        const cmp = (a, b) => {
            if (a.is_group_root && !b.is_group_root) return -1;
            if (!a.is_group_root && b.is_group_root) return 1;
            if (state.sortBy === 'size' && a.type === 'file' && b.type === 'file') {
                const diff = (Number(a.size_bytes || 0) - Number(b.size_bytes || 0)) * dir;
                if (diff !== 0) return diff;
            } else if (state.sortBy === 'date') {
                const da = String(a.updated_at || '');
                const db = String(b.updated_at || '');
                const diff = da.localeCompare(db) * dir;
                if (diff !== 0) return diff;
            }
            return String(a.name || '').localeCompare(String(b.name || ''), 'de', { numeric: true, sensitivity: 'base' }) * dir;
        };

        const sortedFolders = folders.filter(matchItem).slice().sort(cmp);
        const sortedFiles = files.filter(matchItem).slice().sort(cmp);
        return [...sortedFolders, ...sortedFiles];
    }

    function renderItems() {
        const container = $('#items-container');
        if (state.layout === 'split' && state.view === 'files' && state.search === '') {
            renderSplitView(container);
            return;
        }

        const rawTotal = state.folders.length + state.files.length;
        const allItems = filterAndSortItems(state.folders, state.files);

        if (rawTotal === 0) {
            const emptyMsg = state.view === 'trash'
                ? 'Der Papierkorb ist leer.'
                : state.view === 'vault'
                    ? 'Keine verschlüsselten Dateien im Tresor. Sie können jede Datei über das 🔒-Symbol mit AES-256 verschlüsseln.'
                    : state.view === 'shared'
                        ? 'Noch keine Dateien oder Ordner für Sie freigegeben.'
                        : 'Dieser Ordner ist leer. Ziehen Sie Dateien oder ganze Ordner per Drag & Drop hierher!';
            container.innerHTML = `
                <div class="empty-state">
                    <div class="empty-state-icon">${state.view === 'trash' ? '🗑️' : state.view === 'vault' ? '🔒' : '📂'}</div>
                    <h3>Keine Einträge</h3>
                    <p class="muted">${esc(emptyMsg)}</p>
                </div>
            `;
            return;
        }

        if (state.layout === 'grid') {
            renderGridView(container, allItems);
        } else {
            renderListView(container, allItems);
        }
    }

    function renderSplitView(container) {
        const leftItems = filterAndSortItems(state.folders, state.files);
        const rightItems = filterAndSortItems(state.rightFolders, state.rightFiles);

        const renderPaneCrumbs = (crumbs, side) => crumbs.map((c, idx) => `
            ${idx > 0 ? '<span class="crumb-sep">/</span>' : ''}
            <span class="crumb ${idx === crumbs.length - 1 ? 'active' : ''}" data-split-crumb="${side}" data-folder-id="${c.id ?? ''}">${esc(c.name)}</span>
        `).join('');

        const renderPaneRows = (items, side) => {
            if (items.length === 0) {
                return `<tr><td colspan="3" class="empty-cell">Ordner ist leer – Dateien hierher ziehen</td></tr>`;
            }
            return items.map((item) => {
                const key = `${item.type}:${item.id}`;
                const isSel = state.selected.has(key);
                const isFolder = item.type === 'folder';
                const hasThumb = !isFolder && item.category === 'image' && !item.is_encrypted;
                return `
                    <tr data-split-side="${side}" data-key="${key}" data-type="${item.type}" data-id="${item.id}" draggable="${!item.is_group_folder}" class="${isSel ? 'selected' : ''}">
                        <td style="width:32px">
                            <input type="checkbox" class="row-chk" data-side="${side}" data-key="${key}" ${isSel ? 'checked' : ''}>
                        </td>
                        <td>
                            <div class="item-cell" data-split-open="${key}" data-side="${side}" style="min-width:140px">
                                ${hasThumb
                                    ? `<img class="item-thumb" loading="lazy" src="api.php?action=thumb&id=${item.id}" alt="">`
                                    : `<div class="item-icon">${isFolder ? '📁' : fileIcon(item)}</div>`
                                }
                                <div class="item-name-wrap">
                                    <span class="item-name">${esc(item.name)}</span>
                                    ${item.is_encrypted || item.encrypted_count > 0 ? '<span class="badge badge-vault">🔒</span>' : ''}
                                    ${item.share_count > 0 ? '<span class="badge badge-shared">🔗</span>' : ''}
                                    ${item.group_name ? `<span class="badge badge-shared" title="Gruppenordner">👪 ${esc(item.group_name)}</span>` : ''}
                                </div>
                            </div>
                        </td>
                        <td class="muted small" style="width:90px;text-align:right;white-space:nowrap">${isFolder ? '—' : formatBytes(item.size_bytes)}</td>
                    </tr>
                `;
            }).join('');
        };

        container.innerHTML = `
            <div class="split-workspace">
                <!-- Pane 1 -->
                <section class="split-pane" data-pane-side="left" data-pane-folder="${state.currentFolderId ?? ''}">
                    <header class="split-pane-header">
                        <div class="split-pane-crumbs">${renderPaneCrumbs(state.breadcrumbs, 'left')}</div>
                        <button type="button" class="btn btn-ghost btn-sm" data-split-new-folder="left" title="Neuer Ordner">➕ Ordner</button>
                    </header>
                    <div class="file-table-wrap">
                        <table class="file-table">
                            <thead>
                                <tr>
                                    <th style="width:32px"></th>
                                    <th>Name</th>
                                    <th style="text-align:right">Größe</th>
                                </tr>
                            </thead>
                            <tbody>${renderPaneRows(leftItems, 'left')}</tbody>
                        </table>
                    </div>
                </section>

                <!-- Pane 2 -->
                <section class="split-pane" data-pane-side="right" data-pane-folder="${state.rightFolderId ?? ''}">
                    <header class="split-pane-header">
                        <div class="split-pane-crumbs">${renderPaneCrumbs(state.rightBreadcrumbs, 'right')}</div>
                        <button type="button" class="btn btn-ghost btn-sm" data-split-new-folder="right" title="Neuer Ordner">➕ Ordner</button>
                    </header>
                    <div class="file-table-wrap">
                        <table class="file-table">
                            <thead>
                                <tr>
                                    <th style="width:32px"></th>
                                    <th>Name</th>
                                    <th style="text-align:right">Größe</th>
                                </tr>
                            </thead>
                            <tbody>${renderPaneRows(rightItems, 'right')}</tbody>
                        </table>
                    </div>
                </section>
            </div>
        `;

        bindSplitViewEvents(container, leftItems, rightItems);
    }

    function bindSplitViewEvents(container, leftItems, rightItems) {
        const itemMap = new Map([...leftItems, ...rightItems].map((it) => [`${it.type}:${it.id}`, it]));

        async function executeSplitTransfer(items, targetFolderId, forceCopy = false) {
            if (!items || items.length === 0) {
                toast('Bitte zuerst Dateien oder Ordner auswählen.', 'error');
                return;
            }
            const action = forceCopy ? 'copy' : 'move';
            try {
                const res = await apiPost(action, { items, target_folder_id: targetFolderId });
                const count = action === 'copy' ? res.copied : res.moved;
                toast(`${count} Element(e) ${action === 'copy' ? 'kopiert' : 'verschoben'}.`);
                loadCurrentView();
            } catch (err) {
                toast(err.message, 'error');
            }
        }

        // Breadcrumb clicks & drop targets in both panes
        $$('[data-split-crumb]', container).forEach((el) => {
            const side = el.dataset.splitCrumb;
            const rawId = el.dataset.folderId;
            const folderId = rawId === '' ? null : Number(rawId);

            el.addEventListener('click', () => {
                if (side === 'left') {
                    state.currentFolderId = folderId;
                } else {
                    state.rightFolderId = folderId;
                }
                loadCurrentView();
            });

            el.addEventListener('dragover', (e) => {
                if (e.dataTransfer.types.includes('application/x-cloud-item')) {
                    e.preventDefault();
                    el.classList.add('drag-over');
                }
            });
            el.addEventListener('dragleave', () => el.classList.remove('drag-over'));
            el.addEventListener('drop', async (e) => {
                el.classList.remove('drag-over');
                const raw = e.dataTransfer.getData('application/x-cloud-item');
                if (!raw) return;
                e.preventDefault();
                e.stopPropagation();
                await executeSplitTransfer(JSON.parse(raw), folderId, e.ctrlKey || e.altKey || e.metaKey);
            });
        });

        // New folder button per pane
        $$('[data-split-new-folder]', container).forEach((btn) => {
            btn.addEventListener('click', () => {
                const side = btn.dataset.splitNewFolder;
                const parentId = side === 'left' ? state.currentFolderId : state.rightFolderId;
                openModal(`Neuer Ordner (${side === 'left' ? 'Linkes Fenster' : 'Rechtes Fenster'})`, `
                    <form id="split-new-folder-form">
                        <div class="form-group">
                            <label>Ordnername</label>
                            <input type="text" name="name" class="input" placeholder="Ordnername…" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Ordner erstellen</button>
                    </form>
                `);
                $('#split-new-folder-form').querySelector('input').focus();
                $('#split-new-folder-form').addEventListener('submit', async (e) => {
                    e.preventDefault();
                    const name = e.target.name.value.trim();
                    try {
                        await apiPost('create_folder', { parent_id: parentId, name });
                        closeModal();
                        toast(`Ordner "${name}" erstellt.`);
                        loadCurrentView();
                    } catch (err) {
                        toast(err.message, 'error');
                    }
                });
            });
        });

        // Checkboxes
        $$('.row-chk', container).forEach((chk) => {
            chk.addEventListener('click', (e) => e.stopPropagation());
            chk.addEventListener('change', () => {
                const key = chk.dataset.key;
                const it = itemMap.get(key);
                if (!it) return;
                if (chk.checked) {
                    state.selected.set(key, it);
                } else {
                    state.selected.delete(key);
                }
                $$(`[data-key="${key}"]`, container).forEach((r) => r.classList.toggle('selected', chk.checked));
                updateBulkToolbar();
            });
        });

        // Open folder in the respective pane or open file modal
        $$('[data-split-open]', container).forEach((el) => {
            el.addEventListener('click', (e) => {
                if (el.closest('[data-key]')?.dataset.longPressed === '1') {
                    el.closest('[data-key]').dataset.longPressed = '0';
                    e.preventDefault();
                    return;
                }
                const item = itemMap.get(el.dataset.splitOpen);
                const side = el.dataset.side;
                if (!item) return;
                if (item.type === 'folder') {
                    if (side === 'left') {
                        state.currentFolderId = item.id;
                    } else {
                        state.rightFolderId = item.id;
                    }
                    loadCurrentView();
                } else {
                    openFileModal(item);
                }
            });
        });

        // Drag start, context menu (right-click & long-touch) & folder row drop targets
        $$('tr[data-key]', container).forEach((row) => {
            const key = row.dataset.key;
            const item = itemMap.get(key);
            if (!item) return;

            bindContextMenuEvents(row, item, row.dataset.splitSide || 'left');

            row.addEventListener('dragstart', (e) => {
                let dragList = [{ type: item.type, id: item.id, name: item.name }];
                if (state.selected.has(key)) {
                    dragList = Array.from(state.selected.values()).map((x) => ({ type: x.type, id: x.id, name: x.name }));
                }
                e.dataTransfer.setData('application/x-cloud-item', JSON.stringify(dragList));
                e.dataTransfer.effectAllowed = 'copyMove';
            });

            if (item.type === 'folder') {
                row.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    e.stopPropagation();
                    row.classList.add('drag-over');
                    const lbl = $('#drop-target-label');
                    if (lbl) lbl.textContent = `In Ordner „${item.name}“ ablegen`;
                });
                row.addEventListener('dragleave', () => {
                    row.classList.remove('drag-over');
                    const lbl = $('#drop-target-label');
                    if (lbl) lbl.textContent = 'Dateien hier ablegen (oder direkt auf einen Ordner ziehen)';
                });
                row.addEventListener('drop', async (e) => {
                    row.classList.remove('drag-over');
                    const raw = e.dataTransfer.getData('application/x-cloud-item');
                    if (raw) {
                        e.preventDefault();
                        e.stopPropagation();
                        await executeSplitTransfer(JSON.parse(raw), item.id, e.ctrlKey || e.altKey || e.metaKey);
                        return;
                    }
                    if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                        e.preventDefault();
                        e.stopPropagation();
                        const entries = await extractDroppedFileEntries(e.dataTransfer);
                        await uploadFileEntries(entries, item.id);
                    }
                });
            }
        });

        // Dropping anywhere onto Left Pane or Right Pane
        $$('.split-pane', container).forEach((pane) => {
            const rawFolder = pane.dataset.paneFolder;
            const paneFolderId = rawFolder === '' ? null : Number(rawFolder);

            pane.addEventListener('dragover', (e) => {
                if (e.dataTransfer.types.includes('application/x-cloud-item') || e.dataTransfer.types.includes('Files')) {
                    e.preventDefault();
                    pane.classList.add('pane-drop-target');
                }
            });
            pane.addEventListener('dragleave', (e) => {
                if (!pane.contains(e.relatedTarget)) {
                    pane.classList.remove('pane-drop-target');
                }
            });
            pane.addEventListener('drop', async (e) => {
                pane.classList.remove('pane-drop-target');
                const raw = e.dataTransfer.getData('application/x-cloud-item');
                if (raw) {
                    e.preventDefault();
                    e.stopPropagation();
                    await executeSplitTransfer(JSON.parse(raw), paneFolderId, e.ctrlKey || e.altKey || e.metaKey);
                    return;
                }
                if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                    e.preventDefault();
                    e.stopPropagation();
                    const entries = await extractDroppedFileEntries(e.dataTransfer);
                    await uploadFileEntries(entries, paneFolderId);
                }
            });
        });
    }

    function renderListView(container, allItems) {
        const isTrash = state.view === 'trash';
        const arrow = (col) => state.sortBy === col ? `<span class="th-sort-arrow">${state.sortDir === 'asc' ? '▲' : '▼'}</span>` : '';
        const isFiltered = hasActiveFilter();
        const filterOpts = [
            { id: 'all',       label: '✦ Alle Typen' },
            { id: 'folder',    label: '📁 Nur Ordner' },
            { id: 'doc',       label: '📄 Dokumente & PDF' },
            { id: 'image',     label: '🖼️ Bilder' },
            { id: 'video',     label: '🎬 Videos' },
            { id: 'audio',     label: '🎵 Audio' },
            { id: 'zip',       label: '📦 ZIP-Archive' },
            { id: 'encrypted', label: '🔒 Verschlüsselt (Tresor)' },
            { id: 'shared',    label: '🔗 Geteilt / Gruppenordner' },
        ];
        const filterMenuHtml = `
            <div style="padding:0.25rem 0.25rem 0.45rem;border-bottom:1px solid var(--border);margin-bottom:0.25rem">
                <input type="text" id="th-filter-text-inp" class="input small" placeholder="🔍 Dateiname enthält…" value="${esc(state.filterText)}" autocomplete="off" style="padding:0.35rem 0.55rem;font-size:0.82rem">
            </div>
        ` + filterOpts.map((o) => `
            <button type="button" class="dropdown-item ${state.filterType === o.id ? 'active' : ''}" data-filter-opt="${o.id}">
                ${o.label}
            </button>
        `).join('');

        let html = `
            <div class="file-table-wrap">
                <table class="file-table">
                    <thead>
                        <tr>
                            <th style="width:36px"><input type="checkbox" id="select-all-chk"></th>
                            <th>
                                <div class="th-sort-wrap">
                                    <span class="th-sort-btn ${state.sortBy === 'name' ? 'active' : ''}" data-sort-col="name" title="Nach Name sortieren">
                                        Name ${arrow('name')}
                                    </span>
                                    <button type="button" class="th-filter-btn ${isFiltered ? 'active' : ''}" id="btn-th-filter" title="Nach Name oder Typ filtern">
                                        ▾ ${esc(activeFilterSummary())}
                                    </button>
                                    ${isFiltered ? `<button type="button" class="th-filter-btn active" id="btn-th-filter-reset" title="Filter zurücksetzen">✕</button>` : ''}
                                    <div id="th-filter-menu" class="th-filter-menu ${state.filterMenuOpen ? '' : 'hidden'}">${filterMenuHtml}</div>
                                </div>
                            </th>
                            <th style="width:110px">
                                <span class="th-sort-btn ${state.sortBy === 'size' ? 'active' : ''}" data-sort-col="size" title="Nach Dateigröße sortieren">
                                    Größe ${arrow('size')}
                                </span>
                            </th>
                            <th class="hide-mobile" style="width:155px">
                                <span class="th-sort-btn ${state.sortBy === 'date' ? 'active' : ''}" data-sort-col="date" title="Nach Änderungsdatum sortieren">
                                    Geändert ${arrow('date')}
                                </span>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
        `;

        if (allItems.length === 0) {
            html += `
                <tr>
                    <td colspan="4" class="empty-cell">
                        Keine Einträge für den Filter <strong>${esc(activeFilterSummary())}</strong> gefunden.
                        <button type="button" class="btn btn-ghost btn-sm" id="btn-empty-filter-reset" style="margin-left:0.5rem">Filter zurücksetzen</button>
                    </td>
                </tr>
            `;
        }

        for (const item of allItems) {
            const key = `${item.type}:${item.id}`;
            const isSel = state.selected.has(key);
            const isFolder = item.type === 'folder';
            const hasThumb = !isFolder && item.category === 'image' && !item.is_encrypted && !isTrash;

            let badges = '';
            if (item.is_encrypted || item.encrypted_count > 0) {
                badges += `<span class="badge badge-vault" title="AES-256-GCM verschlüsselt">🔒 Tresor</span>`;
            }
            if (item.share_count > 0) {
                badges += `<span class="badge badge-shared" title="Aktiv geteilt">🔗 Geteilt</span>`;
            }
            if (item.group_name) {
                badges += `<span class="badge badge-shared" title="Gruppenordner">👪 ${esc(item.group_name)}</span>`;
            }
            if (item.owner_name) {
                badges += `<span class="badge">👤 ${esc(item.owner_name)}</span>`;
            }

            html += `
                <tr data-key="${key}" data-type="${item.type}" data-id="${item.id}" draggable="${!isTrash && !item.is_group_folder}" class="${isSel ? 'selected' : ''}">
                    <td>
                        <input type="checkbox" class="row-chk" data-key="${key}" ${isSel ? 'checked' : ''}>
                    </td>
                    <td>
                        <div class="item-cell" data-open="${key}">
                            ${hasThumb
                                ? `<img class="item-thumb" loading="lazy" src="api.php?action=thumb&id=${item.id}" alt="">`
                                : `<div class="item-icon">${isFolder ? '📁' : fileIcon(item)}</div>`
                            }
                            <div class="item-name-wrap">
                                <span class="item-name">${esc(item.name)}</span>
                                ${badges}
                            </div>
                        </div>
                    </td>
                    <td class="muted" style="white-space:nowrap">${isFolder ? '—' : formatBytes(item.size_bytes)}</td>
                    <td class="muted small hide-mobile">${esc((item.updated_at || '').replace('T', ' ').slice(0, 16))}</td>
                </tr>
            `;
        }

        html += `</tbody></table></div>`;
        container.innerHTML = html;

        // Bind sort & filter header controls
        $$('[data-sort-col]', container).forEach((el) => {
            el.addEventListener('click', () => {
                const col = el.dataset.sortCol;
                if (state.sortBy === col) {
                    state.sortDir = state.sortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    state.sortBy = col;
                    state.sortDir = col === 'name' ? 'asc' : 'desc';
                }
                renderItems();
            });
        });

        $('#btn-th-filter', container)?.addEventListener('click', (e) => {
            e.stopPropagation();
            state.filterMenuOpen = !state.filterMenuOpen;
            const menu = $('#th-filter-menu', container);
            menu?.classList.toggle('hidden', !state.filterMenuOpen);
            if (state.filterMenuOpen) {
                $('#th-filter-text-inp', container)?.focus();
            }
        });

        $('#th-filter-menu', container)?.addEventListener('click', (e) => {
            e.stopPropagation();
        });

        $('#th-filter-text-inp', container)?.addEventListener('input', (e) => {
            const pos = e.target.selectionStart ?? e.target.value.length;
            state.filterText = e.target.value;
            state.filterMenuOpen = true;
            renderItems();
            const inp = $('#th-filter-text-inp');
            if (inp) {
                inp.focus();
                try { inp.setSelectionRange(pos, pos); } catch (_) {}
            }
        });

        $('#th-filter-text-inp', container)?.addEventListener('keydown', (e) => {
            if (e.key === 'Enter' || e.key === 'Escape') {
                e.preventDefault();
                state.filterMenuOpen = false;
                $('#th-filter-menu', container)?.classList.add('hidden');
            }
        });

        $$('[data-filter-opt]', container).forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                state.filterType = btn.dataset.filterOpt;
                state.filterMenuOpen = false;
                renderItems();
            });
        });

        $('#btn-th-filter-reset', container)?.addEventListener('click', (e) => {
            e.stopPropagation();
            state.filterType = 'all';
            state.filterText = '';
            state.filterMenuOpen = false;
            renderItems();
        });

        $('#btn-empty-filter-reset', container)?.addEventListener('click', () => {
            state.filterType = 'all';
            state.filterText = '';
            state.filterMenuOpen = false;
            renderItems();
        });

        bindItemEvents(container, allItems);
    }

    function renderGridView(container, allItems) {
        const isTrash = state.view === 'trash';
        let html = `<div class="file-grid">`;

        for (const item of allItems) {
            const key = `${item.type}:${item.id}`;
            const isSel = state.selected.has(key);
            const isFolder = item.type === 'folder';
            const hasThumb = !isFolder && item.category === 'image' && !item.is_encrypted && !isTrash;

            html += `
                <div class="grid-card ${isSel ? 'selected' : ''}" data-key="${key}" data-type="${item.type}" data-id="${item.id}" draggable="${!isTrash && !item.is_group_folder}">
                    <div class="grid-card-top">
                        <input type="checkbox" class="row-chk" data-key="${key}" ${isSel ? 'checked' : ''}>
                        <span>${item.is_encrypted || item.encrypted_count > 0 ? '🔒' : (item.group_name ? '👪' : (item.share_count > 0 ? '🔗' : ''))}</span>
                    </div>
                    <div class="grid-preview" data-open="${key}">
                        ${hasThumb
                            ? `<img loading="lazy" src="api.php?action=thumb&id=${item.id}" alt="">`
                            : `<span>${isFolder ? '📁' : fileIcon(item)}</span>`
                        }
                    </div>
                    <div class="grid-title" data-open="${key}" title="${esc(item.name)}">${esc(item.name)}</div>
                    <div class="grid-meta">
                        <span>${isFolder ? 'Ordner' : formatBytes(item.size_bytes)}</span>
                    </div>
                </div>
            `;
        }

        html += `</div>`;
        container.innerHTML = html;
        bindItemEvents(container, allItems);
    }

    // Context Menu (Right-Click on Desktop / Long-Touch on Mobile)
    let contextMenuOpenedAt = 0;

    function closeContextMenu() {
        $('#context-menu')?.classList.add('hidden');
        $('#context-menu-backdrop')?.classList.add('hidden');
    }

    $('#context-menu-backdrop')?.addEventListener('click', (e) => {
        if (Date.now() - contextMenuOpenedAt < 650) {
            e.preventDefault();
            e.stopPropagation();
            return;
        }
        closeContextMenu();
    });
    $('#context-menu-backdrop')?.addEventListener('contextmenu', (e) => {
        e.preventDefault();
        if (Date.now() - contextMenuOpenedAt < 650) {
            return;
        }
        closeContextMenu();
    });
    window.addEventListener('scroll', () => {
        if (Date.now() - contextMenuOpenedAt < 650) return;
        closeContextMenu();
    }, true);

    // Clipboard (Copy / Cut / Paste) Management
    function setClipboard(mode, items) {
        if (!items || items.length === 0) return;
        state.clipboard = {
            mode: mode === 'cut' ? 'cut' : 'copy',
            items: items.map((x) => ({ type: x.type, id: x.id, name: x.name })),
        };
        updateClipboardToolbar();
        const label = state.clipboard.mode === 'cut' ? 'ausgeschnitten' : 'kopiert';
        toast(`${items.length} Element(e) ${label} – im Zielordner „Einfügen“ wählen.`);
    }

    function clearClipboard() {
        state.clipboard = null;
        updateClipboardToolbar();
    }

    function updateClipboardToolbar() {
        const bar = $('#clipboard-toolbar');
        const btn = $('#btn-clipboard-paste');
        if (!bar || !btn) return;
        if (!state.clipboard || !state.clipboard.items?.length || state.view === 'trash') {
            bar.classList.add('hidden');
            return;
        }
        const count = state.clipboard.items.length;
        const modeText = state.clipboard.mode === 'cut' ? 'Ausschneiden' : 'Kopieren';
        btn.textContent = `📥 Hier einfügen (${count} · ${modeText})`;
        bar.classList.remove('hidden');
    }

    async function pasteClipboard(targetFolderId) {
        if (!state.clipboard || !state.clipboard.items?.length) return;
        const isCut = state.clipboard.mode === 'cut';
        const action = isCut ? 'move' : 'copy';
        const items = state.clipboard.items;
        try {
            const res = await apiPost(action, { items, target_folder_id: targetFolderId });
            const count = isCut ? res.moved : res.copied;
            if (isCut) {
                clearClipboard();
            }
            toast(`${count} Element(e) ${isCut ? 'verschoben' : 'eingefügt'}.`);
            loadCurrentView();
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    $('#btn-clipboard-paste')?.addEventListener('click', () => {
        pasteClipboard(state.currentFolderId);
    });
    $('#btn-clipboard-clear')?.addEventListener('click', () => {
        clearClipboard();
        toast('Zwischenablage geleert.');
    });

    function openContextMenu(item, clientX, clientY, splitSide = 'left') {
        const menu = $('#context-menu');
        const backdrop = $('#context-menu-backdrop');
        if (!menu || !backdrop) return;

        contextMenuOpenedAt = Date.now();

        const isTrash = state.view === 'trash';
        const isFolder = item.type === 'folder';
        const icon = isFolder ? '📁' : fileIcon(item);
        const activePaneFolderId = (state.layout === 'split' && splitSide === 'right')
            ? state.rightFolderId
            : state.currentFolderId;

        const key = `${item.type}:${item.id}`;
        const targetItems = state.selected.has(key)
            ? Array.from(state.selected.values())
            : [item];
        const countSuffix = targetItems.length > 1 ? ` (${targetItems.length})` : '';

        let itemsHtml = `<div class="context-menu-header">${icon} ${esc(item.name)}</div>`;

        if (isTrash) {
            itemsHtml += `
                <button type="button" class="dropdown-item" data-ctx-act="restore">♻️ Wiederherstellen</button>
                <button type="button" class="dropdown-item dropdown-item-danger" data-ctx-act="purge">❌ Endgültig löschen</button>
            `;
        } else if (item.is_group_root) {
            itemsHtml += `
                <button type="button" class="dropdown-item" data-ctx-act="open">📂 Gruppenordner öffnen</button>
            `;
        } else {
            itemsHtml += `
                <button type="button" class="dropdown-item" data-ctx-act="open">
                    ${isFolder ? '📂 Ordner öffnen' : '👁️ Öffnen / Vorschau'}
                </button>
            `;

            // Paste options when clipboard has items
            if (state.clipboard && state.clipboard.items?.length > 0 && ['owner', 'write'].includes(state.permission)) {
                const clipCount = state.clipboard.items.length;
                if (isFolder) {
                    itemsHtml += `
                        <button type="button" class="dropdown-item" data-ctx-act="paste_into">
                            📥 In „${esc(item.name)}“ einfügen (${clipCount})
                        </button>
                    `;
                }
                itemsHtml += `
                    <button type="button" class="dropdown-item" data-ctx-act="paste_here">
                        📥 Im aktuellen Ordner einfügen (${clipCount})
                    </button>
                `;
            }

            itemsHtml += `
                <div class="dropdown-divider"></div>
                <button type="button" class="dropdown-item" data-ctx-act="copy">
                    📋 Kopieren${countSuffix}
                </button>
            `;
            if (['owner', 'write'].includes(state.permission) && !item.is_group_folder) {
                itemsHtml += `
                    <button type="button" class="dropdown-item" data-ctx-act="cut">
                        ✂️ Ausschneiden${countSuffix}
                    </button>
                `;
            }
            itemsHtml += `<div class="dropdown-divider"></div>`;

            if (state.permission === 'owner' || item.is_group_folder || state.isGroupTree) {
                itemsHtml += `
                    <button type="button" class="dropdown-item" data-ctx-act="share">
                        ${(item.is_group_folder || state.isGroupTree) ? '🔗 Teilen (Öffentlicher Link)' : '🔗 Teilen (Link / Benutzer)'}
                    </button>
                `;
            }
            itemsHtml += `
                <button type="button" class="dropdown-item" data-ctx-act="download">
                    ${isFolder ? '📦 Als ZIP herunterladen' : '⬇️ Herunterladen'}
                </button>
            `;
            if (!isFolder && item.category === 'zip' && !item.is_encrypted) {
                itemsHtml += `
                    <button type="button" class="dropdown-item" data-ctx-act="unzip">
                        📂 ZIP hier entpacken
                    </button>
                `;
            }
            if (['owner', 'write'].includes(state.permission)) {
                if (isFolder) {
                    itemsHtml += `
                        <button type="button" class="dropdown-item" data-ctx-act="vault">
                            ${item.encrypted_count > 0 ? '🔓 Ordner im Tresor ver-/entschlüsseln' : '🔒 Ordner im Tresor verschlüsseln (AES-256)'}
                        </button>
                    `;
                } else {
                    itemsHtml += `
                        <button type="button" class="dropdown-item" data-ctx-act="vault">
                            ${item.is_encrypted ? '🔓 Tresor entschlüsseln' : '🔒 Im Tresor verschlüsseln (AES-256)'}
                        </button>
                    `;
                }
            }
            if (['owner', 'write'].includes(state.permission) && !item.is_group_folder) {
                itemsHtml += `
                    <div class="dropdown-divider"></div>
                    <button type="button" class="dropdown-item" data-ctx-act="rename">✏️ Umbenennen</button>
                    <button type="button" class="dropdown-item dropdown-item-danger" data-ctx-act="trash">🗑️ In den Papierkorb</button>
                `;
            }
        }

        menu.innerHTML = itemsHtml;
        backdrop.classList.remove('hidden');
        menu.classList.remove('hidden');

        // Position near cursor on desktop (CSS media query overrides to bottom sheet on mobile <= 768px)
        const rect = menu.getBoundingClientRect();
        const x = Math.min(clientX, Math.max(12, window.innerWidth - rect.width - 12));
        const y = Math.min(clientY, Math.max(12, window.innerHeight - rect.height - 12));
        menu.style.left = `${x}px`;
        menu.style.top = `${y}px`;

        $$('[data-ctx-act]', menu).forEach((btn) => {
            btn.addEventListener('click', () => {
                const act = btn.dataset.ctxAct;
                closeContextMenu();
                if (act === 'open') {
                    if (isFolder) {
                        if (state.layout === 'split' && splitSide === 'right') {
                            state.rightFolderId = item.id;
                        } else {
                            state.currentFolderId = item.id;
                        }
                        state.search = '';
                        $('#search-input').value = '';
                        loadCurrentView();
                    } else {
                        openFileModal(item);
                    }
                } else if (act === 'copy') {
                    setClipboard('copy', targetItems);
                } else if (act === 'cut') {
                    setClipboard('cut', targetItems);
                } else if (act === 'paste_into') {
                    pasteClipboard(item.id);
                } else if (act === 'paste_here') {
                    pasteClipboard(activePaneFolderId);
                } else {
                    handleItemAction(act, item);
                }
            });
        });
    }

    // Background Context Menu (Right-click or Long-touch on empty folder space)
    function openBackgroundContextMenu(clientX, clientY, targetFolderId) {
        if (state.view !== 'files' && state.view !== 'shared') return;
        if (!['owner', 'write'].includes(state.permission)) return;

        const menu = $('#context-menu');
        const backdrop = $('#context-menu-backdrop');
        if (!menu || !backdrop) return;

        contextMenuOpenedAt = Date.now();
        const hasClip = state.clipboard && state.clipboard.items?.length > 0;
        const clipCount = hasClip ? state.clipboard.items.length : 0;

        let html = `<div class="context-menu-header">📂 Aktueller Ordner</div>`;
        if (hasClip) {
            html += `
                <button type="button" class="dropdown-item" data-bg-act="paste">
                    📥 Hier einfügen (${clipCount} Element${clipCount > 1 ? 'e' : ''})
                </button>
                <div class="dropdown-divider"></div>
            `;
        }
        html += `
            <button type="button" class="dropdown-item" data-bg-act="new_folder">📁 Neuer Ordner</button>
            <button type="button" class="dropdown-item" data-bg-act="new_text">📝 Neue Textdatei</button>
            <button type="button" class="dropdown-item" data-bg-act="upload">⬆️ Dateien hochladen</button>
        `;

        menu.innerHTML = html;
        backdrop.classList.remove('hidden');
        menu.classList.remove('hidden');

        const rect = menu.getBoundingClientRect();
        menu.style.left = `${Math.min(clientX, Math.max(12, window.innerWidth - rect.width - 12))}px`;
        menu.style.top = `${Math.min(clientY, Math.max(12, window.innerHeight - rect.height - 12))}px`;

        $$('[data-bg-act]', menu).forEach((btn) => {
            btn.addEventListener('click', () => {
                const act = btn.dataset.bgAct;
                closeContextMenu();
                if (act === 'paste') {
                    pasteClipboard(targetFolderId);
                } else if (act === 'new_folder') {
                    $('#btn-new-folder')?.click();
                } else if (act === 'new_text') {
                    $('#btn-new-text')?.click();
                } else if (act === 'upload') {
                    $('#file-input-multi')?.click();
                }
            });
        });
    }

    // Bind background right-click & long-touch on #file-viewport
    (() => {
        const vp = $('#file-viewport');
        if (!vp) return;
        vp.addEventListener('contextmenu', (e) => {
            if (e.target.closest('[data-key]')) return;
            e.preventDefault();
            const pane = e.target.closest('.split-pane');
            const folderId = pane
                ? (pane.dataset.paneFolder === '' ? null : Number(pane.dataset.paneFolder))
                : state.currentFolderId;
            openBackgroundContextMenu(e.clientX, e.clientY, folderId);
        });

        let bgTimer = null;
        let bgX = 0;
        let bgY = 0;
        vp.addEventListener('touchstart', (e) => {
            if (e.touches.length !== 1 || e.target.closest('[data-key]') || e.target.closest('button, a, input')) return;
            bgX = e.touches[0].clientX;
            bgY = e.touches[0].clientY;
            const pane = e.target.closest('.split-pane');
            const folderId = pane
                ? (pane.dataset.paneFolder === '' ? null : Number(pane.dataset.paneFolder))
                : state.currentFolderId;
            bgTimer = setTimeout(() => {
                if (navigator.vibrate) {
                    try { navigator.vibrate(15); } catch (_) {}
                }
                openBackgroundContextMenu(bgX, bgY, folderId);
            }, 450);
        }, { passive: true });
        vp.addEventListener('touchmove', (e) => {
            if (!bgTimer || e.touches.length !== 1) return;
            if (Math.abs(e.touches[0].clientX - bgX) > 12 || Math.abs(e.touches[0].clientY - bgY) > 12) {
                clearTimeout(bgTimer);
                bgTimer = null;
            }
        }, { passive: true });
        vp.addEventListener('touchend', () => { if (bgTimer) { clearTimeout(bgTimer); bgTimer = null; } }, { passive: true });
        vp.addEventListener('touchcancel', () => { if (bgTimer) { clearTimeout(bgTimer); bgTimer = null; } }, { passive: true });
    })();

    function getOrCreateTouchGhost() {
        let g = $('#touch-drag-ghost');
        if (!g) {
            g = document.createElement('div');
            g.id = 'touch-drag-ghost';
            g.className = 'touch-drag-ghost hidden';
            document.body.appendChild(g);
        }
        return g;
    }

    function bindContextMenuEvents(el, item, splitSide = 'left') {
        // 1. Desktop Right-Click
        el.addEventListener('contextmenu', (e) => {
            if (el.dataset.touchActive === '1') {
                e.preventDefault();
                return;
            }
            e.preventDefault();
            e.stopPropagation();
            openContextMenu(item, e.clientX, e.clientY, splitSide);
        });

        // 2. Mobile Long-Touch (~430ms):
        //    - Hold & lift finger without moving -> Opens Context Menu
        //    - Hold & drag finger -> Moves item(s) onto hovered folder or breadcrumb!
        let touchTimer = null;
        let startX = 0;
        let startY = 0;
        let lastX = 0;
        let lastY = 0;
        let armed = false;
        let dragging = false;
        let hoveredTargetEl = null;
        let hoveredFolderId = undefined; // undefined = no target, null = root folder, number = subfolder ID
        let hoveredFolderName = '';

        const clearHoveredTarget = () => {
            if (hoveredTargetEl) {
                hoveredTargetEl.classList.remove('drag-over');
                hoveredTargetEl = null;
            }
            hoveredFolderId = undefined;
            hoveredFolderName = '';
        };

        el.addEventListener('touchstart', (e) => {
            if (e.touches.length !== 1 || e.target.closest('input, button, a')) return;
            startX = e.touches[0].clientX;
            startY = e.touches[0].clientY;
            lastX = startX;
            lastY = startY;
            armed = false;
            dragging = false;
            el.dataset.touchActive = '1';

            touchTimer = setTimeout(() => {
                touchTimer = null;
                armed = true;
                el.dataset.longPressed = '1';
                el.classList.add('touch-armed');
                if (navigator.vibrate) {
                    try { navigator.vibrate(18); } catch (_) {}
                }
            }, 430);
        }, { passive: true });

        el.addEventListener('touchmove', (e) => {
            if (e.touches.length !== 1) return;
            const tx = e.touches[0].clientX;
            const ty = e.touches[0].clientY;
            lastX = tx;
            lastY = ty;
            const dx = Math.abs(tx - startX);
            const dy = Math.abs(ty - startY);

            if (!armed) {
                if (dx > 12 || dy > 12) {
                    if (touchTimer) {
                        clearTimeout(touchTimer);
                        touchTimer = null;
                    }
                }
                return;
            }

            // Armed! If the user moves their finger after the 430ms vibration, switch to Touch-Drag mode
            if (!dragging && (dx > 10 || dy > 10)) {
                if (state.view === 'trash' || item.is_group_folder) return;
                dragging = true;
                closeContextMenu();
            }

            if (dragging) {
                if (e.cancelable) e.preventDefault();

                const key = `${item.type}:${item.id}`;
                const dragCount = state.selected.has(key) ? state.selected.size : 1;
                const baseLabel = dragCount > 1
                    ? `📦 ${dragCount} Elemente`
                    : `${item.type === 'folder' ? '📁' : '📄'} ${item.name}`;

                const ghost = getOrCreateTouchGhost();
                ghost.classList.remove('hidden');
                ghost.style.left = `${tx}px`;
                ghost.style.top = `${ty}px`;

                // Auto-scroll #file-viewport near top/bottom edges
                const vp = $('#file-viewport');
                if (vp) {
                    const vpRect = vp.getBoundingClientRect();
                    if (ty < vpRect.top + 55) {
                        vp.scrollTop -= 10;
                    } else if (ty > vpRect.bottom - 55) {
                        vp.scrollTop += 10;
                    }
                }

                // Detect drop target under finger
                const underEl = document.elementFromPoint(tx, ty);
                const folderRow = underEl?.closest('[data-type="folder"]');
                const crumbEl = underEl?.closest('.crumb[data-crumb-id], [data-split-crumb]');

                clearHoveredTarget();

                if (folderRow && Number(folderRow.dataset.id) !== item.id) {
                    hoveredTargetEl = folderRow;
                    hoveredFolderId = Number(folderRow.dataset.id);
                    hoveredFolderName = folderRow.querySelector('.item-name, .grid-title')?.textContent || 'Ordner';
                    hoveredTargetEl.classList.add('drag-over');
                    ghost.textContent = `📥 In „${hoveredFolderName}“`;
                } else if (crumbEl) {
                    hoveredTargetEl = crumbEl;
                    const rawId = crumbEl.dataset.crumbId ?? crumbEl.dataset.folderId ?? '';
                    hoveredFolderId = rawId === '' ? null : Number(rawId);
                    hoveredFolderName = crumbEl.textContent || 'Ordner';
                    hoveredTargetEl.classList.add('drag-over');
                    ghost.textContent = `📥 Nach „${hoveredFolderName}“`;
                } else {
                    ghost.textContent = baseLabel;
                }
            }
        }, { passive: false });

        const finishTouch = async (cancelled = false) => {
            if (touchTimer) {
                clearTimeout(touchTimer);
                touchTimer = null;
            }
            el.classList.remove('touch-armed');
            setTimeout(() => { el.dataset.touchActive = '0'; }, 300);

            const ghost = $('#touch-drag-ghost');
            if (ghost) ghost.classList.add('hidden');

            if (cancelled) {
                clearHoveredTarget();
                armed = false;
                dragging = false;
                return;
            }

            if (armed && !dragging) {
                // User held finger for 430ms and lifted without dragging -> Open Context Menu!
                armed = false;
                openContextMenu(item, lastX, lastY, splitSide);
                return;
            }

            if (dragging) {
                const targetId = hoveredFolderId;
                const targetName = hoveredFolderName;
                clearHoveredTarget();
                armed = false;
                dragging = false;

                if (targetId !== undefined) {
                    const key = `${item.type}:${item.id}`;
                    const dragItems = state.selected.has(key)
                        ? Array.from(state.selected.values()).map((x) => ({ type: x.type, id: x.id, name: x.name }))
                        : [{ type: item.type, id: item.id, name: item.name }];

                    try {
                        const res = await apiPost('move', { items: dragItems, target_folder_id: targetId });
                        if (res.moved > 0) {
                            toast(`In „${targetName}“ verschoben.`);
                            loadCurrentView();
                        }
                    } catch (err) {
                        toast(err.message, 'error');
                    }
                }
            }
        };

        el.addEventListener('touchend', () => finishTouch(false), { passive: true });
        el.addEventListener('touchcancel', () => finishTouch(true), { passive: true });
    }

    function bindItemEvents(container, allItems) {
        const itemMap = new Map(allItems.map((it) => [`${it.type}:${it.id}`, it]));

        // Select All checkbox
        const selectAll = $('#select-all-chk', container);
        if (selectAll) {
            selectAll.checked = allItems.length > 0 && state.selected.size === allItems.length;
            selectAll.addEventListener('change', () => {
                if (selectAll.checked) {
                    allItems.forEach((it) => state.selected.set(`${it.type}:${it.id}`, it));
                } else {
                    state.selected.clear();
                }
                renderItems();
                updateBulkToolbar();
            });
        }

        // Row Checkboxes
        $$('.row-chk', container).forEach((chk) => {
            chk.addEventListener('click', (e) => e.stopPropagation());
            chk.addEventListener('change', () => {
                const key = chk.dataset.key;
                const it = itemMap.get(key);
                if (!it) return;
                if (chk.checked) {
                    state.selected.set(key, it);
                } else {
                    state.selected.delete(key);
                }
                const row = chk.closest('[data-key]');
                if (row) row.classList.toggle('selected', chk.checked);
                updateBulkToolbar();
            });
        });

        // Click to Open Folder or File Preview
        $$('[data-open]', container).forEach((el) => {
            el.addEventListener('click', (e) => {
                const row = el.closest('[data-key]');
                if (row?.dataset.longPressed === '1') {
                    row.dataset.longPressed = '0';
                    e.preventDefault();
                    return;
                }
                const key = el.dataset.open;
                const item = itemMap.get(key);
                if (!item) return;
                if (state.view === 'trash') {
                    openContextMenu(item, e.clientX || 120, e.clientY || 120);
                    return;
                }
                if (item.type === 'folder') {
                    state.currentFolderId = item.id;
                    state.search = '';
                    $('#search-input').value = '';
                    loadCurrentView();
                } else {
                    openFileModal(item);
                }
            });
        });

        // Context Menu & Internal Drag & Drop (move items into folders)
        $$('[data-key]', container).forEach((row) => {
            const key = row.dataset.key;
            const item = itemMap.get(key);
            if (!item) return;

            bindContextMenuEvents(row, item, 'left');

            row.addEventListener('dragstart', (e) => {
                let dragList = [{ type: item.type, id: item.id, name: item.name }];
                if (state.selected.has(key)) {
                    dragList = Array.from(state.selected.values()).map((x) => ({ type: x.type, id: x.id, name: x.name }));
                }
                e.dataTransfer.setData('application/x-cloud-item', JSON.stringify(dragList));
                e.dataTransfer.effectAllowed = 'move';
            });

            if (item.type === 'folder' && state.view !== 'trash') {
                row.addEventListener('dragover', (e) => {
                    e.preventDefault();
                    row.classList.add('drag-over');
                    const lbl = $('#drop-target-label');
                    if (lbl) lbl.textContent = `In Ordner „${item.name}“ ablegen`;
                });
                row.addEventListener('dragleave', () => {
                    row.classList.remove('drag-over');
                    const lbl = $('#drop-target-label');
                    if (lbl) lbl.textContent = 'Dateien hier ablegen (oder direkt auf einen Ordner ziehen)';
                });
                row.addEventListener('drop', async (e) => {
                    row.classList.remove('drag-over');
                    const internalData = e.dataTransfer.getData('application/x-cloud-item');
                    if (internalData) {
                        e.preventDefault();
                        e.stopPropagation();
                        try {
                            const items = JSON.parse(internalData);
                            const res = await apiPost('move', { items, target_folder_id: item.id });
                            if (res.moved > 0) {
                                toast(`In "${item.name}" verschoben.`);
                                loadCurrentView();
                            }
                        } catch (err) {
                            toast(err.message, 'error');
                        }
                        return;
                    }

                    // External files dropped directly onto a subfolder row!
                    if (e.dataTransfer.files && e.dataTransfer.files.length > 0) {
                        e.preventDefault();
                        e.stopPropagation();
                        const entries = await extractDroppedFileEntries(e.dataTransfer);
                        await uploadFileEntries(entries, item.id);
                    }
                });
            }
        });
    }

    function updateBulkToolbar() {
        updateClipboardToolbar();
        const count = state.selected.size;
        const bar = $('#bulk-toolbar');
        if (count === 0) {
            bar.classList.add('hidden');
            return;
        }
        bar.classList.remove('hidden');
        $('#bulk-count').textContent = `${count} ausgewählt`;

        const isTrash = state.view === 'trash';
        $('#btn-bulk-copy')?.classList.toggle('hidden', isTrash);
        $('#btn-bulk-cut')?.classList.toggle('hidden', isTrash);
        $('#btn-bulk-download').classList.toggle('hidden', isTrash);
        $('#btn-bulk-trash').classList.toggle('hidden', isTrash);
        $('#btn-bulk-restore').classList.toggle('hidden', !isTrash);
        $('#btn-bulk-purge').classList.toggle('hidden', !isTrash);
    }

    // Individual Row Actions
    async function handleItemAction(act, item) {
        switch (act) {
            case 'download':
                if (item.type === 'folder') {
                    triggerZipDownload([item]);
                } else if (item.is_encrypted) {
                    openVaultModal(item);
                } else {
                    window.location.href = `api.php?action=download&id=${item.id}`;
                }
                break;

            case 'rename':
                openRenameModal(item);
                break;

            case 'trash':
                try {
                    await apiPost('trash', { items: [{ type: item.type, id: item.id, name: item.name }] });
                    toast(`"${item.name}" in den Papierkorb verschoben.`);
                    loadCurrentView();
                } catch (err) {
                    toast(err.message, 'error');
                }
                break;

            case 'restore':
                try {
                    await apiPost('restore', { items: [{ type: item.type, id: item.id }] });
                    toast(`"${item.name}" wiederhergestellt.`);
                    loadCurrentView();
                } catch (err) {
                    toast(err.message, 'error');
                }
                break;

            case 'purge':
                if (!confirm(`"${item.name}" wirklich unwiderruflich löschen?`)) return;
                try {
                    await apiPost('delete_permanent', { items: [{ type: item.type, id: item.id }] });
                    toast(`"${item.name}" endgültig gelöscht.`);
                    loadCurrentView();
                } catch (err) {
                    toast(err.message, 'error');
                }
                break;

            case 'vault':
                openVaultModal(item);
                break;

            case 'unzip':
                openZipModal(item);
                break;

            case 'share':
                openShareModal(item);
                break;
        }
    }

    // Bulk Toolbar Actions
    $('#btn-bulk-copy')?.addEventListener('click', () => {
        setClipboard('copy', Array.from(state.selected.values()));
    });

    $('#btn-bulk-cut')?.addEventListener('click', () => {
        setClipboard('cut', Array.from(state.selected.values()));
    });

    // Keyboard shortcuts: Ctrl+C (Copy), Ctrl+X (Cut), Ctrl+V (Paste)
    document.addEventListener('keydown', (e) => {
        if (!state.user || !$('#modal-backdrop').classList.contains('hidden')) return;
        const tag = (e.target?.tagName || '').toLowerCase();
        if (tag === 'input' || tag === 'textarea' || tag === 'select' || e.target?.isContentEditable) return;

        if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'c' && state.selected.size > 0 && state.view !== 'trash') {
            e.preventDefault();
            setClipboard('copy', Array.from(state.selected.values()));
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'x' && state.selected.size > 0 && state.view !== 'trash') {
            e.preventDefault();
            setClipboard('cut', Array.from(state.selected.values()));
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'v' && state.clipboard?.items?.length > 0 && state.view === 'files') {
            e.preventDefault();
            pasteClipboard(state.currentFolderId);
        } else if ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'z' && state.undoStack.length > 0) {
            e.preventDefault();
            executeUndo();
        }
    });

    $('#btn-bulk-download')?.addEventListener('click', () => {
        const items = Array.from(state.selected.values());
        if (items.length === 1 && items[0].type === 'file' && !items[0].is_encrypted) {
            window.location.href = `api.php?action=download&id=${items[0].id}`;
        } else {
            triggerZipDownload(items);
        }
    });

    function triggerZipDownload(items) {
        const minimal = items.map((x) => ({ type: x.type, id: x.id, name: x.name }));
        const url = `api.php?action=download_zip&items=${encodeURIComponent(JSON.stringify(minimal))}`;
        window.location.href = url;
    }

    $('#btn-bulk-trash')?.addEventListener('click', async () => {
        const items = Array.from(state.selected.values()).map((x) => ({ type: x.type, id: x.id, name: x.name }));
        try {
            await apiPost('trash', { items });
            toast(`${items.length} Element(e) in den Papierkorb verschoben.`);
            loadCurrentView();
        } catch (err) {
            toast(err.message, 'error');
        }
    });

    $('#btn-bulk-restore')?.addEventListener('click', async () => {
        const items = Array.from(state.selected.values()).map((x) => ({ type: x.type, id: x.id }));
        try {
            await apiPost('restore', { items });
            toast(`${items.length} Element(e) wiederhergestellt.`);
            loadCurrentView();
        } catch (err) {
            toast(err.message, 'error');
        }
    });

    $('#btn-bulk-purge')?.addEventListener('click', async () => {
        const items = Array.from(state.selected.values()).map((x) => ({ type: x.type, id: x.id }));
        if (!confirm(`${items.length} Element(e) wirklich endgültig löschen?`)) return;
        try {
            await apiPost('delete_permanent', { items });
            toast(`${items.length} Element(e) endgültig gelöscht.`);
            loadCurrentView();
        } catch (err) {
            toast(err.message, 'error');
        }
    });

    $('#btn-empty-trash')?.addEventListener('click', async () => {
        if (!confirm('Gesamten Papierkorb unwiderruflich leeren?')) return;
        try {
            await apiPost('empty_trash');
            toast('Papierkorb geleert.');
            loadCurrentView();
        } catch (err) {
            toast(err.message, 'error');
        }
    });

    $('#btn-bulk-move')?.addEventListener('click', async () => {
        const items = Array.from(state.selected.values()).map((x) => ({ type: x.type, id: x.id }));
        try {
            const res = await apiGet({ action: 'folder_tree' });
            const folders = res.folders || [];
            let opts = `<option value="">📁 Meine Dateien (Hauptordner)</option>`;
            for (const f of folders) {
                opts += `<option value="${f.id}">📁 ${esc(f.name)}</option>`;
            }
            openModal('Auswahl verschieben', `
                <form id="move-modal-form">
                    <div class="form-group">
                        <label>Zielordner auswählen</label>
                        <select name="target_folder_id" class="input">${opts}</select>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">Hierhin verschieben</button>
                </form>
            `);
            $('#move-modal-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                const targetId = e.target.target_folder_id.value;
                try {
                    await apiPost('move', { items, target_folder_id: targetId === '' ? null : Number(targetId) });
                    closeModal();
                    toast('Elemente verschoben.');
                    loadCurrentView();
                } catch (err) {
                    toast(err.message, 'error');
                }
            });
        } catch (err) {
            toast(err.message, 'error');
        }
    });

    // Create Folder Modal
    $('#btn-new-folder')?.addEventListener('click', () => {
        openModal('Neuer Ordner', `
            <form id="new-folder-form">
                <div class="form-group">
                    <label>Ordnername</label>
                    <input type="text" name="name" class="input" placeholder="z. B. Rechnungen 2026" required autofocus>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Ordner erstellen</button>
            </form>
        `);
        $('#new-folder-form').querySelector('input').focus();
        $('#new-folder-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            const name = e.target.name.value.trim();
            try {
                await apiPost('create_folder', { parent_id: state.currentFolderId, name });
                closeModal();
                toast(`Ordner "${name}" erstellt.`);
                loadCurrentView();
            } catch (err) {
                toast(err.message, 'error');
            }
        });
    });

    // Rename Modal
    function openRenameModal(item) {
        openModal('Umbenennen', `
            <form id="rename-form">
                <div class="form-group">
                    <label>Neuer Name</label>
                    <input type="text" name="name" class="input" value="${esc(item.name)}" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Speichern</button>
            </form>
        `);
        const inp = $('#rename-form').querySelector('input');
        inp.focus();
        inp.select();
        $('#rename-form').addEventListener('submit', async (e) => {
            e.preventDefault();
            try {
                await apiPost('rename', { type: item.type, id: item.id, name: inp.value.trim(), old_name: item.name });
                closeModal();
                toast('Umbenannt.');
                loadCurrentView();
            } catch (err) {
                toast(err.message, 'error');
            }
        });
    }

    // Create / Edit Text File Modal
    $('#btn-new-text')?.addEventListener('click', () => {
        openTextEditorModal(null, 'notiz.md', '');
    });

    function openTextEditorModal(fileId, fileName, content, readOnly = false) {
        openModal(fileId ? `Textdatei: ${fileName}` : 'Neue Textdatei erstellen', `
            <form id="text-editor-form">
                ${!fileId ? `
                <div class="form-group">
                    <label>Dateiname</label>
                    <input type="text" name="name" class="input" value="${esc(fileName)}" required>
                </div>` : ''}
                <div class="form-group">
                    <textarea name="content" class="input editor-textarea" ${readOnly ? 'readonly' : ''}>${esc(content)}</textarea>
                </div>
                <div style="display:flex;justify-content:space-between;gap:0.5rem">
                    ${fileId ? `<a class="btn btn-ghost" href="api.php?action=download&id=${fileId}">⬇️ Herunterladen</a>` : '<span></span>'}
                    ${!readOnly ? `<button type="submit" class="btn btn-primary">💾 Speichern</button>` : ''}
                </div>
            </form>
        `, true);

        $('#text-editor-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const name = fileId ? fileName : e.target.name.value.trim();
            const text = e.target.content.value;
            try {
                await apiPost('save_text', {
                    folder_id: state.currentFolderId,
                    file_id: fileId,
                    name,
                    content: text,
                });
                closeModal();
                toast('Datei gespeichert.');
                loadCurrentView();
            } catch (err) {
                toast(err.message, 'error');
            }
        });
    }

    // File Preview Modal (Images, Video, Audio, PDF, Text, ZIP, Vault)
    async function openFileModal(file) {
        if (file.is_encrypted) {
            openVaultModal(file);
            return;
        }

        if (file.category === 'zip') {
            openZipModal(file);
            return;
        }

        if (file.category === 'text') {
            try {
                const res = await apiGet({ action: 'read_text', id: file.id });
                const readOnly = !['owner', 'write'].includes(res.permission);
                openTextEditorModal(file.id, res.name, res.content, readOnly);
            } catch (err) {
                toast(err.message, 'error');
            }
            return;
        }

        const streamUrl = `api.php?action=download&id=${file.id}&inline=1`;
        const dlUrl = `api.php?action=download&id=${file.id}`;
        let previewHtml = '';

        if (file.category === 'image') {
            previewHtml = `<div class="preview-media-wrap"><img src="${streamUrl}" alt="${esc(file.name)}"></div>`;
        } else if (file.category === 'video') {
            previewHtml = `<div class="preview-media-wrap"><video controls autoplay src="${streamUrl}"></video></div>`;
        } else if (file.category === 'audio') {
            previewHtml = `<div class="preview-media-wrap" style="padding:2rem"><audio controls autoplay src="${streamUrl}" style="width:100%"></audio></div>`;
        } else if (file.category === 'pdf') {
            previewHtml = `<iframe class="preview-pdf-frame" src="${streamUrl}"></iframe>`;
        } else {
            previewHtml = `
                <div class="empty-state" style="margin-bottom:1rem">
                    <div class="empty-state-icon">📄</div>
                    <p class="muted">${esc(file.mime_type)} · ${formatBytes(file.size_bytes)}</p>
                </div>
            `;
        }

        openModal(file.name, `
            ${previewHtml}
            <div style="display:flex;justify-content:space-between;align-items:center;margin-top:0.75rem">
                <span class="muted small">${formatBytes(file.size_bytes)} · ${esc(file.mime_type)}</span>
                <div style="display:flex;gap:0.5rem">
                    <button type="button" class="btn btn-ghost" id="modal-btn-vault">🔒 Verschlüsseln</button>
                    <a class="btn btn-primary" href="${dlUrl}">⬇️ Herunterladen</a>
                </div>
            </div>
        `, file.category === 'pdf' || file.category === 'image' || file.category === 'video');

        $('#modal-btn-vault')?.addEventListener('click', () => {
            openVaultModal(file);
        });
    }

    // ZIP Inspector & Extractor Modal
    async function openZipModal(file) {
        try {
            const res = await apiGet({ action: 'zip_inspect', id: file.id });
            const rows = (res.entries || []).slice(0, 200).map((e) => `
                <tr>
                    <td>${e.is_dir ? '📁' : '📄'} ${esc(e.name)}</td>
                    <td class="muted" style="text-align:right">${e.is_dir ? '—' : formatBytes(e.size)}</td>
                </tr>
            `).join('');

            openModal(`ZIP-Archiv: ${file.name}`, `
                <p class="muted small" style="margin-top:0">
                    ${res.total_entries} Einträge · Entpackte Gesamtgröße: <strong>${formatBytes(res.total_uncompressed)}</strong>
                </p>
                <div class="file-table-wrap" style="max-height:320px;overflow-y:auto;margin-bottom:1rem">
                    <table class="file-table">
                        <thead><tr><th>Pfad im Archiv</th><th style="text-align:right">Größe</th></tr></thead>
                        <tbody>${rows || '<tr><td colspan="2" class="empty-cell">Archiv ist leer.</td></tr>'}</tbody>
                    </table>
                </div>
                <div style="display:flex;justify-content:space-between;gap:0.5rem">
                    <a class="btn btn-ghost" href="api.php?action=download&id=${file.id}">⬇️ ZIP herunterladen</a>
                    <button type="button" class="btn btn-primary" id="btn-confirm-unzip">📂 Jetzt im aktuellen Ordner entpacken</button>
                </div>
            `, true);

            $('#btn-confirm-unzip')?.addEventListener('click', async (e) => {
                e.target.disabled = true;
                e.target.textContent = 'Entpacke…';
                try {
                    const ext = await apiPost('zip_extract', { file_id: file.id, target_folder_id: state.currentFolderId });
                    closeModal();
                    toast(`${ext.extracted_files} Datei(en) erfolgreich entpackt.`);
                    loadCurrentView();
                } catch (err) {
                    toast(err.message, 'error');
                    e.target.disabled = false;
                    e.target.textContent = '📂 Jetzt im aktuellen Ordner entpacken';
                }
            });
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    // AES-256-GCM Zero-Knowledge Vault Modal (for single File or entire Folder)
    function openVaultModal(item) {
        if (item.type === 'folder') {
            const defaultDec = (item.encrypted_count || 0) > 0;
            openModal(`🔒 Ordner-Tresor (AES-256): ${item.name}`, `
                <div class="tabs">
                    <button type="button" class="tab-btn ${!defaultDec ? 'active' : ''}" data-fv-tab="fv-enc">🔒 Ordner verschlüsseln</button>
                    <button type="button" class="tab-btn ${defaultDec ? 'active' : ''}" data-fv-tab="fv-dec">🔓 Ordner entschlüsseln</button>
                </div>

                <div id="fv-enc" class="${!defaultDec ? '' : 'hidden'}">
                    <p class="muted small">
                        Alle noch unverschlüsselten Dateien in <strong>${esc(item.name)}</strong> (inkl. aller Unterordner) werden mit <strong>AES-256-GCM</strong> verschlüsselt. Das Passwort wird niemals gespeichert.
                    </p>
                    <form id="folder-vault-enc-form">
                        <div class="form-group">
                            <label>Tresor-Passwort festlegen (mind. 4 Zeichen)</label>
                            <input type="password" name="password" class="input" placeholder="Tresor-Passwort…" required minlength="4">
                        </div>
                        <div class="form-group">
                            <label>Passwort wiederholen</label>
                            <input type="password" name="password2" class="input" placeholder="Passwort bestätigen…" required minlength="4">
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">🔒 Alle Dateien im Ordner verschlüsseln</button>
                    </form>
                </div>

                <div id="fv-dec" class="${defaultDec ? '' : 'hidden'}">
                    <p class="muted small">
                        Entschlüsselt alle verschlüsselten Dateien in <strong>${esc(item.name)}</strong> (inkl. Unterordner), die mit diesem Tresor-Passwort geschützt wurden.
                    </p>
                    <form id="folder-vault-dec-form">
                        <div class="form-group">
                            <label>Tresor-Passwort eingeben</label>
                            <input type="password" name="password" class="input" placeholder="Tresor-Passwort…" required>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">🔓 Alle Dateien im Ordner entschlüsseln</button>
                    </form>
                </div>
            `);

            const body = $('#modal-body');
            $$('[data-fv-tab]', body).forEach((btn) => {
                btn.addEventListener('click', () => {
                    $$('[data-fv-tab]', body).forEach((b) => b.classList.remove('active'));
                    btn.classList.add('active');
                    $('#fv-enc', body).classList.toggle('hidden', btn.dataset.fvTab !== 'fv-enc');
                    $('#fv-dec', body).classList.toggle('hidden', btn.dataset.fvTab !== 'fv-dec');
                });
            });

            $('#folder-vault-enc-form', body)?.addEventListener('submit', async (e) => {
                e.preventDefault();
                const pw1 = e.target.password.value;
                const pw2 = e.target.password2.value;
                if (pw1 !== pw2) {
                    toast('Die Passwörter stimmen nicht überein.', 'error');
                    return;
                }
                const btn = e.target.querySelector('button[type="submit"]');
                btn.disabled = true;
                btn.textContent = 'Verschlüssele Ordner…';
                try {
                    const res = await apiPost('vault_encrypt_folder', { folder_id: item.id, password: pw1 });
                    closeModal();
                    toast(`${res.encrypted_count} Datei(en) im Ordner verschlüsselt.`);
                    loadCurrentView();
                } catch (err) {
                    toast(err.message, 'error');
                    btn.disabled = false;
                    btn.textContent = '🔒 Alle Dateien im Ordner verschlüsseln';
                }
            });

            $('#folder-vault-dec-form', body)?.addEventListener('submit', async (e) => {
                e.preventDefault();
                const pw = e.target.password.value;
                const btn = e.target.querySelector('button[type="submit"]');
                btn.disabled = true;
                btn.textContent = 'Entschlüssele Ordner…';
                try {
                    const res = await apiPost('vault_decrypt_folder', { folder_id: item.id, password: pw });
                    closeModal();
                    toast(`${res.decrypted_count} Datei(en) im Ordner entschlüsselt.`);
                    loadCurrentView();
                } catch (err) {
                    toast(err.message, 'error');
                    btn.disabled = false;
                    btn.textContent = '🔓 Alle Dateien im Ordner entschlüsseln';
                }
            });
            return;
        }

        const file = item;
        if (!file.is_encrypted) {
            openModal(`🔒 Datei verschlüsseln: ${file.name}`, `
                <p class="muted small">
                    Die Datei wird auf dem Server mit <strong>AES-256-GCM</strong> (PBKDF2-HMAC-SHA256, 100.000 Iterationen) verschlüsselt.
                    Das Passwort wird <strong>niemals</strong> gespeichert (Zero-Knowledge).
                </p>
                <form id="vault-encrypt-form">
                    <div class="form-group">
                        <label>Tresor-Passwort festlegen (mind. 4 Zeichen)</label>
                        <input type="password" name="password" class="input" placeholder="Tresor-Passwort…" required minlength="4">
                    </div>
                    <div class="form-group">
                        <label>Passwort wiederholen</label>
                        <input type="password" name="password2" class="input" placeholder="Passwort bestätigen…" required minlength="4">
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">🔒 Jetzt mit AES-256 verschlüsseln</button>
                </form>
            `);
            $('#vault-encrypt-form').querySelector('input').focus();
            $('#vault-encrypt-form').addEventListener('submit', async (e) => {
                e.preventDefault();
                const pw1 = e.target.password.value;
                const pw2 = e.target.password2.value;
                if (pw1 !== pw2) {
                    toast('Die Passwörter stimmen nicht überein.', 'error');
                    return;
                }
                try {
                    await apiPost('vault_encrypt', { file_id: file.id, password: pw1 });
                    closeModal();
                    toast(`"${file.name}" wurde im Tresor verschlüsselt.`);
                    loadCurrentView();
                } catch (err) {
                    toast(err.message, 'error');
                }
            });
        } else {
            openModal(`🔓 Verschlüsselte Tresor-Datei: ${file.name}`, `
                <p class="muted small">
                    Diese Datei ist mit <strong>AES-256-GCM</strong> geschützt. Geben Sie das Tresor-Passwort ein, um sie einmalig entschlüsselt herunterzuladen oder dauerhaft im Cloud-Speicher zu entschlüsseln.
                </p>
                <form id="vault-decrypt-form" method="post" action="api.php?action=download&id=${file.id}" target="_blank">
                    <input type="hidden" name="csrf_token" value="${esc(state.csrfToken)}">
                    <div class="form-group">
                        <label>Tresor-Passwort</label>
                        <input type="password" name="vault_password" id="vault-pw-inp" class="input" placeholder="Tresor-Passwort eingeben…" required>
                    </div>
                    <div style="display:flex;gap:0.5rem;flex-wrap:wrap">
                        <button type="submit" class="btn btn-primary" style="flex:1">⬇️ Einmalig entschlüsselt herunterladen</button>
                        <button type="button" class="btn btn-ghost" id="btn-vault-unlock-perm" style="flex:1">🔓 Dauerhaft entschlüsseln</button>
                    </div>
                </form>
            `);
            $('#vault-pw-inp').focus();
            $('#btn-vault-unlock-perm').addEventListener('click', async () => {
                const pw = $('#vault-pw-inp').value;
                if (!pw) {
                    toast('Bitte Tresor-Passwort eingeben.', 'error');
                    return;
                }
                try {
                    await apiPost('vault_decrypt', { file_id: file.id, password: pw });
                    closeModal();
                    toast(`"${file.name}" wurde dauerhaft entschlüsselt.`);
                    loadCurrentView();
                } catch (err) {
                    toast(err.message, 'error');
                }
            });
        }
    }

    // Share Modal (Public Links + Internal User Sharing)
    async function openShareModal(item) {
        try {
            const data = await apiGet({ action: 'item_shares', item_type: item.type, item_id: item.id });
            renderShareModalContent(item, data);
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function renderShareModalContent(item, data) {
        const isFolder = item.type === 'folder';
        const pubRows = (data.public_shares || []).map((ps) => {
            const shareUrl = `${state.baseUrl}/share.php?t=${ps.token}`;
            const modeBadge = ps.mode === 'upload' ? '📤 Nur Upload (File-Drop)' : ps.mode === 'readwrite' ? '🔄 Lesen & Upload' : '⬇️ Download';
            return `
                <div style="border:1px solid var(--border);border-radius:8px;padding:0.65rem;margin-bottom:0.5rem;background:var(--bg)">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;margin-bottom:0.4rem">
                        <span class="small">
                            <strong>${modeBadge}</strong>
                            ${ps.has_password ? ' · 🔒 Passwort' : ''}
                            ${ps.expires_at ? ` · 📅 bis ${esc(ps.expires_at)}` : ''}
                            · ${ps.download_count}${ps.max_downloads ? '/' + ps.max_downloads : ''} Abrufe
                        </span>
                        <button type="button" class="btn btn-danger btn-sm" data-del-pub="${ps.id}">Löschen</button>
                    </div>
                    <div class="form-row">
                        <input type="text" class="input small" value="${esc(shareUrl)}" readonly onclick="this.select()">
                        <button type="button" class="btn btn-primary btn-sm" data-copy="${esc(shareUrl)}">Kopieren</button>
                    </div>
                </div>
            `;
        }).join('');

        const groupRows = (data.group_shares || []).map((gs) => `
            <tr>
                <td>👪 <strong>${esc(gs.group_name)}</strong> <span class="muted small">(${gs.member_count} Mitgl.)</span></td>
                <td>${gs.permission === 'write' ? '✏️ Lesen & Schreiben' : '👁️ Nur Lesen'}</td>
                <td style="text-align:right">
                    <button type="button" class="btn btn-danger btn-sm" data-del-grp="${gs.id}">Entfernen</button>
                </td>
            </tr>
        `).join('');

        const userRows = (data.user_shares || []).map((us) => `
            <tr>
                <td>👤 <strong>${esc(us.display_name)}</strong> <span class="muted small">(@${esc(us.username)})</span></td>
                <td>${us.permission === 'write' ? '✏️ Lesen & Schreiben' : '👁️ Nur Lesen'}</td>
                <td style="text-align:right">
                    <button type="button" class="btn btn-danger btn-sm" data-del-usr="${us.id}">Entfernen</button>
                </td>
            </tr>
        `).join('');

        const groupOpts = (data.available_groups || []).map((g) => `
            <option value="${g.id}">👪 ${esc(g.name)} (${g.member_count} Mitglieder)</option>
        `).join('');

        const userOpts = (data.available_users || []).map((u) => `
            <option value="${u.id}">👤 ${esc(u.display_name)} (@${esc(u.username)})</option>
        `).join('');

        openModal(`🔗 Teilen: ${item.name}`, `
            <div class="tabs">
                <button type="button" class="tab-btn active" data-tab="tab-public">🌐 Öffentlicher Link</button>
                ${!data.is_group_tree ? `<button type="button" class="tab-btn" data-tab="tab-internal">👥 Benutzer & Gruppen</button>` : ''}
            </div>

            <div id="tab-public">
                ${pubRows || '<p class="muted small">Noch kein öffentlicher Link für dieses Element erstellt.</p>'}
                <hr style="border:none;border-top:1px solid var(--border);margin:1rem 0">
                <form id="create-pub-share-form">
                    <h4 style="margin:0 0 0.75rem">Neuen öffentlichen Link erstellen</h4>
                    ${isFolder ? `
                    <div class="form-group">
                        <label>Ordner-Modus</label>
                        <select name="mode" class="input">
                            <option value="download">⬇️ Nur Download (Gäste können Dateien ansehen & herunterladen)</option>
                            <option value="upload">📤 Digitaler Briefkasten / File-Drop (Gäste können nur Dateien hochladen)</option>
                            <option value="readwrite">🔄 Lesen & Hochladen (Gäste können herunterladen und hochladen)</option>
                        </select>
                    </div>` : ''}
                    <div class="form-row" style="margin-bottom:0.75rem">
                        <div style="flex:1">
                            <label class="small muted">Optionales Passwort</label>
                            <input type="password" name="password" class="input" placeholder="Kein Passwort">
                        </div>
                        <div style="flex:1">
                            <label class="small muted">Gültig bis (optional)</label>
                            <input type="date" name="expires_at" class="input">
                        </div>
                        <div style="width:120px">
                            <label class="small muted">Max. Downloads</label>
                            <input type="number" name="max_downloads" class="input" min="1" placeholder="∞">
                        </div>
                    </div>
                    <button type="submit" class="btn btn-primary btn-block">🔗 Link generieren</button>
                </form>
            </div>

            <div id="tab-internal" class="hidden">
                ${groupOpts ? `
                <form id="create-grp-share-form" style="margin-bottom:0.75rem">
                    <label class="small muted" style="display:block;margin-bottom:0.25rem">Für Benutzergruppe freigeben (erscheint bei Mitgliedern unter 👥 Geteilt)</label>
                    <div class="form-row">
                        <select name="target_group_id" class="input" style="flex:2" required>${groupOpts}</select>
                        <select name="permission" class="input" style="flex:1">
                            <option value="write">✏️ Lesen & Schreiben</option>
                            <option value="read">👁️ Nur Lesen</option>
                        </select>
                        <button type="submit" class="btn btn-primary">Gruppe freigeben</button>
                    </div>
                </form>` : ''}

                ${userOpts ? `
                <form id="create-usr-share-form" style="margin-bottom:1rem">
                    <label class="small muted" style="display:block;margin-bottom:0.25rem">Für einzelnen Benutzer freigeben</label>
                    <div class="form-row">
                        <select name="target_user_id" class="input" style="flex:2" required>${userOpts}</select>
                        <select name="permission" class="input" style="flex:1">
                            <option value="read">👁️ Nur Lesen</option>
                            <option value="write">✏️ Lesen & Schreiben</option>
                        </select>
                        <button type="submit" class="btn btn-primary">Freigeben</button>
                    </div>
                </form>` : (!groupOpts ? '<p class="muted small">Keine weiteren Benutzer oder Gruppen vorhanden. Als Admin können Sie unter ⚙️ Admin Benutzer & Gruppen anlegen.</p>' : '')}

                <div class="file-table-wrap">
                    <table class="file-table">
                        <thead><tr><th>Empfänger (Gruppe / Benutzer)</th><th>Berechtigung</th><th style="text-align:right">Aktion</th></tr></thead>
                        <tbody>${(groupRows + userRows) || '<tr><td colspan="3" class="empty-cell">Nicht intern geteilt.</td></tr>'}</tbody>
                    </table>
                </div>
            </div>
        `);

        // Modal Tab Switching
        const body = $('#modal-body');
        $$('.tab-btn', body).forEach((btn) => {
            btn.addEventListener('click', () => {
                $$('.tab-btn', body).forEach((b) => b.classList.remove('active'));
                btn.classList.add('active');
                $('#tab-public', body).classList.toggle('hidden', btn.dataset.tab !== 'tab-public');
                $('#tab-internal', body).classList.toggle('hidden', btn.dataset.tab !== 'tab-internal');
            });
        });

        $$('[data-copy]', body).forEach((btn) => {
            btn.addEventListener('click', () => {
                navigator.clipboard?.writeText(btn.dataset.copy);
                toast('Link in die Zwischenablage kopiert!');
            });
        });

        $$('[data-del-pub]', body).forEach((btn) => {
            btn.addEventListener('click', async () => {
                await apiPost('delete_public_share', { share_id: Number(btn.dataset.delPub) });
                toast('Link gelöscht.');
                openShareModal(item);
                loadCurrentView();
            });
        });

        $$('[data-del-usr]', body).forEach((btn) => {
            btn.addEventListener('click', async () => {
                await apiPost('delete_user_share', { share_id: Number(btn.dataset.delUsr) });
                toast('Freigabe entfernt.');
                openShareModal(item);
                loadCurrentView();
            });
        });

        $$('[data-del-grp]', body).forEach((btn) => {
            btn.addEventListener('click', async () => {
                await apiPost('delete_group_share', { share_id: Number(btn.dataset.delGrp) });
                toast('Gruppen-Freigabe entfernt.');
                openShareModal(item);
                loadCurrentView();
            });
        });

        $('#create-grp-share-form', body)?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            try {
                await apiPost('set_group_share', {
                    item_type: item.type,
                    item_id: item.id,
                    target_group_id: Number(fd.get('target_group_id')),
                    permission: fd.get('permission'),
                });
                toast('Für Gruppe freigegeben.');
                openShareModal(item);
                loadCurrentView();
            } catch (err) {
                toast(err.message, 'error');
            }
        });

        $('#create-pub-share-form', body)?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            try {
                await apiPost('create_public_share', {
                    item_type: item.type,
                    item_id: item.id,
                    mode: fd.get('mode') || 'download',
                    password: fd.get('password') || '',
                    expires_at: fd.get('expires_at') || '',
                    max_downloads: fd.get('max_downloads') || '',
                });
                toast('Öffentlicher Link erstellt!');
                openShareModal(item);
                loadCurrentView();
            } catch (err) {
                toast(err.message, 'error');
            }
        });

        $('#create-usr-share-form', body)?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            try {
                await apiPost('set_user_share', {
                    item_type: item.type,
                    item_id: item.id,
                    target_user_id: Number(fd.get('target_user_id')),
                    permission: fd.get('permission'),
                });
                toast('Für Benutzer freigegeben.');
                openShareModal(item);
                loadCurrentView();
            } catch (err) {
                toast(err.message, 'error');
            }
        });
    }

    // "Meine Freigaben" View
    async function loadMySharesView() {
        $('#folder-write-actions').classList.add('hidden');
        $('#trash-toolbar').classList.add('hidden');
        $('#breadcrumbs').innerHTML = `<span class="crumb active">Meine aktiven Freigaben</span>`;

        try {
            const res = await apiGet({ action: 'my_shares' });
            const pub = res.public_shares || [];
            const usr = res.user_shares || [];
            const grp = res.group_shares || [];
            const container = $('#items-container');

            const pubRows = pub.map((ps) => {
                const url = `${state.baseUrl}/share.php?t=${ps.token}`;
                return `
                    <tr>
                        <td>${ps.item_type === 'folder' ? '📁' : '📄'} <strong>${esc(ps.item_name)}</strong></td>
                        <td>${ps.mode === 'upload' ? '📤 File-Drop' : ps.mode === 'readwrite' ? '🔄 Lesen & Upload' : '⬇️ Download'}${ps.has_password ? ' · 🔒' : ''}</td>
                        <td class="muted small">${ps.expires_at ? esc(ps.expires_at) : 'Unbegrenzt'} (${ps.download_count} Abrufe)</td>
                        <td style="text-align:right">
                            <button type="button" class="btn btn-ghost btn-sm" data-copy-link="${esc(url)}">📋 Link kopieren</button>
                            <button type="button" class="btn btn-danger btn-sm" data-revoke-pub="${ps.id}">Löschen</button>
                        </td>
                    </tr>
                `;
            }).join('');

            const grpRows = grp.map((g) => `
                <tr>
                    <td>${g.item_type === 'folder' ? '📁' : '📄'} <strong>${esc(g.item_name)}</strong></td>
                    <td>👪 <strong>${esc(g.group_name)}</strong> <span class="muted small">(${g.member_count} Mitgl.)</span></td>
                    <td>${g.permission === 'write' ? '✏️ Lesen & Schreiben' : '👁️ Nur Lesen'}</td>
                    <td style="text-align:right">
                        <button type="button" class="btn btn-danger btn-sm" data-revoke-grp="${g.id}">Entfernen</button>
                    </td>
                </tr>
            `).join('');

            const usrRows = usr.map((u) => `
                <tr>
                    <td>${u.item_type === 'folder' ? '📁' : '📄'} <strong>${esc(u.item_name)}</strong></td>
                    <td>👤 ${esc(u.display_name)} (@${esc(u.username)})</td>
                    <td>${u.permission === 'write' ? '✏️ Lesen & Schreiben' : '👁️ Nur Lesen'}</td>
                    <td style="text-align:right">
                        <button type="button" class="btn btn-danger btn-sm" data-revoke-usr="${u.id}">Entfernen</button>
                    </td>
                </tr>
            `).join('');

            container.innerHTML = `
                <h3 style="margin-top:0">🌐 Öffentliche Links (${pub.length})</h3>
                <div class="file-table-wrap" style="margin-bottom:1.75rem">
                    <table class="file-table">
                        <thead><tr><th>Element</th><th>Modus</th><th>Gültigkeit</th><th style="text-align:right">Aktion</th></tr></thead>
                        <tbody>${pubRows || '<tr><td colspan="4" class="empty-cell">Keine öffentlichen Links aktiv.</td></tr>'}</tbody>
                    </table>
                </div>

                <h3>👥 Interne Benutzer- & Gruppen-Freigaben (${usr.length + grp.length})</h3>
                <div class="file-table-wrap">
                    <table class="file-table">
                        <thead><tr><th>Element</th><th>Freigegeben für</th><th>Berechtigung</th><th style="text-align:right">Aktion</th></tr></thead>
                        <tbody>${(grpRows + usrRows) || '<tr><td colspan="4" class="empty-cell">Keine internen Freigaben aktiv.</td></tr>'}</tbody>
                    </table>
                </div>
            `;

            $$('[data-copy-link]', container).forEach((btn) => {
                btn.addEventListener('click', () => {
                    navigator.clipboard?.writeText(btn.dataset.copyLink);
                    toast('Link kopiert!');
                });
            });
            $$('[data-revoke-pub]', container).forEach((btn) => {
                btn.addEventListener('click', async () => {
                    await apiPost('delete_public_share', { share_id: Number(btn.dataset.revokePub) });
                    toast('Link gelöscht.');
                    loadMySharesView();
                });
            });
            $$('[data-revoke-grp]', container).forEach((btn) => {
                btn.addEventListener('click', async () => {
                    await apiPost('delete_group_share', { share_id: Number(btn.dataset.revokeGrp) });
                    toast('Gruppen-Freigabe beendet.');
                    loadMySharesView();
                });
            });
            $$('[data-revoke-usr]', container).forEach((btn) => {
                btn.addEventListener('click', async () => {
                    await apiPost('delete_user_share', { share_id: Number(btn.dataset.revokeUsr) });
                    toast('Freigabe beendet.');
                    loadMySharesView();
                });
            });
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    // Shared Club Calendar ("📅 Kalender") View
    const MONTH_NAMES_DE = [
        'Januar', 'Februar', 'März', 'April', 'Mai', 'Juni',
        'Juli', 'August', 'September', 'Oktober', 'November', 'Dezember',
    ];

    function shiftMonthYm(ym, delta) {
        const [y, m] = ym.split('-').map(Number);
        const d = new Date(y, m - 1 + delta, 1);
        return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
    }

    function formatDeDate(isoStr, allDay = false) {
        if (!isoStr) return '';
        const datePart = isoStr.slice(0, 10);
        const [y, m, d] = datePart.split('-');
        const base = `${d}.${m}.${y}`;
        if (allDay || isoStr.length <= 10) return base;
        const timePart = isoStr.slice(11, 16);
        return timePart ? `${base}, ${timePart} Uhr` : base;
    }

    const CAL_CATEGORIES = [
        { hex: '#3b82f6', label: '🔵 Verein / Allgemein' },
        { hex: '#06b6d4', label: '🏟️ Hallenbelegung' },
        { hex: '#10b981', label: '🟢 Veranstaltung / Fest' },
        { hex: '#8b5cf6', label: '🟣 Sitzung / Vorstand' },
        { hex: '#f59e0b', label: '🟠 Arbeitseinsatz / Technik' },
        { hex: '#ef4444', label: '🔴 Wichtig / Frist' },
    ];

    function calCategoryLabel(hex) {
        return CAL_CATEGORIES.find((c) => c.hex === hex)?.label || '🔵 Verein / Allgemein';
    }

    function calRecurrenceLabel(rec, until) {
        const map = {
            weekly: '🔄 Wöchentlich',
            biweekly: '🔄 Alle 2 Wochen (14-tägig)',
            monthly: '🔄 Monatlich',
            yearly: '🔄 Jährlich',
        };
        const base = map[rec] || '';
        if (!base) return '';
        return until ? `${base} (bis ${formatDeDate(until, true)})` : base;
    }

    async function loadCalendarView() {
        $('#folder-write-actions').classList.add('hidden');
        $('#trash-toolbar').classList.add('hidden');
        $('#breadcrumbs').innerHTML = `<span class="crumb active">📅 Gemeinsamer Vereinskalender</span>`;

        try {
            const res = await apiGet({ action: 'calendar_list', month: state.calendarMonth });
            const ym = res.month || state.calendarMonth;
            state.calendarMonth = ym;
            const rawEvents = res.events || [];
            const rawUpcoming = res.upcoming || [];
            const groups = res.groups || [];
            const canManageCal = !!res.can_manage;

            const activeColor = state.calendarColorFilter || 'all';
            const matchCat = (ev) => activeColor === 'all' || ev.color === activeColor;
            const events = rawEvents.filter(matchCat);
            const upcoming = rawUpcoming.filter(matchCat);

            const [year, month] = ym.split('-').map(Number);
            const monthTitle = `${MONTH_NAMES_DE[month - 1] || ''} ${year}`;

            // Build 6-week (42-day) Monday-first grid
            const firstOfMonth = new Date(year, month - 1, 1);
            const startDow = (firstOfMonth.getDay() + 6) % 7; // 0 = Monday .. 6 = Sunday
            const gridStart = new Date(year, month - 1, 1 - startDow);
            const todayIso = new Date().toISOString().slice(0, 10);

            const eventsByKey = new Map();
            events.forEach((e, idx) => eventsByKey.set(`${e.id}_${e.start_at}_${idx}`, e));
            upcoming.forEach((e, idx) => eventsByKey.set(`up_${e.id}_${e.start_at}_${idx}`, e));

            let cellsHtml = '';
            for (let i = 0; i < 42; i++) {
                const cellDate = new Date(gridStart.getFullYear(), gridStart.getMonth(), gridStart.getDate() + i);
                const cellY = cellDate.getFullYear();
                const cellM = String(cellDate.getMonth() + 1).padStart(2, '0');
                const cellD = String(cellDate.getDate()).padStart(2, '0');
                const isoDate = `${cellY}-${cellM}-${cellD}`;
                const isOtherMonth = cellDate.getMonth() !== (month - 1);
                const isToday = isoDate === todayIso;

                const dayEvents = events.filter((ev) => {
                    const sDay = (ev.start_at || '').slice(0, 10);
                    const eDay = ev.end_at ? ev.end_at.slice(0, 10) : sDay;
                    return isoDate >= sDay && isoDate <= eDay;
                });

                const pills = dayEvents.map((ev) => {
                    const evKey = `${ev.id}_${ev.start_at}`;
                    eventsByKey.set(evKey, ev);
                    const timePrefix = !ev.all_day && (ev.start_at || '').slice(0, 10) === isoDate && ev.start_at.length >= 16
                        ? `${ev.start_at.slice(11, 16)} `
                        : '';
                    const recIcon = ev.recurrence && ev.recurrence !== 'none' ? '🔄 ' : '';
                    const grpTag = ev.group_name ? ` [${ev.group_name}]` : '';
                    return `
                        <div class="cal-event-pill" style="background:${esc(ev.color)}" data-cal-ev="${esc(evKey)}" title="${esc(ev.title + grpTag)}">
                            ${esc(recIcon + timePrefix + ev.title)}
                        </div>
                    `;
                }).join('');

                cellsHtml += `
                    <div class="cal-day ${isOtherMonth ? 'other-month' : ''} ${isToday ? 'today' : ''}" data-cal-date="${isoDate}">
                        <span class="cal-day-num">${cellDate.getDate()}</span>
                        ${pills}
                    </div>
                `;
            }

            const upcomingHtml = upcoming.map((ev) => {
                const evKey = `${ev.id}_${ev.start_at}`;
                eventsByKey.set(evKey, ev);
                const recText = calRecurrenceLabel(ev.recurrence, ev.recurrence_until);
                return `
                    <div class="cal-upcoming-item" data-cal-ev="${esc(evKey)}">
                        <span class="cal-color-dot" style="background:${esc(ev.color)}"></span>
                        <div style="flex:1;min-width:0">
                            <div style="font-weight:600;font-size:0.88rem">${esc(ev.title)}</div>
                            <div class="muted small">${esc(formatDeDate(ev.start_at, ev.all_day))}${ev.end_at ? ` – ${esc(formatDeDate(ev.end_at, ev.all_day))}` : ''}</div>
                            ${recText ? `<div class="muted small">${esc(recText)}</div>` : ''}
                            ${ev.location ? `<div class="muted small">📍 ${esc(ev.location)}</div>` : ''}
                            <div style="margin-top:0.25rem;display:flex;gap:0.3rem;flex-wrap:wrap">
                                <span class="badge">${esc(calCategoryLabel(ev.color))}</span>
                                <span class="badge">${ev.group_name ? `👪 ${esc(ev.group_name)}` : '🌐 Alle Mitglieder'}</span>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');

            const filterCatOpts = [
                `<option value="all" ${activeColor === 'all' ? 'selected' : ''}>✦ Alle Kategorien</option>`,
                ...CAL_CATEGORIES.map((c) => `<option value="${c.hex}" ${activeColor === c.hex ? 'selected' : ''}>${c.label}</option>`),
            ].join('');

            const container = $('#items-container');
            container.innerHTML = `
                <div class="cal-header-bar">
                    <div class="cal-nav-group">
                        <button type="button" class="btn btn-ghost btn-sm" id="btn-cal-prev" title="Vorheriger Monat">◀</button>
                        <div class="cal-month-title">${esc(monthTitle)}</div>
                        <button type="button" class="btn btn-ghost btn-sm" id="btn-cal-next" title="Nächster Monat">▶</button>
                        <button type="button" class="btn btn-ghost btn-sm" id="btn-cal-today">Heute</button>
                    </div>
                    <div style="display:flex;align-items:center;gap:0.5rem;flex-wrap:wrap">
                        <select id="cal-cat-filter" class="input small" style="width:auto;padding:0.35rem 0.65rem">${filterCatOpts}</select>
                        ${canManageCal ? `<button type="button" class="btn btn-primary btn-sm" id="btn-cal-new">➕ Neuer Termin</button>` : ''}
                    </div>
                </div>

                <div class="cal-layout">
                    <div class="cal-board">
                        <div class="cal-weekdays">
                            <span>Mo</span><span>Di</span><span>Mi</span><span>Do</span><span>Fr</span><span>Sa</span><span>So</span>
                        </div>
                        <div class="cal-grid">${cellsHtml}</div>
                    </div>

                    <div class="cal-upcoming-box">
                        <h4 style="margin:0 0 0.25rem">📌 Anstehende Termine</h4>
                        ${upcomingHtml || '<p class="muted small" style="margin:0">Keine anstehenden Termine in dieser Ansicht.</p>'}
                    </div>
                </div>
            `;

            $('#btn-cal-prev', container)?.addEventListener('click', () => {
                state.calendarMonth = shiftMonthYm(state.calendarMonth, -1);
                loadCalendarView();
            });
            $('#btn-cal-next', container)?.addEventListener('click', () => {
                state.calendarMonth = shiftMonthYm(state.calendarMonth, 1);
                loadCalendarView();
            });
            $('#btn-cal-today', container)?.addEventListener('click', () => {
                state.calendarMonth = new Date().toISOString().slice(0, 7);
                loadCalendarView();
            });
            $('#cal-cat-filter', container)?.addEventListener('change', (e) => {
                state.calendarColorFilter = e.target.value;
                loadCalendarView();
            });
            $('#btn-cal-new', container)?.addEventListener('click', () => {
                openCalendarEventModal(null, new Date().toISOString().slice(0, 10), groups);
            });

            $$('[data-cal-ev]', container).forEach((el) => {
                el.addEventListener('click', (e) => {
                    e.stopPropagation();
                    const ev = eventsByKey.get(el.dataset.calEv);
                    if (ev) openCalendarDetailModal(ev, groups);
                });
            });

            if (canManageCal) {
                $$('.cal-day[data-cal-date]', container).forEach((dayEl) => {
                    dayEl.addEventListener('click', () => {
                        openCalendarEventModal(null, dayEl.dataset.calDate, groups);
                    });
                });
            }
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function openCalendarDetailModal(ev, groups) {
        const icsUrl = `api.php?action=calendar_ics&id=${ev.id}`;
        const recText = calRecurrenceLabel(ev.recurrence, ev.recurrence_until);
        openModal(`📅 ${ev.title}`, `
            <div style="display:flex;flex-direction:column;gap:0.75rem">
                <div style="display:flex;gap:0.4rem;flex-wrap:wrap;align-items:center">
                    <span class="badge" style="background:${esc(ev.color)};color:#fff;border:none">${esc(calCategoryLabel(ev.color))}</span>
                    <span class="badge">${ev.group_name ? `👪 Gruppe: ${esc(ev.group_name)}` : '🌐 Für alle Vereinsmitglieder'}</span>
                    <span class="muted small">von ${esc(ev.creator_name)}</span>
                </div>
                <div class="code-box">
                    <strong>🕒 Zeitraum:</strong> ${esc(formatDeDate(ev.start_at, ev.all_day))}${ev.end_at ? ` bis ${esc(formatDeDate(ev.end_at, ev.all_day))}` : ''}${ev.all_day ? ' (Ganztägig)' : ''}
                    ${recText ? `<br><strong>🔄 Wiederholung:</strong> ${esc(recText)}` : ''}
                    ${ev.location ? `<br><strong>📍 Ort / Halle:</strong> ${esc(ev.location)}` : ''}
                </div>
                ${ev.description ? `<div style="white-space:pre-wrap;font-size:0.92rem;line-height:1.5;padding:0.5rem 0">${esc(ev.description)}</div>` : ''}
                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;margin-top:0.4rem">
                    <a class="btn btn-ghost" href="${icsUrl}">📲 In Handy-/PC-Kalender (.ics)</a>
                    <span style="flex:1"></span>
                    ${ev.can_edit ? `
                        <button type="button" class="btn btn-primary" id="btn-cal-edit">✏️ Bearbeiten</button>
                        <button type="button" class="btn btn-danger" id="btn-cal-del">🗑️ Löschen</button>
                    ` : ''}
                </div>
            </div>
        `);

        $('#btn-cal-edit')?.addEventListener('click', () => {
            openCalendarEventModal(ev, null, groups);
        });
        $('#btn-cal-del')?.addEventListener('click', async () => {
            const msg = ev.recurrence && ev.recurrence !== 'none'
                ? 'Diesen regelmäßigen Serientermin wirklich löschen?'
                : 'Diesen Termin wirklich löschen?';
            if (!confirm(msg)) return;
            try {
                await apiPost('calendar_delete', { id: ev.id });
                closeModal();
                toast('Termin gelöscht.');
                loadCalendarView();
            } catch (err) {
                toast(err.message, 'error');
            }
        });
    }

    function openCalendarEventModal(ev, defaultDate, groups) {
        const isEdit = !!ev;
        const allDay = isEdit ? ev.all_day : false;
        const baseDate = defaultDate || new Date().toISOString().slice(0, 10);
        const rawStart = isEdit ? (ev.base_start_at || ev.start_at) : `${baseDate}T19:00`;
        const rawEnd = isEdit ? (ev.base_end_at || ev.end_at || '') : '';
        const initStart = isEdit
            ? (ev.all_day ? rawStart.slice(0, 10) : rawStart.replace(' ', 'T').slice(0, 16))
            : `${baseDate}T19:00`;
        const initEnd = isEdit && rawEnd
            ? (ev.all_day ? rawEnd.slice(0, 10) : rawEnd.replace(' ', 'T').slice(0, 16))
            : '';

        const initRec = isEdit ? (ev.recurrence || 'none') : 'none';
        const initRecUntil = isEdit && ev.recurrence_until ? ev.recurrence_until.slice(0, 10) : '';

        const groupOpts = [
            `<option value="">🌐 Alle Vereinsmitglieder</option>`,
            ...(groups || []).map((g) => `<option value="${g.id}" ${isEdit && ev.group_id === g.id ? 'selected' : ''}>👪 Nur Gruppe: ${esc(g.name)}</option>`),
        ].join('');

        const defaultColor = isEdit ? ev.color : (state.calendarColorFilter !== 'all' ? state.calendarColorFilter : '#3b82f6');
        const colorOpts = CAL_CATEGORIES.map((c) => `<option value="${c.hex}" ${defaultColor === c.hex ? 'selected' : ''}>${c.label}</option>`).join('');

        openModal(isEdit ? `✏️ Termin bearbeiten` : `➕ Neuer Vereinstermin`, `
            <form id="cal-event-form">
                <div class="form-group">
                    <label>Titel des Termins / der Belegung</label>
                    <input type="text" name="title" class="input" value="${esc(isEdit ? ev.title : '')}" placeholder="z. B. Hallentraining Jugend, Vorstandssitzung, Vereinsfest…" required>
                </div>
                <div class="form-row" style="margin-bottom:0.75rem">
                    <div style="flex:1">
                        <label class="small muted">Kategorie</label>
                        <select name="color" class="input">${colorOpts}</select>
                    </div>
                    <div style="flex:1">
                        <label class="small muted">Sichtbarkeit</label>
                        <select name="group_id" class="input">${groupOpts}</select>
                    </div>
                </div>
                <div class="form-group">
                    <label style="display:inline-flex;align-items:center;gap:0.4rem;cursor:pointer">
                        <input type="checkbox" name="all_day" id="cal-allday-chk" value="1" ${allDay ? 'checked' : ''}>
                        <span>Ganztägiger Termin</span>
                    </label>
                </div>
                <div class="form-row" style="margin-bottom:0.75rem">
                    <div style="flex:1">
                        <label class="small muted">Beginn</label>
                        <input type="${allDay ? 'date' : 'datetime-local'}" name="start_at" id="cal-start-inp" class="input" value="${esc(initStart)}" required>
                    </div>
                    <div style="flex:1">
                        <label class="small muted">Ende (optional)</label>
                        <input type="${allDay ? 'date' : 'datetime-local'}" name="end_at" id="cal-end-inp" class="input" value="${esc(initEnd)}">
                    </div>
                </div>
                <div class="form-row" style="margin-bottom:0.75rem">
                    <div style="flex:1">
                        <label class="small muted">🔄 Regelmäßige Wiederholung</label>
                        <select name="recurrence" id="cal-rec-sel" class="input">
                            <option value="none" ${initRec === 'none' ? 'selected' : ''}>Einmaliger Termin</option>
                            <option value="weekly" ${initRec === 'weekly' ? 'selected' : ''}>🔄 Wöchentlich (jede Woche)</option>
                            <option value="biweekly" ${initRec === 'biweekly' ? 'selected' : ''}>🔄 Alle 2 Wochen (14-tägig)</option>
                            <option value="monthly" ${initRec === 'monthly' ? 'selected' : ''}>🔄 Monatlich</option>
                            <option value="yearly" ${initRec === 'yearly' ? 'selected' : ''}>🔄 Jährlich</option>
                        </select>
                    </div>
                    <div style="flex:1" id="cal-rec-until-wrap" class="${initRec === 'none' ? 'hidden' : ''}">
                        <label class="small muted">Wiederholen bis (leer = unbegrenzt)</label>
                        <input type="date" name="recurrence_until" id="cal-rec-until-inp" class="input" value="${esc(initRecUntil)}">
                    </div>
                </div>
                <div class="form-group">
                    <label class="small muted">Ort / Halle (optional)</label>
                    <input type="text" name="location" class="input" value="${esc(isEdit ? ev.location : '')}" placeholder="z. B. Römerhalle, Vereinsheim, Sporthalle…">
                </div>
                <div class="form-group">
                    <label class="small muted">Beschreibung / Notizen (optional)</label>
                    <textarea name="description" class="input" rows="3" placeholder="Übungsleiter, Hinweise, Tagesordnung…">${esc(isEdit ? ev.description : '')}</textarea>
                </div>
                <button type="submit" class="btn btn-primary btn-block">💾 Termin speichern</button>
            </form>
        `);

        const body = $('#modal-body');
        $('#cal-rec-sel', body)?.addEventListener('change', (e) => {
            $('#cal-rec-until-wrap', body)?.classList.toggle('hidden', e.target.value === 'none');
        });

        $('#cal-allday-chk', body)?.addEventListener('change', (e) => {
            const isAd = e.target.checked;
            const sInp = $('#cal-start-inp', body);
            const eInp = $('#cal-end-inp', body);
            if (isAd) {
                const sv = sInp.value.slice(0, 10) || baseDate;
                const evVal = eInp.value ? eInp.value.slice(0, 10) : '';
                sInp.type = 'date';
                eInp.type = 'date';
                sInp.value = sv;
                eInp.value = evVal;
            } else {
                const sv = sInp.value.slice(0, 10) || baseDate;
                const evVal = eInp.value ? eInp.value.slice(0, 10) : '';
                sInp.type = 'datetime-local';
                eInp.type = 'datetime-local';
                sInp.value = `${sv}T19:00`;
                eInp.value = evVal ? `${evVal}T21:00` : '';
            }
        });

        $('#cal-event-form', body)?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            try {
                await apiPost('calendar_save', {
                    id: isEdit ? ev.id : null,
                    title: fd.get('title'),
                    all_day: fd.get('all_day') === '1',
                    start_at: fd.get('start_at'),
                    end_at: fd.get('end_at') || '',
                    recurrence: fd.get('recurrence') || 'none',
                    recurrence_until: fd.get('recurrence_until') || '',
                    group_id: fd.get('group_id') || null,
                    color: fd.get('color'),
                    location: fd.get('location') || '',
                    description: fd.get('description') || '',
                });
                closeModal();
                toast(isEdit ? 'Termin aktualisiert.' : 'Termin eingetragen.');
                loadCalendarView();
            } catch (err) {
                toast(err.message, 'error');
            }
        });
    }

    // Club Member Management ("🪪 Mitglieder") View
    let cachedMembersData = { members: [], departments: [] };

    async function loadMembersView() {
        $('#folder-write-actions').classList.add('hidden');
        $('#trash-toolbar').classList.add('hidden');
        $('#breadcrumbs').innerHTML = `<span class="crumb active">🪪 Vereins-Mitgliederverwaltung</span>`;

        try {
            const res = await apiGet({ action: 'members_list' });
            cachedMembersData = {
                members: res.members || [],
                departments: res.departments || [],
            };
            renderMembersViewContent();
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function renderMembersViewContent() {
        const container = $('#items-container');
        const all = cachedMembersData.members || [];
        const departments = cachedMembersData.departments || [];

        const totalCount = all.length;
        const activeCount = all.filter((m) => m.status === 'Aktiv').length;
        const honorCount = all.filter((m) => m.status === 'Ehrenmitglied').length;
        const youthCount = all.filter((m) => m.status === 'Jugend').length;
        const totalFee = all
            .filter((m) => m.status !== 'Ausgetreten')
            .reduce((sum, m) => sum + (Number(m.fee_amount) || 0), 0);

        const q = (state.memberSearch || '').trim().toLowerCase();
        const stFilter = state.memberStatusFilter || 'all';
        const depFilter = state.memberDeptFilter || 'all';

        const filtered = all.filter((m) => {
            if (stFilter !== 'all' && m.status !== stFilter) return false;
            if (depFilter !== 'all' && m.department !== depFilter) return false;
            if (q !== '') {
                const hay = `${m.member_no} ${m.first_name} ${m.last_name} ${m.street} ${m.zip} ${m.city} ${m.email} ${m.phone} ${m.department} ${m.notes}`.toLowerCase();
                if (!hay.includes(q)) return false;
            }
            return true;
        });

        const dir = state.memberSortDir === 'desc' ? -1 : 1;
        const sorted = filtered.slice().sort((a, b) => {
            if (state.memberSortBy === 'no') {
                return String(a.member_no || '').localeCompare(String(b.member_no || ''), 'de', { numeric: true }) * dir;
            }
            if (state.memberSortBy === 'birth') {
                return String(a.birth_date || '').localeCompare(String(b.birth_date || '')) * dir;
            }
            if (state.memberSortBy === 'city') {
                return String(a.city || '').localeCompare(String(b.city || ''), 'de') * dir;
            }
            if (state.memberSortBy === 'status') {
                return String(a.status || '').localeCompare(String(b.status || ''), 'de') * dir;
            }
            if (state.memberSortBy === 'joined') {
                return String(a.joined_at || '').localeCompare(String(b.joined_at || '')) * dir;
            }
            const nameA = `${a.last_name} ${a.first_name}`;
            const nameB = `${b.last_name} ${b.first_name}`;
            return nameA.localeCompare(nameB, 'de', { sensitivity: 'base' }) * dir;
        });

        const arrow = (col) => state.memberSortBy === col
            ? `<span class="th-sort-arrow">${state.memberSortDir === 'asc' ? '▲' : '▼'}</span>`
            : '';

        const statusBadge = (st) => {
            switch (st) {
                case 'Aktiv':         return `<span class="badge" style="border-color:var(--success);color:var(--success)">✅ Aktiv</span>`;
                case 'Ehrenmitglied': return `<span class="badge" style="border-color:var(--warning);color:var(--warning)">🎖️ Ehrenmitglied</span>`;
                case 'Jugend':        return `<span class="badge badge-shared">🌱 Jugend</span>`;
                case 'Passiv':        return `<span class="badge">⏸️ Passiv</span>`;
                case 'Ausgetreten':   return `<span class="badge" style="opacity:0.6">🚪 Ausgetreten</span>`;
                default:              return `<span class="badge">${esc(st)}</span>`;
            }
        };

        const rowsHtml = sorted.map((m) => `
            <tr data-edit-mem="${m.id}" style="cursor:pointer">
                <td class="muted small" style="white-space:nowrap">${esc(m.member_no || '—')}</td>
                <td>
                    <strong>${esc(m.last_name)}${m.last_name && m.first_name ? ', ' : ''}${esc(m.first_name)}</strong>
                    ${m.department ? `<div class="muted small">🏷️ ${esc(m.department)}</div>` : ''}
                </td>
                <td class="small" style="white-space:nowrap">
                    ${m.birth_date ? esc(formatDeDate(m.birth_date, true)) : '—'}
                    ${m.age !== null ? `<span class="muted">(${m.age} J.)</span>` : ''}
                </td>
                <td class="small">
                    ${esc([m.street, [m.zip, m.city].filter(Boolean).join(' ')].filter(Boolean).join(', ') || '—')}
                </td>
                <td class="small">
                    ${m.phone ? `<div>📞 ${esc(m.phone)}</div>` : ''}
                    ${m.email ? `<div class="muted">${esc(m.email)}</div>` : ''}
                    ${!m.phone && !m.email ? '—' : ''}
                </td>
                <td>${statusBadge(m.status)}</td>
                <td class="small hide-mobile" style="white-space:nowrap">
                    ${m.joined_at ? esc(formatDeDate(m.joined_at, true)) : '—'}
                    ${m.years !== null ? `<span class="muted">(${m.years} J.)</span>` : ''}
                </td>
                <td style="text-align:right;white-space:nowrap">
                    <button type="button" class="btn btn-ghost btn-sm" data-btn-edit-mem="${m.id}" title="Bearbeiten">✏️</button>
                    <button type="button" class="btn btn-danger btn-sm" data-btn-del-mem="${m.id}" title="Löschen">🗑️</button>
                </td>
            </tr>
        `).join('');

        const deptOpts = [
            `<option value="all" ${depFilter === 'all' ? 'selected' : ''}>Alle Abteilungen</option>`,
            ...departments.map((d) => `<option value="${esc(d)}" ${depFilter === d ? 'selected' : ''}>🏷️ ${esc(d)}</option>`),
        ].join('');

        container.innerHTML = `
            <div style="display:flex;gap:0.65rem;flex-wrap:wrap;margin-bottom:0.9rem">
                <span class="badge" style="font-size:0.82rem;padding:0.35rem 0.7rem">👥 Gesamt: <strong>${totalCount}</strong></span>
                <span class="badge" style="font-size:0.82rem;padding:0.35rem 0.7rem">✅ Aktiv: <strong>${activeCount}</strong></span>
                <span class="badge" style="font-size:0.82rem;padding:0.35rem 0.7rem">🌱 Jugend: <strong>${youthCount}</strong></span>
                <span class="badge" style="font-size:0.82rem;padding:0.35rem 0.7rem">🎖️ Ehrenmitglieder: <strong>${honorCount}</strong></span>
                <span class="badge" style="font-size:0.82rem;padding:0.35rem 0.7rem">💶 Beitragssumme: <strong>${totalFee.toFixed(2).replace('.', ',')} €</strong></span>
            </div>

            <div class="cal-header-bar" style="margin-bottom:0.85rem">
                <div style="display:flex;gap:0.5rem;flex-wrap:wrap;flex:1">
                    <input type="search" id="mem-search-inp" class="input small" style="max-width:250px" placeholder="🔍 Name, Ort, Nr., E-Mail…" value="${esc(state.memberSearch)}">
                    <select id="mem-status-filter" class="input small" style="width:auto">
                        <option value="all" ${stFilter === 'all' ? 'selected' : ''}>✦ Alle Status (${totalCount})</option>
                        <option value="Aktiv" ${stFilter === 'Aktiv' ? 'selected' : ''}>✅ Aktiv</option>
                        <option value="Jugend" ${stFilter === 'Jugend' ? 'selected' : ''}>🌱 Jugend</option>
                        <option value="Passiv" ${stFilter === 'Passiv' ? 'selected' : ''}>⏸️ Passiv</option>
                        <option value="Ehrenmitglied" ${stFilter === 'Ehrenmitglied' ? 'selected' : ''}>🎖️ Ehrenmitglied</option>
                        <option value="Ausgetreten" ${stFilter === 'Ausgetreten' ? 'selected' : ''}>🚪 Ausgetreten</option>
                    </select>
                    ${departments.length > 0 ? `<select id="mem-dept-filter" class="input small" style="width:auto">${deptOpts}</select>` : ''}
                </div>
                <div style="display:flex;gap:0.45rem;flex-wrap:wrap">
                    <button type="button" class="btn btn-ghost btn-sm" id="btn-mem-import">📥 CSV-Import</button>
                    <a class="btn btn-ghost btn-sm" href="api.php?action=members_export_csv">📤 Excel/CSV-Export</a>
                    <button type="button" class="btn btn-primary btn-sm" id="btn-mem-new">➕ Neues Mitglied</button>
                </div>
            </div>

            <div class="file-table-wrap">
                <table class="file-table">
                    <thead>
                        <tr>
                            <th style="width:70px"><span class="th-sort-btn ${state.memberSortBy === 'no' ? 'active' : ''}" data-mem-sort="no">Nr. ${arrow('no')}</span></th>
                            <th><span class="th-sort-btn ${state.memberSortBy === 'name' ? 'active' : ''}" data-mem-sort="name">Name / Abteilung ${arrow('name')}</span></th>
                            <th><span class="th-sort-btn ${state.memberSortBy === 'birth' ? 'active' : ''}" data-mem-sort="birth">Geburtsdatum ${arrow('birth')}</span></th>
                            <th><span class="th-sort-btn ${state.memberSortBy === 'city' ? 'active' : ''}" data-mem-sort="city">Anschrift ${arrow('city')}</span></th>
                            <th>Kontakt</th>
                            <th><span class="th-sort-btn ${state.memberSortBy === 'status' ? 'active' : ''}" data-mem-sort="status">Status ${arrow('status')}</span></th>
                            <th class="hide-mobile"><span class="th-sort-btn ${state.memberSortBy === 'joined' ? 'active' : ''}" data-mem-sort="joined">Eintritt ${arrow('joined')}</span></th>
                            <th style="width:90px;text-align:right">Aktion</th>
                        </tr>
                    </thead>
                    <tbody>
                        ${rowsHtml || '<tr><td colspan="8" class="empty-cell">Keine Mitglieder gefunden. Über „➕ Neues Mitglied“ oder „📥 CSV-Import“ können Sie Ihre Vereinsmitglieder anlegen.</td></tr>'}
                    </tbody>
                </table>
            </div>
        `;

        const memById = new Map(all.map((m) => [m.id, m]));

        $('#mem-search-inp', container)?.addEventListener('input', (e) => {
            const pos = e.target.selectionStart ?? e.target.value.length;
            state.memberSearch = e.target.value;
            renderMembersViewContent();
            const inp = $('#mem-search-inp', container);
            if (inp) {
                inp.focus();
                try { inp.setSelectionRange(pos, pos); } catch (_) {}
            }
        });

        $('#mem-status-filter', container)?.addEventListener('change', (e) => {
            state.memberStatusFilter = e.target.value;
            renderMembersViewContent();
        });

        $('#mem-dept-filter', container)?.addEventListener('change', (e) => {
            state.memberDeptFilter = e.target.value;
            renderMembersViewContent();
        });

        $$('[data-mem-sort]', container).forEach((el) => {
            el.addEventListener('click', () => {
                const col = el.dataset.memSort;
                if (state.memberSortBy === col) {
                    state.memberSortDir = state.memberSortDir === 'asc' ? 'desc' : 'asc';
                } else {
                    state.memberSortBy = col;
                    state.memberSortDir = 'asc';
                }
                renderMembersViewContent();
            });
        });

        $('#btn-mem-new', container)?.addEventListener('click', () => {
            openMemberModal(null);
        });

        $('#btn-mem-import', container)?.addEventListener('click', () => {
            openMemberCsvImportModal();
        });

        $$('tr[data-edit-mem]', container).forEach((tr) => {
            tr.addEventListener('click', (e) => {
                if (e.target.closest('button')) return;
                const m = memById.get(Number(tr.dataset.editMem));
                if (m) openMemberModal(m);
            });
        });

        $$('[data-btn-edit-mem]', container).forEach((btn) => {
            btn.addEventListener('click', (e) => {
                e.stopPropagation();
                const m = memById.get(Number(btn.dataset.btnEditMem));
                if (m) openMemberModal(m);
            });
        });

        $$('[data-btn-del-mem]', container).forEach((btn) => {
            btn.addEventListener('click', async (e) => {
                e.stopPropagation();
                const m = memById.get(Number(btn.dataset.btnDelMem));
                if (!m) return;
                if (!confirm(`Mitglied „${m.first_name} ${m.last_name}“ wirklich löschen?`)) return;
                try {
                    await apiPost('members_delete', { id: m.id });
                    toast('Mitglied gelöscht.');
                    loadMembersView();
                } catch (err) {
                    toast(err.message, 'error');
                }
            });
        });
    }

    function openMemberModal(m) {
        const isEdit = !!m;
        const statuses = ['Aktiv', 'Jugend', 'Passiv', 'Ehrenmitglied', 'Ausgetreten'];
        const stOpts = statuses.map((s) => `<option value="${s}" ${isEdit && m.status === s ? 'selected' : ''}>${s}</option>`).join('');

        openModal(isEdit ? `🪪 Mitglied bearbeiten: ${m.first_name} ${m.last_name}` : '➕ Neues Vereinsmitglied anlegen', `
            <form id="member-edit-form">
                <div class="form-row" style="margin-bottom:0.75rem">
                    <div style="width:130px">
                        <label class="small muted">Mitglieds-Nr.</label>
                        <input type="text" name="member_no" class="input" value="${esc(isEdit ? m.member_no : '')}" placeholder="z. B. 1042">
                    </div>
                    <div style="flex:1">
                        <label class="small muted">Vorname</label>
                        <input type="text" name="first_name" class="input" value="${esc(isEdit ? m.first_name : '')}" required>
                    </div>
                    <div style="flex:1">
                        <label class="small muted">Nachname</label>
                        <input type="text" name="last_name" class="input" value="${esc(isEdit ? m.last_name : '')}" required>
                    </div>
                </div>

                <div class="form-row" style="margin-bottom:0.75rem">
                    <div style="flex:1">
                        <label class="small muted">Geburtsdatum</label>
                        <input type="date" name="birth_date" class="input" value="${esc(isEdit && m.birth_date ? m.birth_date : '')}">
                    </div>
                    <div style="flex:1">
                        <label class="small muted">Status</label>
                        <select name="status" class="input">${stOpts}</select>
                    </div>
                    <div style="flex:1">
                        <label class="small muted">Abteilung / Funktion</label>
                        <input type="text" name="department" class="input" value="${esc(isEdit ? m.department : '')}" placeholder="z. B. Turnen, Fußball, Vorstand…">
                    </div>
                </div>

                <div class="form-row" style="margin-bottom:0.75rem">
                    <div style="flex:2">
                        <label class="small muted">Straße & Hausnummer</label>
                        <input type="text" name="street" class="input" value="${esc(isEdit ? m.street : '')}">
                    </div>
                    <div style="width:105px">
                        <label class="small muted">PLZ</label>
                        <input type="text" name="zip" class="input" value="${esc(isEdit ? m.zip : '')}">
                    </div>
                    <div style="flex:1">
                        <label class="small muted">Wohnort</label>
                        <input type="text" name="city" class="input" value="${esc(isEdit ? m.city : '')}">
                    </div>
                </div>

                <div class="form-row" style="margin-bottom:0.75rem">
                    <div style="flex:1">
                        <label class="small muted">Telefon / Mobil</label>
                        <input type="text" name="phone" class="input" value="${esc(isEdit ? m.phone : '')}">
                    </div>
                    <div style="flex:1">
                        <label class="small muted">E-Mail-Adresse</label>
                        <input type="email" name="email" class="input" value="${esc(isEdit ? m.email : '')}">
                    </div>
                </div>

                <div class="form-row" style="margin-bottom:0.75rem">
                    <div style="flex:1">
                        <label class="small muted">Eintrittsdatum</label>
                        <input type="date" name="joined_at" class="input" value="${esc(isEdit && m.joined_at ? m.joined_at : '')}">
                    </div>
                    <div style="flex:1">
                        <label class="small muted">Austrittsdatum (optional)</label>
                        <input type="date" name="left_at" class="input" value="${esc(isEdit && m.left_at ? m.left_at : '')}">
                    </div>
                    <div style="width:130px">
                        <label class="small muted">Beitrag (€)</label>
                        <input type="number" step="0.01" min="0" name="fee_amount" class="input" value="${isEdit ? Number(m.fee_amount || 0).toFixed(2) : '0.00'}">
                    </div>
                </div>

                <div class="form-row" style="margin-bottom:0.75rem">
                    <div style="flex:2">
                        <label class="small muted">IBAN (optional)</label>
                        <input type="text" name="iban" class="input" value="${esc(isEdit ? m.iban : '')}" placeholder="DE…">
                    </div>
                    <div style="flex:1">
                        <label class="small muted">SEPA-Mandatsreferenz</label>
                        <input type="text" name="mandate_ref" class="input" value="${esc(isEdit ? m.mandate_ref : '')}">
                    </div>
                </div>

                <div class="form-group">
                    <label class="small muted">Notizen / Ehrungen / Bemerkungen</label>
                    <textarea name="notes" class="input" rows="2">${esc(isEdit ? m.notes : '')}</textarea>
                </div>

                <button type="submit" class="btn btn-primary btn-block">💾 Mitglied speichern</button>
            </form>
        `, true);

        $('#member-edit-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            const payload = Object.fromEntries(fd.entries());
            if (isEdit) payload.id = m.id;
            try {
                await apiPost('members_save', payload);
                closeModal();
                toast(isEdit ? 'Mitglied aktualisiert.' : 'Mitglied angelegt.');
                loadMembersView();
            } catch (err) {
                toast(err.message, 'error');
            }
        });
    }

    function openMemberCsvImportModal() {
        openModal('📥 Mitgliederliste aus CSV / Excel importieren', `
            <p class="small muted" style="margin-top:0">
                Wählen Sie eine aus Excel exportierte <strong>.csv-Datei</strong> (Trennzeichen Semikolon <code>;</code> oder Komma <code>,</code>).<br>
                Erkannte Spaltenköpfe u. a.: <code>Mitgliedsnummer</code>, <code>Nachname</code>, <code>Vorname</code>, <code>Geburtsdatum</code>, <code>Straße</code>, <code>PLZ</code>, <code>Ort</code>, <code>Telefon</code>, <code>E-Mail</code>, <code>Status</code>, <code>Abteilung</code>, <code>Eintrittsdatum</code>, <code>Beitrag</code>, <code>IBAN</code>, <code>Mandatsreferenz</code>, <code>Notizen</code>.
            </p>
            <form id="mem-csv-import-form">
                <div class="form-group">
                    <input type="file" id="mem-csv-file-inp" accept=".csv,text/csv" class="input" required>
                </div>
                <button type="submit" class="btn btn-primary btn-block">📥 Jetzt importieren</button>
            </form>
        `);

        $('#mem-csv-import-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const file = $('#mem-csv-file-inp')?.files?.[0];
            if (!file) return;
            try {
                const buf = await file.arrayBuffer();
                // Try UTF-8 first; if replacement characters appear, decode as Windows-1252 (classic German Excel CSV)
                let text = new TextDecoder('utf-8', { fatal: false }).decode(buf);
                if (text.includes('\uFFFD')) {
                    text = new TextDecoder('windows-1252').decode(buf);
                }
                const res = await apiPost('members_import_csv', { csv: text });
                closeModal();
                toast(`${res.imported} Mitglieder erfolgreich importiert!`);
                loadMembersView();
            } catch (err) {
                toast(err.message, 'error');
            }
        });
    }

    // "➕ Neu" Dropdown Menu & Upload Triggers
    $('#btn-new-menu-toggle')?.addEventListener('click', (e) => {
        e.stopPropagation();
        $('#undo-history-menu')?.classList.add('hidden');
        $('#new-action-menu')?.classList.toggle('hidden');
    });

    document.addEventListener('click', () => {
        $('#new-action-menu')?.classList.add('hidden');
        $('#undo-history-menu')?.classList.add('hidden');
        state.filterMenuOpen = false;
        $('#th-filter-menu')?.classList.add('hidden');
    });

    $('#btn-upload-trigger')?.addEventListener('click', () => {
        setMobileSidebarOpen(false);
        $('#file-input-multi').click();
    });
    $('#menu-upload-files')?.addEventListener('click', () => $('#file-input-multi').click());
    $('#menu-upload-folder')?.addEventListener('click', () => $('#folder-input-multi').click());
    $('#btn-upload-folder')?.addEventListener('click', () => $('#folder-input-multi').click());

    $('#file-input-multi')?.addEventListener('change', async (e) => {
        const files = Array.from(e.target.files || []).map((f) => ({ file: f, relativePath: '' }));
        e.target.value = '';
        await uploadFileEntries(files, state.currentFolderId);
    });

    $('#folder-input-multi')?.addEventListener('change', async (e) => {
        const files = Array.from(e.target.files || []).map((f) => ({
            file: f,
            relativePath: f.webkitRelativePath || '',
        }));
        e.target.value = '';
        await uploadFileEntries(files, state.currentFolderId);
    });

    // Global Window Drag & Drop for External Files/Folders
    let dragDepth = 0;
    function hideDropOverlay() {
        dragDepth = 0;
        $('#drop-overlay')?.classList.add('hidden');
    }

    // Always hide drop overlay in capture phase whenever a drop or dragend happens anywhere
    window.addEventListener('drop', hideDropOverlay, true);
    window.addEventListener('dragend', hideDropOverlay, true);

    window.addEventListener('dragenter', (e) => {
        if (!state.user || e.dataTransfer?.types?.includes('application/x-cloud-item')) return;
        if (e.dataTransfer?.types?.includes('Files')) {
            dragDepth++;
            $('#drop-overlay').classList.remove('hidden');
        }
    });
    window.addEventListener('dragleave', (e) => {
        if (e.dataTransfer?.types?.includes('Files')) {
            dragDepth = Math.max(0, dragDepth - 1);
            if (
                dragDepth === 0 ||
                !e.relatedTarget ||
                e.clientX <= 0 ||
                e.clientY <= 0 ||
                e.clientX >= window.innerWidth ||
                e.clientY >= window.innerHeight
            ) {
                hideDropOverlay();
            }
        }
    });
    window.addEventListener('dragover', (e) => {
        if (e.dataTransfer?.types?.includes('Files')) {
            e.preventDefault();
        }
    });
    window.addEventListener('drop', async (e) => {
        hideDropOverlay();
        if (!state.user || e.dataTransfer?.types?.includes('application/x-cloud-item')) return;
        if (e.dataTransfer?.files?.length > 0) {
            e.preventDefault();
            const entries = await extractDroppedFileEntries(e.dataTransfer);
            await uploadFileEntries(entries, state.currentFolderId);
        }
    });

    async function extractDroppedFileEntries(dataTransfer) {
        const results = [];
        const items = dataTransfer.items ? Array.from(dataTransfer.items) : [];
        const webkitEntries = items
            .map((it) => (typeof it.webkitGetAsEntry === 'function' ? it.webkitGetAsEntry() : null))
            .filter(Boolean);

        if (webkitEntries.length > 0) {
            async function traverse(entry, pathPrefix = '') {
                if (entry.isFile) {
                    const file = await new Promise((resolve) => entry.file(resolve));
                    results.push({ file, relativePath: pathPrefix ? `${pathPrefix}/${file.name}` : '' });
                } else if (entry.isDirectory) {
                    const reader = entry.createReader();
                    let batch = [];
                    do {
                        batch = await new Promise((resolve) => reader.readEntries(resolve));
                        for (const child of batch) {
                            await traverse(child, pathPrefix ? `${pathPrefix}/${entry.name}` : entry.name);
                        }
                    } while (batch.length > 0);
                }
            }
            for (const entry of webkitEntries) {
                await traverse(entry, '');
            }
            return results;
        }

        return Array.from(dataTransfer.files || []).map((f) => ({
            file: f,
            relativePath: f.webkitRelativePath || '',
        }));
    }

    async function uploadFileEntries(entries, targetFolderId) {
        hideDropOverlay();
        if (!entries || entries.length === 0) return;

        const panel = $('#upload-progress-panel');
        const statusLbl = $('#upload-status-label');
        const pctLbl = $('#upload-percent-label');
        const fill = $('#upload-progress-fill');
        pctLbl.textContent = '0%';
        fill.style.width = '0%';
        panel.classList.remove('hidden');

        const totalBytes = entries.reduce((acc, e) => acc + (e.file.size || 0), 0);
        let uploadedBytes = 0;
        let successCount = 0;

        try {
            for (let i = 0; i < entries.length; i++) {
                const { file, relativePath } = entries[i];
                const totalChunks = Math.max(1, Math.ceil(file.size / CHUNK_SIZE));
                const uploadId = `${Date.now()}_${i}_${Math.random().toString(36).slice(2, 9)}`;

                statusLbl.textContent = `Lade hoch (${i + 1}/${entries.length}): ${file.name}`;

                try {
                    for (let chunkIdx = 0; chunkIdx < totalChunks; chunkIdx++) {
                        const start = chunkIdx * CHUNK_SIZE;
                        const end = Math.min(file.size, start + CHUNK_SIZE);
                        const blob = file.slice(start, end);

                        const fd = new FormData();
                        fd.append('action', 'upload_chunk');
                        fd.append('csrf_token', state.csrfToken);
                        if (targetFolderId !== null) {
                            fd.append('folder_id', String(targetFolderId));
                        }
                        fd.append('upload_id', uploadId);
                        fd.append('chunk_index', String(chunkIdx));
                        fd.append('total_chunks', String(totalChunks));
                        fd.append('file_name', file.name);
                        fd.append('total_size', String(file.size));
                        fd.append('relative_path', relativePath || '');
                        fd.append('chunk', blob, file.name);

                        const res = await fetch('api.php?action=upload_chunk', {
                            method: 'POST',
                            credentials: 'same-origin',
                            headers: { 'X-CSRF-Token': state.csrfToken },
                            body: fd,
                        });
                        const data = await parseJsonResponse(res);
                        if (!res.ok || !data.ok) {
                            throw new Error(data.error || `Fehler bei ${file.name}`);
                        }

                        uploadedBytes += (end - start);
                        const pct = totalBytes > 0 ? Math.min(100, Math.round((uploadedBytes / totalBytes) * 100)) : 100;
                        pctLbl.textContent = `${pct}%`;
                        fill.style.width = `${pct}%`;
                    }
                    successCount++;
                } catch (err) {
                    toast(err.message, 'error');
                }
            }
        } finally {
            hideDropOverlay();
            panel.classList.add('hidden');
            fill.style.width = '0%';
        }

        if (successCount > 0) {
            toast(`${successCount} Datei(en) erfolgreich hochgeladen.`);
            await loadCurrentView();
        }
    }

    // WebDAV Info Modal
    $('#btn-webdav-info')?.addEventListener('click', () => {
        setMobileSidebarOpen(false);
        openWebdavModal();
    });

    function openWebdavModal(generatedToken = '') {
        const hasToken = !!(state.user?.has_webdav_token || generatedToken);
        const has2fa = !!(state.user?.totp_enabled || state.user?.email_2fa_enabled || state.user?.passkey_2fa_enabled);

        openModal('📡 WebDAV – Als Netzlaufwerk einbinden', `
            <p class="muted small" style="margin-top:0">
                Binden Sie Ihren Cloud-Speicher direkt als Laufwerk im Dateimanager ein – ganz ohne Zusatzsoftware:
            </p>
            <div class="form-group">
                <label>Ihre WebDAV-Serveradresse</label>
                <div class="form-row">
                    <input type="text" class="input" value="${esc(state.webdavUrl)}" readonly onclick="this.select()">
                    <button type="button" class="btn btn-primary" id="btn-copy-webdav">Kopieren</button>
                </div>
            </div>

            <div style="border:1px solid var(--border);border-radius:10px;padding:0.9rem;margin:0.9rem 0;background:var(--bg)">
                <div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;flex-wrap:wrap">
                    <div>
                        <strong>🔑 WebDAV-App-Passwort ${has2fa ? '(Bei 2FA erforderlich)' : '(Empfohlen)'}</strong>
                        <div class="muted small">
                            Separates Geräte-Passwort für Netzlaufwerke, damit Ihr Hauptkonto trotz WebDAV durch 2FA geschützt bleibt.
                        </div>
                    </div>
                    <div style="display:flex;gap:0.4rem">
                        <button type="button" class="btn btn-primary btn-sm" id="btn-gen-dav-token">
                            ${hasToken ? '🔄 Neu erzeugen' : '➕ App-Passwort erstellen'}
                        </button>
                        ${hasToken ? `<button type="button" class="btn btn-danger btn-sm" id="btn-rev-dav-token">Widerrufen</button>` : ''}
                    </div>
                </div>
                ${generatedToken ? `
                <div class="alert alert-success" style="margin:0.75rem 0 0">
                    <div class="small">Ihr neues WebDAV-App-Passwort (bitte jetzt kopieren):</div>
                    <div class="form-row" style="margin-top:0.4rem">
                        <input type="text" class="input" value="${esc(generatedToken)}" readonly onclick="this.select()" style="font-family:monospace;font-weight:700">
                        <button type="button" class="btn btn-primary btn-sm" id="btn-copy-dav-token">Kopieren</button>
                    </div>
                </div>` : ''}
            </div>

            <div class="code-box small">
                <strong>Windows Explorer:</strong> Dieser PC → Netzlaufwerk verbinden → Adresse einfügen<br>
                <strong>macOS Finder:</strong> Gehe zu → Mit Server verbinden (⌘K) → Adresse einfügen<br>
                <strong>Linux (GNOME/KDE):</strong> <code>davs://${esc(state.webdavUrl.replace(/^https?:\/\//, ''))}</code><br>
                <strong>Benutzername:</strong> <code>${esc(state.user?.username)}</code> · <strong>Passwort:</strong> ${hasToken ? 'Ihr WebDAV-App-Passwort' : 'Ihr Konto-Passwort'}
            </div>
        `);

        $('#btn-copy-webdav')?.addEventListener('click', () => {
            navigator.clipboard?.writeText(state.webdavUrl);
            toast('WebDAV-Adresse kopiert!');
        });
        $('#btn-copy-dav-token')?.addEventListener('click', () => {
            navigator.clipboard?.writeText(generatedToken);
            toast('WebDAV-App-Passwort kopiert!');
        });
        $('#btn-gen-dav-token')?.addEventListener('click', async () => {
            try {
                const res = await apiPost('generate_webdav_token');
                if (res.user) state.user = res.user;
                openWebdavModal(res.app_password);
                toast('Neues WebDAV-App-Passwort erstellt!');
            } catch (err) {
                toast(err.message, 'error');
            }
        });
        $('#btn-rev-dav-token')?.addEventListener('click', async () => {
            if (!confirm('WebDAV-App-Passwort wirklich widerrufen?')) return;
            try {
                const res = await apiPost('revoke_webdav_token');
                if (res.user) state.user = res.user;
                openWebdavModal('');
                toast('WebDAV-App-Passwort widerrufen.');
            } catch (err) {
                toast(err.message, 'error');
            }
        });
    }

    // PWA Service Worker & Install Prompt Capture
    let deferredPwaPrompt = null;

    if ('serviceWorker' in navigator) {
        window.addEventListener('load', () => {
            navigator.serviceWorker.register('sw.js').catch(() => {});
        });
    }

    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferredPwaPrompt = e;
    });

    window.addEventListener('appinstalled', () => {
        deferredPwaPrompt = null;
        toast('App wurde erfolgreich installiert!');
    });

    function isRunningAsPwa() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    }

    // User Profile & 2FA Security Modal
    $('#btn-profile-modal')?.addEventListener('click', () => {
        setMobileSidebarOpen(false);
        openProfileModal('tab-profile');
    });

    async function openProfileModal(initialTab = 'tab-profile', generatedDavToken = '') {
        if (!state.user) return;
        try {
            const sec = await apiGet({ action: '2fa_status' });
            const passkeyRows = (sec.passkeys || []).map((pk) => `
                <tr>
                    <td>🔑 <strong>${esc(pk.device_name)}</strong></td>
                    <td class="muted small">${esc((pk.created_at || '').slice(0, 10))}</td>
                    <td style="text-align:right">
                        <button type="button" class="btn btn-danger btn-sm" data-del-pk="${pk.id}">Entfernen</button>
                    </td>
                </tr>
            `).join('');

            const isInstalled = isRunningAsPwa();
            const hasDavToken = !!(state.user?.has_webdav_token || generatedDavToken);
            const has2fa = !!(sec.totp_enabled || sec.email_2fa_enabled || sec.passkey_2fa_enabled);
            const isAdmin = !!(state.user.role === 'admin' || state.user.has_admin_panel);

            openModal('👤 Einstellungen', `
                <div class="tabs">
                    <button type="button" class="tab-btn ${initialTab === 'tab-profile' ? 'active' : ''}" data-prof-tab="tab-profile">👤 Profil</button>
                    <button type="button" class="tab-btn ${initialTab === 'tab-2fa' ? 'active' : ''}" data-prof-tab="tab-2fa">🔐 2FA</button>
                    <button type="button" class="tab-btn ${initialTab === 'tab-webdav' ? 'active' : ''}" data-prof-tab="tab-webdav">📡 WebDAV</button>
                    <button type="button" class="tab-btn ${initialTab === 'tab-pwa' ? 'active' : ''}" data-prof-tab="tab-pwa">📱 App</button>
                    ${isAdmin ? `<button type="button" class="tab-btn" id="btn-open-admin-from-profile" title="Administration öffnen" style="margin-left:auto">⚙️</button>` : ''}
                </div>

                <!-- Tab 1: Profile, Theme & Password -->
                <div id="tab-profile" class="${initialTab === 'tab-profile' ? '' : 'hidden'}">
                    <div style="display:flex;justify-content:space-between;align-items:center;padding:0.75rem 0.9rem;border:1px solid var(--border);border-radius:10px;background:var(--bg);margin-bottom:0.9rem">
                        <div>
                            <strong>🌓 Erscheinungsbild (Design)</strong>
                            <div class="muted small">Zwischen dunklem und hellem Farbschema wechseln</div>
                        </div>
                        <button type="button" class="btn btn-ghost btn-sm" id="btn-modal-theme-toggle">
                            ${state.theme === 'dark' ? '☀️ Hell aktivieren' : '🌙 Dunkel aktivieren'}
                        </button>
                    </div>

                    <form id="profile-form">
                        <div class="form-group">
                            <label>Benutzername</label>
                            <input type="text" class="input" value="${esc(state.user.username)}" disabled>
                        </div>
                        <div class="form-row" style="margin-bottom:0.85rem">
                            <div style="flex:1">
                                <label class="small muted">Anzeigename</label>
                                <input type="text" name="display_name" class="input" value="${esc(state.user.display_name)}" required>
                            </div>
                            <div style="flex:1">
                                <label class="small muted">E-Mail-Adresse (für E-Mail-2FA)</label>
                                <input type="email" name="email" class="input" value="${esc(sec.email || '')}" placeholder="name@beispiel.de">
                            </div>
                        </div>
                        <hr style="border:none;border-top:1px solid var(--border);margin:0.9rem 0">
                        <p class="small muted" style="margin-top:0">Passwort ändern (leer lassen, wenn das Passwort gleich bleiben soll):</p>
                        <div class="form-row" style="margin-bottom:0.9rem">
                            <div style="flex:1">
                                <label class="small muted">Aktuelles Passwort</label>
                                <input type="password" name="current_password" class="input" placeholder="Nur bei Passwortänderung">
                            </div>
                            <div style="flex:1">
                                <label class="small muted">Neues Passwort (mind. 6 Zeichen)</label>
                                <input type="password" name="new_password" class="input" minlength="6" placeholder="Unverändert">
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">Profil speichern</button>
                    </form>
                </div>

                <!-- Tab 2: 2FA & Biometrics -->
                <div id="tab-2fa" class="${initialTab === 'tab-2fa' ? '' : 'hidden'}">
                    <!-- 1. TOTP Authenticator App -->
                    <div style="border:1px solid var(--border);border-radius:10px;padding:0.9rem;margin-bottom:0.9rem;background:var(--bg)">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem">
                            <div>
                                <strong>📱 Authenticator-App (TOTP)</strong>
                                <div class="muted small">Google/Microsoft Authenticator, Aegis, Bitwarden, Apple Passwörter</div>
                            </div>
                            ${sec.totp_enabled
                                ? `<button type="button" class="btn btn-danger btn-sm" id="btn-totp-disable">Deaktivieren</button>`
                                : `<button type="button" class="btn btn-primary btn-sm" id="btn-totp-start">Einrichten</button>`
                            }
                        </div>
                        <div id="totp-setup-box" class="hidden" style="margin-top:0.9rem;border-top:1px solid var(--border);padding-top:0.9rem"></div>
                    </div>

                    <!-- 2. Biometrie / Passkeys (WebAuthn) -->
                    <div style="border:1px solid var(--border);border-radius:10px;padding:0.9rem;margin-bottom:0.9rem;background:var(--bg)">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;margin-bottom:0.65rem">
                            <div>
                                <strong>🔑 Biometrie & Passkeys</strong>
                                <div class="muted small">Fingerabdruck (TouchID / Android), FaceID oder Windows Hello</div>
                            </div>
                            <button type="button" class="btn btn-primary btn-sm" id="btn-add-passkey">➕ Gerät hinzufügen</button>
                        </div>
                        <div class="file-table-wrap" style="margin-bottom:0.65rem">
                            <table class="file-table">
                                <tbody>${passkeyRows || '<tr><td colspan="3" class="empty-cell" style="padding:0.75rem !important">Noch kein Biometrie-Gerät registriert.</td></tr>'}</tbody>
                            </table>
                        </div>
                        <label class="small" style="display:flex;align-items:center;gap:0.45rem;cursor:pointer">
                            <input type="checkbox" id="chk-passkey-2fa" ${sec.passkey_2fa_enabled ? 'checked' : ''} ${(sec.passkeys || []).length === 0 ? 'disabled' : ''}>
                            <span>Beim Login mit Passwort zusätzlich Biometrie / Passkey als 2. Faktor verlangen</span>
                        </label>
                    </div>

                    <!-- 3. E-Mail-Einmalcode (2FA) -->
                    <div style="border:1px solid var(--border);border-radius:10px;padding:0.9rem;background:var(--bg)">
                        <div style="display:flex;align-items:center;justify-content:space-between;gap:0.75rem">
                            <div>
                                <strong>✉️ E-Mail-Einmalcode (2FA)</strong>
                                <div class="muted small">
                                    Sendet beim Login einen 6-stelligen Code an <strong>${esc(sec.email || 'keine E-Mail hinterlegt')}</strong>
                                </div>
                            </div>
                            ${sec.email_2fa_enabled
                                ? `<button type="button" class="btn btn-danger btn-sm" id="btn-email-2fa-disable">Deaktivieren</button>`
                                : `<button type="button" class="btn btn-primary btn-sm" id="btn-email-2fa-start">Testcode senden</button>`
                            }
                        </div>
                        <div id="email-2fa-setup-box" class="hidden" style="margin-top:0.85rem;border-top:1px solid var(--border);padding-top:0.85rem"></div>
                    </div>
                </div>

                <!-- Tab 3: WebDAV -->
                <div id="tab-webdav" class="${initialTab === 'tab-webdav' ? '' : 'hidden'}">
                    <p class="muted small" style="margin-top:0">
                        Binden Sie Ihren Cloud-Speicher direkt als Netzlaufwerk im Dateimanager ein – ganz ohne Zusatzsoftware:
                    </p>
                    <div class="form-group">
                        <label>Ihre WebDAV-Serveradresse</label>
                        <div class="form-row">
                            <input type="text" class="input" value="${esc(state.webdavUrl)}" readonly onclick="this.select()">
                            <button type="button" class="btn btn-primary" id="btn-copy-webdav">Kopieren</button>
                        </div>
                    </div>

                    <div style="border:1px solid var(--border);border-radius:10px;padding:0.9rem;margin:0.9rem 0;background:var(--bg)">
                        <div style="display:flex;justify-content:space-between;align-items:center;gap:0.5rem;flex-wrap:wrap">
                            <div>
                                <strong>🔑 WebDAV-App-Passwort ${has2fa ? '(Bei 2FA erforderlich)' : '(Empfohlen)'}</strong>
                                <div class="muted small">
                                    Separates Geräte-Passwort für Netzlaufwerke, damit Ihr Hauptkonto trotz WebDAV durch 2FA geschützt bleibt.
                                </div>
                            </div>
                            <div style="display:flex;gap:0.4rem">
                                <button type="button" class="btn btn-primary btn-sm" id="btn-gen-dav-token">
                                    ${hasDavToken ? '🔄 Neu erzeugen' : '➕ App-Passwort erstellen'}
                                </button>
                                ${hasDavToken ? `<button type="button" class="btn btn-danger btn-sm" id="btn-rev-dav-token">Widerrufen</button>` : ''}
                            </div>
                        </div>
                        ${generatedDavToken ? `
                        <div class="alert alert-success" style="margin:0.75rem 0 0">
                            <div class="small">Ihr neues WebDAV-App-Passwort (bitte jetzt kopieren):</div>
                            <div class="form-row" style="margin-top:0.4rem">
                                <input type="text" class="input" value="${esc(generatedDavToken)}" readonly onclick="this.select()" style="font-family:monospace;font-weight:700">
                                <button type="button" class="btn btn-primary btn-sm" id="btn-copy-dav-token">Kopieren</button>
                            </div>
                        </div>` : ''}
                    </div>

                    <div class="code-box small">
                        <strong>Windows Explorer:</strong> Dieser PC → Netzlaufwerk verbinden → Adresse einfügen<br>
                        <strong>macOS Finder:</strong> Gehe zu → Mit Server verbinden (⌘K) → Adresse einfügen<br>
                        <strong>Linux (GNOME/KDE):</strong> <code>davs://${esc(state.webdavUrl.replace(/^https?:\/\//, ''))}</code><br>
                        <strong>Benutzername:</strong> <code>${esc(state.user?.username)}</code> · <strong>Passwort:</strong> ${hasDavToken ? 'Ihr WebDAV-App-Passwort' : 'Ihr Konto-Passwort'}
                    </div>
                </div>

                <!-- Tab 4: Progressive Web App (PWA) Installation -->
                <div id="tab-pwa" class="${initialTab === 'tab-pwa' ? '' : 'hidden'}">
                    <div style="border:1px solid var(--border);border-radius:10px;padding:1.1rem;background:var(--bg);text-align:center;margin-bottom:1rem">
                        <img src="manifest.php?icon=192" alt="App Icon" style="width:64px;height:64px;border-radius:14px;margin-bottom:0.5rem">
                        <h4 style="margin:0 0 0.3rem">${esc(state.appName)} als App nutzen</h4>
                        <p class="muted small" style="margin:0 0 1rem">
                            Installieren Sie die Cloud direkt auf Ihrem Startbildschirm oder Desktop – ohne Adressleiste und im Vollbildmodus wie eine native App.
                        </p>
                        ${isInstalled
                            ? `<div class="alert alert-success" style="margin-bottom:0">✅ Sie nutzen ${esc(state.appName)} bereits als installierte App!</div>`
                            : deferredPwaPrompt
                                ? `<button type="button" class="btn btn-primary btn-block" id="btn-install-pwa-now">📲 Jetzt als App installieren</button>`
                                : `<div class="code-box small" style="text-align:left">
                                    <strong>📱 Android (Chrome / Edge / Samsung):</strong><br>
                                    Tippen Sie oben rechts im Browser-Menü (<code>⋮</code>) auf <strong>„App installieren“</strong> bzw. <strong>„Zum Startbildschirm hinzufügen“</strong>.<br><br>
                                    <strong>🍏 iPhone & iPad (Safari):</strong><br>
                                    Tippen Sie unten auf das <strong>Teilen-Symbol</strong> (<code>⬆️</code>) und wählen Sie <strong>„Zum Home-Bildschirm“</strong>.<br><br>
                                    <strong>💻 Windows, macOS & Linux (Chrome / Edge):</strong><br>
                                    Klicken Sie rechts in der Adressleiste auf das <strong>Installieren-Symbol (⊕ / 🖥️)</strong>.
                                   </div>`
                        }
                    </div>
                </div>
            `);

            const body = $('#modal-body');

            // Tab switching
            $$('[data-prof-tab]', body).forEach((btn) => {
                btn.addEventListener('click', () => {
                    $$('[data-prof-tab]', body).forEach((b) => b.classList.remove('active'));
                    btn.classList.add('active');
                    ['tab-profile', 'tab-2fa', 'tab-webdav', 'tab-pwa'].forEach((tId) => {
                        $(`#${tId}`, body)?.classList.toggle('hidden', btn.dataset.profTab !== tId);
                    });
                });
            });

            // Open Admin Modal from Profile Menu
            $('#btn-open-admin-from-profile', body)?.addEventListener('click', () => {
                openAdminModal();
            });

            // Theme Toggle inside Profile Modal
            $('#btn-modal-theme-toggle', body)?.addEventListener('click', (e) => {
                state.theme = state.theme === 'dark' ? 'light' : 'dark';
                document.documentElement.setAttribute('data-theme', state.theme);
                localStorage.setItem('cloud_theme', state.theme);
                e.target.textContent = state.theme === 'dark' ? '☀️ Hell aktivieren' : '🌙 Dunkel aktivieren';
            });

            // WebDAV Controls inside Profile Modal
            $('#btn-copy-webdav', body)?.addEventListener('click', () => {
                navigator.clipboard?.writeText(state.webdavUrl);
                toast('WebDAV-Adresse kopiert!');
            });
            $('#btn-copy-dav-token', body)?.addEventListener('click', () => {
                navigator.clipboard?.writeText(generatedDavToken);
                toast('WebDAV-App-Passwort kopiert!');
            });
            $('#btn-gen-dav-token', body)?.addEventListener('click', async () => {
                try {
                    const res = await apiPost('generate_webdav_token');
                    if (res.user) state.user = res.user;
                    openProfileModal('tab-webdav', res.app_password);
                    toast('Neues WebDAV-App-Passwort erstellt!');
                } catch (err) {
                    toast(err.message, 'error');
                }
            });
            $('#btn-rev-dav-token', body)?.addEventListener('click', async () => {
                if (!confirm('WebDAV-App-Passwort wirklich widerrufen?')) return;
                try {
                    const res = await apiPost('revoke_webdav_token');
                    if (res.user) state.user = res.user;
                    openProfileModal('tab-webdav', '');
                    toast('WebDAV-App-Passwort widerrufen.');
                } catch (err) {
                    toast(err.message, 'error');
                }
            });

            // 1-Click PWA Install Button
            $('#btn-install-pwa-now', body)?.addEventListener('click', async () => {
                if (!deferredPwaPrompt) return;
                deferredPwaPrompt.prompt();
                const choice = await deferredPwaPrompt.userChoice;
                if (choice && choice.outcome === 'accepted') {
                    deferredPwaPrompt = null;
                    openProfileModal('tab-pwa');
                }
            });

            // Save Profile & Password
            $('#profile-form', body)?.addEventListener('submit', async (e) => {
                e.preventDefault();
                const fd = new FormData(e.target);
                try {
                    const res = await apiPost('change_password', Object.fromEntries(fd.entries()));
                    if (res.user) state.user = res.user;
                    updateUserSidebar();
                    toast('Profil gespeichert.');
                    openProfileModal('tab-profile');
                } catch (err) {
                    toast(err.message, 'error');
                }
            });

            // TOTP Setup
            $('#btn-totp-start', body)?.addEventListener('click', async () => {
                try {
                    const res = await apiPost('totp_setup');
                    const box = $('#totp-setup-box', body);
                    box.classList.remove('hidden');
                    box.innerHTML = `
                        <div style="display:flex;gap:1rem;align-items:center;flex-wrap:wrap">
                            <div>${res.qr_svg}</div>
                            <div style="flex:1;min-width:210px">
                                <p class="small muted" style="margin-top:0">
                                    1. Scannen Sie den QR-Code mit Ihrer Authenticator-App.<br>
                                    <span style="font-size:0.75rem">Manueller Schlüssel: <code>${esc(res.secret)}</code></span>
                                </p>
                                <form id="totp-verify-form">
                                    <div class="form-row">
                                        <input type="text" name="code" class="input" placeholder="6-stelliger Code" pattern="[0-9]*" inputmode="numeric" maxlength="6" required>
                                        <button type="submit" class="btn btn-primary">Aktivieren</button>
                                    </div>
                                </form>
                            </div>
                        </div>
                    `;
                    $('#totp-verify-form', box).querySelector('input').focus();
                    $('#totp-verify-form', box).addEventListener('submit', async (ev) => {
                        ev.preventDefault();
                        try {
                            const code = ev.target.code.value.trim();
                            const enRes = await apiPost('totp_enable', { code });
                            if (enRes.user) state.user = enRes.user;
                            toast('Authenticator-2FA (TOTP) erfolgreich aktiviert!');
                            openProfileModal('tab-2fa');
                        } catch (err) {
                            toast(err.message, 'error');
                        }
                    });
                } catch (err) {
                    toast(err.message, 'error');
                }
            });

            $('#btn-totp-disable', body)?.addEventListener('click', async () => {
                if (!confirm('Authenticator-2FA (TOTP) wirklich deaktivieren?')) return;
                try {
                    const res = await apiPost('totp_disable');
                    if (res.user) state.user = res.user;
                    toast('Authenticator-2FA deaktiviert.');
                    openProfileModal('tab-2fa');
                } catch (err) {
                    toast(err.message, 'error');
                }
            });

            // Register Biometric Passkey
            $('#btn-add-passkey', body)?.addEventListener('click', async () => {
                if (!window.PublicKeyCredential) {
                    toast('Ihr Browser unterstützt keine WebAuthn-Passkeys.', 'error');
                    return;
                }
                try {
                    const opts = await apiPost('passkey_register_options');
                    const publicKey = {
                        rp: { name: opts.rpName, id: opts.rpId },
                        user: {
                            id: b64urlToUint8Array(opts.userIdB64),
                            name: opts.userName,
                            displayName: opts.userDisplayName,
                        },
                        challenge: b64urlToUint8Array(opts.challengeB64),
                        pubKeyCredParams: [
                            { type: 'public-key', alg: -7 },   // ES256
                            { type: 'public-key', alg: -257 }, // RS256
                        ],
                        timeout: 60000,
                        attestation: 'none',
                        authenticatorSelection: {
                            userVerification: 'preferred',
                            residentKey: 'preferred',
                        },
                        excludeCredentials: (opts.excludeCredentials || []).map((c) => ({
                            type: 'public-key',
                            id: b64urlToUint8Array(c.id),
                        })),
                    };

                    const cred = await navigator.credentials.create({ publicKey });
                    if (!cred) return;

                    let spkiB64 = '';
                    let alg = -7;
                    if (typeof cred.response.getPublicKey === 'function') {
                        const spki = cred.response.getPublicKey();
                        if (spki) spkiB64 = bufferToB64url(spki);
                    }
                    if (typeof cred.response.getPublicKeyAlgorithm === 'function') {
                        alg = cred.response.getPublicKeyAlgorithm() || -7;
                    }
                    let authDataB64 = '';
                    if (typeof cred.response.getAuthenticatorData === 'function') {
                        const ad = cred.response.getAuthenticatorData();
                        if (ad) authDataB64 = bufferToB64url(ad);
                    }

                    const devDefault = navigator.platform ? `Biometrie (${navigator.platform})` : 'Biometrie / Passkey';
                    const devName = prompt('Name für dieses Gerät (z. B. Smartphone, Laptop):', devDefault) || devDefault;

                    await apiPost('passkey_register_verify', {
                        credential_id: bufferToB64url(cred.rawId),
                        client_data_json: bufferToB64url(cred.response.clientDataJSON),
                        authenticator_data: authDataB64,
                        public_key_spki: spkiB64,
                        public_key_alg: alg,
                        device_name: devName,
                    });

                    toast('Biometrie / Passkey erfolgreich gespeichert!');
                    openProfileModal('tab-2fa');
                } catch (err) {
                    toast(err.message || 'Passkey-Einrichtung abgebrochen.', 'error');
                }
            });

            // Delete Passkey
            $$('[data-del-pk]', body).forEach((btn) => {
                btn.addEventListener('click', async () => {
                    if (!confirm('Diesen Passkey wirklich entfernen?')) return;
                    try {
                        await apiPost('passkey_delete', { passkey_id: Number(btn.dataset.delPk) });
                        toast('Passkey entfernt.');
                        openProfileModal('tab-2fa');
                    } catch (err) {
                        toast(err.message, 'error');
                    }
                });
            });

            // Email 2FA 2-Step Verification Setup (prevents accidental lockout!)
            $('#btn-email-2fa-start', body)?.addEventListener('click', async (e) => {
                e.target.disabled = true;
                e.target.textContent = 'Sende…';
                try {
                    const res = await apiPost('email_2fa_setup');
                    const box = $('#email-2fa-setup-box', body);
                    box.classList.remove('hidden');
                    box.innerHTML = `
                        <p class="small muted" style="margin-top:0">
                            Ein 6-stelliger Testcode wurde an <strong>${esc(res.masked_email)}</strong> gesendet (bitte ggf. auch im Spam-Ordner prüfen). Erst nach Eingabe des Codes wird E-Mail-2FA aktiviert:
                        </p>
                        <form id="email-2fa-verify-form" class="form-row">
                            <input type="text" name="code" class="input" placeholder="6-stelliger Code" pattern="[0-9]*" inputmode="numeric" maxlength="6" required>
                            <button type="submit" class="btn btn-primary">Bestätigen & Aktivieren</button>
                        </form>
                    `;
                    $('#email-2fa-verify-form', box).querySelector('input').focus();
                    $('#email-2fa-verify-form', box).addEventListener('submit', async (ev) => {
                        ev.preventDefault();
                        try {
                            const code = ev.target.code.value.trim();
                            const enRes = await apiPost('email_2fa_enable', { code });
                            if (enRes.user) state.user = enRes.user;
                            toast('E-Mail-2FA erfolgreich bestätigt und aktiviert!');
                            openProfileModal('tab-2fa');
                        } catch (err) {
                            toast(err.message, 'error');
                        }
                    });
                } catch (err) {
                    toast(err.message, 'error');
                    e.target.disabled = false;
                    e.target.textContent = 'Testcode senden';
                }
            });

            $('#btn-email-2fa-disable', body)?.addEventListener('click', async () => {
                try {
                    const res = await apiPost('email_2fa_disable');
                    if (res.user) state.user = res.user;
                    toast('E-Mail-2FA deaktiviert.');
                    openProfileModal('tab-2fa');
                } catch (err) {
                    toast(err.message, 'error');
                }
            });

            // Toggle Passkey 2FA checkbox
            $('#chk-passkey-2fa', body)?.addEventListener('change', async () => {
                try {
                    const passkey2fa = $('#chk-passkey-2fa', body).checked;
                    await apiPost('toggle_2fa_flags', { passkey_2fa_enabled: passkey2fa });
                    toast('2FA-Einstellung gespeichert.');
                } catch (err) {
                    toast(err.message, 'error');
                    openProfileModal('tab-2fa');
                }
            });
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    // Admin User & Quota Management Modal
    $('#btn-admin-modal')?.addEventListener('click', () => openAdminModal('adm-users'));

    async function openAdminModal(initialTab = '') {
        try {
            const res = await apiGet({ action: 'admin_users' });
            const users = res.users || [];
            const groups = res.groups || [];
            const groupFolders = res.group_folders || [];
            const settings = res.settings || {};

            const isFullAdmin = state.user?.role === 'admin';
            const canUsers = isFullAdmin || !!state.user?.perm_users;
            const canGroups = isFullAdmin || !!state.user?.perm_groups;
            const canGroupFolders = isFullAdmin || !!state.user?.perm_group_folders;

            const availableTabs = [];
            if (canUsers) availableTabs.push('adm-users');
            if (canGroups) availableTabs.push('adm-groups');
            if (canGroupFolders) availableTabs.push('adm-group-folders');
            if (isFullAdmin) availableTabs.push('adm-settings');

            const activeTab = (typeof initialTab === 'string' && availableTabs.includes(initialTab))
                ? initialTab
                : (availableTabs[0] || 'adm-users');

            const rows = users.map((u) => {
                const quotaMb = u.quota_bytes > 0 ? Math.round(u.quota_bytes / 1048576) : 0;
                const delBadges = [];
                if (u.role !== 'admin') {
                    if (u.perm_calendar) delBadges.push('📅');
                    if (u.perm_members) delBadges.push('🪪');
                    if (u.perm_users) delBadges.push('👥');
                    if (u.perm_groups) delBadges.push('👪');
                    if (u.perm_group_folders) delBadges.push('📂');
                }
                const permHint = delBadges.length > 0 ? ` <span class="badge" title="Zusatzrechte: ${delBadges.join(' ')}">${delBadges.join(' ')}</span>` : '';
                const canModifyRow = isFullAdmin || u.role !== 'admin';
                return `
                    <tr>
                        <td>
                            <strong>${esc(u.display_name)}</strong>
                            <div class="muted small">@${esc(u.username)} · ${u.file_count} Dateien</div>
                        </td>
                        <td>
                            <span class="badge">${u.role === 'admin' ? '👑 Admin' : '👤 User'}</span>${permHint}
                        </td>
                        <td class="small">
                            ${formatBytes(u.used_bytes)} / ${u.quota_bytes > 0 ? formatBytes(u.quota_bytes) : '∞'}
                        </td>
                        <td style="text-align:right">
                            ${canModifyRow ? `<button type="button" class="btn btn-ghost btn-sm" data-edit-user='${esc(JSON.stringify({ ...u, quota_mb: quotaMb }))}'>✏️ Bearbeiten</button>` : ''}
                            ${canModifyRow && u.id !== state.user.id ? `<button type="button" class="btn btn-danger btn-sm" data-del-user="${u.id}">🗑️</button>` : ''}
                        </td>
                    </tr>
                `;
            }).join('');

            const groupRows = groups.map((g) => {
                const memberBadges = (g.members || []).map((m) => `<span class="badge">👤 ${esc(m.display_name)}</span>`).join(' ');
                return `
                    <tr>
                        <td><strong>👪 ${esc(g.name)}</strong></td>
                        <td>${memberBadges || '<span class="muted small">Keine Mitglieder</span>'}</td>
                        <td style="text-align:right;white-space:nowrap">
                            <button type="button" class="btn btn-ghost btn-sm" data-edit-grp='${esc(JSON.stringify(g))}'>✏️</button>
                            <button type="button" class="btn btn-danger btn-sm" data-del-grp-adm="${g.id}">🗑️</button>
                        </td>
                    </tr>
                `;
            }).join('');

            const memberCheckboxes = users.map((u) => `
                <label style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.35rem 0.6rem;border:1px solid var(--border);border-radius:8px;cursor:pointer;font-size:0.86rem">
                    <input type="checkbox" name="grp_member" value="${u.id}">
                    <span>${esc(u.display_name)} <span class="muted">(@${esc(u.username)})</span></span>
                </label>
            `).join('');

            const gfRows = groupFolders.map((gf) => {
                const gBadges = (gf.group_names || []).map((gn) => `<span class="badge badge-shared">👪 ${esc(gn)}</span>`).join(' ');
                return `
                    <tr>
                        <td>
                            <strong>📁 ${esc(gf.name)}</strong>
                            <div class="muted small">${gf.file_count} Dateien · ${formatBytes(gf.used_bytes)}</div>
                        </td>
                        <td>${gBadges || '<span class="muted small">—</span>'}</td>
                        <td class="small">${gf.permission === 'write' ? '✏️ Lesen & Schreiben' : '👁️ Nur Lesen'}</td>
                        <td style="text-align:right;white-space:nowrap">
                            <button type="button" class="btn btn-ghost btn-sm" data-edit-gf='${esc(JSON.stringify(gf))}'>✏️</button>
                            <button type="button" class="btn btn-danger btn-sm" data-del-gf="${gf.id}">🗑️</button>
                        </td>
                    </tr>
                `;
            }).join('');

            const gfGroupCheckboxes = groups.map((g) => `
                <label style="display:inline-flex;align-items:center;gap:0.35rem;padding:0.35rem 0.6rem;border:1px solid var(--border);border-radius:8px;cursor:pointer;font-size:0.86rem">
                    <input type="checkbox" name="gf_group" value="${g.id}">
                    <span>👪 ${esc(g.name)} <span class="muted">(${(g.members || []).length})</span></span>
                </label>
            `).join('');

            openModal('⚙️ Administration', `
                <div class="tabs">
                    ${canUsers ? `<button type="button" class="tab-btn ${activeTab === 'adm-users' ? 'active' : ''}" data-adm-tab="adm-users">👥 Benutzer (${users.length})</button>` : ''}
                    ${canGroups ? `<button type="button" class="tab-btn ${activeTab === 'adm-groups' ? 'active' : ''}" data-adm-tab="adm-groups">👪 Gruppen (${groups.length})</button>` : ''}
                    ${canGroupFolders ? `<button type="button" class="tab-btn ${activeTab === 'adm-group-folders' ? 'active' : ''}" data-adm-tab="adm-group-folders">📂 Gruppenordner (${groupFolders.length})</button>` : ''}
                    ${isFullAdmin ? `<button type="button" class="tab-btn ${activeTab === 'adm-settings' ? 'active' : ''}" data-adm-tab="adm-settings">🛠️ System</button>` : ''}
                </div>

                <div id="adm-users" class="${activeTab === 'adm-users' ? '' : 'hidden'}">
                    <div class="file-table-wrap" style="margin-bottom:1rem">
                        <table class="file-table">
                            <thead><tr><th>Benutzer</th><th>Rolle</th><th>Speicher / Quota</th><th style="text-align:right">Aktion</th></tr></thead>
                            <tbody>${rows}</tbody>
                        </table>
                    </div>

                    <form id="admin-create-user-form" style="border:1px solid var(--border);border-radius:10px;padding:0.85rem;background:var(--bg)">
                        <h4 style="margin:0 0 0.65rem">➕ Neuen Benutzer anlegen</h4>
                        <div class="form-row" style="margin-bottom:0.75rem">
                            <div style="flex:1">
                                <label class="small muted">Benutzername</label>
                                <input type="text" name="username" class="input" placeholder="z. B. maria" required>
                            </div>
                            <div style="flex:1">
                                <label class="small muted">Anzeigename</label>
                                <input type="text" name="display_name" class="input" placeholder="Maria Muster" required>
                            </div>
                        </div>
                        <div class="form-row" style="margin-bottom:0.75rem">
                            <div style="flex:1">
                                <label class="small muted">Passwort (mind. 6 Zeichen)</label>
                                <input type="password" name="password" class="input" required minlength="6">
                            </div>
                            ${isFullAdmin ? `
                            <div style="width:140px">
                                <label class="small muted">Rolle</label>
                                <select name="role" class="input">
                                    <option value="user">Benutzer</option>
                                    <option value="admin">Administrator</option>
                                </select>
                            </div>` : ''}
                            <div style="width:160px">
                                <label class="small muted">Quota in MB (0 = ∞)</label>
                                <input type="number" name="quota_mb" class="input" value="5120" min="0">
                            </div>
                        </div>
                        <div style="margin-bottom:0.75rem">
                            <label class="small muted" style="display:block;margin-bottom:0.3rem">Optionale Berechtigungen (für normale Benutzer):</label>
                            <div style="display:flex;gap:0.75rem;flex-wrap:wrap;font-size:0.85rem">
                                <label style="display:inline-flex;align-items:center;gap:0.3rem;cursor:pointer"><input type="checkbox" name="perm_calendar" value="1"> 📅 Kalender pflegen</label>
                                ${isFullAdmin ? `
                                <label style="display:inline-flex;align-items:center;gap:0.3rem;cursor:pointer"><input type="checkbox" name="perm_members" value="1"> 🪪 Mitgliederverwaltung</label>
                                <label style="display:inline-flex;align-items:center;gap:0.3rem;cursor:pointer"><input type="checkbox" name="perm_users" value="1"> 👥 Benutzer verwalten</label>
                                <label style="display:inline-flex;align-items:center;gap:0.3rem;cursor:pointer"><input type="checkbox" name="perm_groups" value="1"> 👪 Gruppen verwalten</label>
                                <label style="display:inline-flex;align-items:center;gap:0.3rem;cursor:pointer"><input type="checkbox" name="perm_group_folders" value="1"> 📂 Gruppenordner verwalten</label>
                                ` : ''}
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">➕ Benutzer anlegen</button>
                    </form>
                </div>

                <div id="adm-groups" class="${activeTab === 'adm-groups' ? '' : 'hidden'}">
                    <p class="small muted" style="margin-top:0">
                        Hier legen Sie Benutzergruppen an und ordnen diesen Mitglieder zu.
                    </p>
                    <div class="file-table-wrap" style="margin-bottom:1rem">
                        <table class="file-table">
                            <thead><tr><th>Gruppe</th><th>Mitglieder</th><th style="text-align:right">Aktion</th></tr></thead>
                            <tbody>${groupRows || '<tr><td colspan="3" class="empty-cell">Noch keine Benutzergruppen angelegt.</td></tr>'}</tbody>
                        </table>
                    </div>

                    <form id="admin-save-group-form" style="border:1px solid var(--border);border-radius:10px;padding:0.85rem;background:var(--bg)">
                        <input type="hidden" name="group_id" id="adm-grp-id" value="">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.6rem">
                            <h4 id="adm-grp-form-title" style="margin:0">➕ Neue Benutzergruppe anlegen</h4>
                            <button type="button" class="btn btn-ghost btn-sm hidden" id="btn-adm-grp-cancel">Abbrechen</button>
                        </div>
                        <div class="form-group">
                            <label class="small muted">Gruppenname (z. B. Team, Vorstand, Buchhaltung)</label>
                            <input type="text" name="name" id="adm-grp-name" class="input" placeholder="Gruppenname…" required>
                        </div>
                        <div class="form-group">
                            <label class="small muted">Mitglieder auswählen</label>
                            <div style="display:flex;flex-wrap:wrap;gap:0.45rem;margin-top:0.25rem">
                                ${memberCheckboxes}
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block" id="btn-adm-grp-submit">💾 Gruppe speichern</button>
                    </form>
                </div>

                <div id="adm-group-folders" class="${activeTab === 'adm-group-folders' ? '' : 'hidden'}">
                    <p class="small muted" style="margin-top:0">
                        Hier angelegte Gruppenordner erscheinen automatisch auf der Hauptebene (<strong>📁 Dateien</strong>) aller Mitglieder der zugewiesenen Gruppe(n) – ohne im persönlichen Dateibereich des Administrators aufzutauchen.
                    </p>
                    <div class="file-table-wrap" style="margin-bottom:1rem">
                        <table class="file-table">
                            <thead><tr><th>Gruppenordner</th><th>Zugewiesene Gruppen</th><th>Berechtigung</th><th style="text-align:right">Aktion</th></tr></thead>
                            <tbody>${gfRows || '<tr><td colspan="4" class="empty-cell">Noch keine Gruppenordner angelegt.</td></tr>'}</tbody>
                        </table>
                    </div>

                    ${groups.length > 0 ? `
                    <form id="admin-save-gf-form" style="border:1px solid var(--border);border-radius:10px;padding:0.85rem;background:var(--bg)">
                        <input type="hidden" name="folder_id" id="adm-gf-id" value="">
                        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:0.6rem">
                            <h4 id="adm-gf-form-title" style="margin:0">➕ Neuen Gruppenordner anlegen</h4>
                            <button type="button" class="btn btn-ghost btn-sm hidden" id="btn-adm-gf-cancel">Abbrechen</button>
                        </div>
                        <div class="form-row" style="margin-bottom:0.75rem">
                            <div style="flex:2">
                                <label class="small muted">Ordnername (z. B. Projekte, Buchhaltung, Team-Austausch)</label>
                                <input type="text" name="name" id="adm-gf-name" class="input" placeholder="Name des Gruppenordners…" required>
                            </div>
                            <div style="flex:1">
                                <label class="small muted">Berechtigung</label>
                                <select name="permission" id="adm-gf-perm" class="input">
                                    <option value="write">✏️ Lesen & Schreiben</option>
                                    <option value="read">👁️ Nur Lesen</option>
                                </select>
                            </div>
                        </div>
                        <div class="form-group">
                            <label class="small muted">Für folgende Benutzergruppe(n) freischalten</label>
                            <div style="display:flex;flex-wrap:wrap;gap:0.45rem;margin-top:0.25rem">
                                ${gfGroupCheckboxes}
                            </div>
                        </div>
                        <button type="submit" class="btn btn-primary btn-block">💾 Gruppenordner speichern</button>
                    </form>` : '<p class="muted small">Bitte legen Sie zuerst im Reiter <strong>👪 Gruppen</strong> mindestens eine Benutzergruppe an.</p>'}
                </div>

                <div id="adm-settings" class="${activeTab === 'adm-settings' ? '' : 'hidden'}">
                    <form id="admin-settings-form">
                        <div class="form-group">
                            <label>Name der Cloud</label>
                            <input type="text" name="app_name" class="input" value="${esc(settings.app_name || 'PureCloud')}" required>
                        </div>
                        <div class="form-group">
                            <label>
                                <input type="checkbox" name="enable_webdav" value="1" ${(settings.enable_webdav ?? '1') === '1' ? 'checked' : ''}>
                                WebDAV-Netzlaufwerk-Schnittstelle aktivieren
                            </label>
                        </div>
                        <button type="submit" class="btn btn-primary">Einstellungen speichern</button>
                    </form>
                </div>
            `, true);

            const body = $('#modal-body');
            $$('[data-adm-tab]', body).forEach((btn) => {
                btn.addEventListener('click', () => {
                    $$('[data-adm-tab]', body).forEach((b) => b.classList.remove('active'));
                    btn.classList.add('active');
                    ['adm-users', 'adm-groups', 'adm-group-folders', 'adm-settings'].forEach((id) => {
                        $(`#${id}`, body).classList.toggle('hidden', btn.dataset.admTab !== id);
                    });
                });
            });

            // Group Folder create/edit/delete handlers
            $$('[data-edit-gf]', body).forEach((btn) => {
                btn.addEventListener('click', () => {
                    const gf = JSON.parse(btn.dataset.editGf);
                    $('#adm-gf-id', body).value = String(gf.id);
                    $('#adm-gf-name', body).value = gf.name;
                    $('#adm-gf-perm', body).value = gf.permission || 'write';
                    $('#adm-gf-form-title', body).textContent = `✏️ Gruppenordner bearbeiten: ${gf.name}`;
                    $('#btn-adm-gf-cancel', body).classList.remove('hidden');
                    const gIds = new Set((gf.group_ids || []).map(Number));
                    $$('input[name="gf_group"]', body).forEach((chk) => {
                        chk.checked = gIds.has(Number(chk.value));
                    });
                    $('#adm-gf-name', body).focus();
                });
            });

            $('#btn-adm-gf-cancel', body)?.addEventListener('click', () => {
                $('#adm-gf-id', body).value = '';
                $('#adm-gf-name', body).value = '';
                $('#adm-gf-perm', body).value = 'write';
                $('#adm-gf-form-title', body).textContent = '➕ Neuen Gruppenordner anlegen';
                $('#btn-adm-gf-cancel', body).classList.add('hidden');
                $$('input[name="gf_group"]', body).forEach((chk) => {
                    chk.checked = false;
                });
            });

            $('#admin-save-gf-form', body)?.addEventListener('submit', async (e) => {
                e.preventDefault();
                const rawId = $('#adm-gf-id', body).value;
                const name = $('#adm-gf-name', body).value.trim();
                const permission = $('#adm-gf-perm', body).value;
                const groupIds = $$('input[name="gf_group"]:checked', body).map((chk) => Number(chk.value));
                if (groupIds.length === 0) {
                    toast('Bitte mindestens eine Benutzergruppe auswählen.', 'error');
                    return;
                }
                try {
                    await apiPost('admin_save_group_folder', {
                        id: rawId ? Number(rawId) : null,
                        name,
                        group_ids: groupIds,
                        permission,
                    });
                    toast('Gruppenordner gespeichert.');
                    openAdminModal('adm-group-folders');
                    loadCurrentView();
                } catch (err) {
                    toast(err.message, 'error');
                }
            });

            $$('[data-del-gf]', body).forEach((btn) => {
                btn.addEventListener('click', async () => {
                    if (!confirm('Diesen Gruppenordner samt allen darin enthaltenen Dateien wirklich unwiderruflich löschen?')) return;
                    try {
                        await apiPost('admin_delete_group_folder', { id: Number(btn.dataset.delGf) });
                        toast('Gruppenordner gelöscht.');
                        openAdminModal('adm-group-folders');
                        loadCurrentView();
                    } catch (err) {
                        toast(err.message, 'error');
                    }
                });
            });

            // Group create/edit/delete handlers
            $$('[data-edit-grp]', body).forEach((btn) => {
                btn.addEventListener('click', () => {
                    const g = JSON.parse(btn.dataset.editGrp);
                    $('#adm-grp-id', body).value = String(g.id);
                    $('#adm-grp-name', body).value = g.name;
                    $('#adm-grp-form-title', body).textContent = `✏️ Gruppe bearbeiten: ${g.name}`;
                    $('#btn-adm-grp-cancel', body).classList.remove('hidden');
                    const mIds = new Set((g.member_ids || []).map(Number));
                    $$('input[name="grp_member"]', body).forEach((chk) => {
                        chk.checked = mIds.has(Number(chk.value));
                    });
                    $('#adm-grp-name', body).focus();
                });
            });

            $('#btn-adm-grp-cancel', body)?.addEventListener('click', () => {
                $('#adm-grp-id', body).value = '';
                $('#adm-grp-name', body).value = '';
                $('#adm-grp-form-title', body).textContent = '➕ Neue Benutzergruppe anlegen';
                $('#btn-adm-grp-cancel', body).classList.add('hidden');
                $$('input[name="grp_member"]', body).forEach((chk) => {
                    chk.checked = false;
                });
            });

            $('#admin-save-group-form', body)?.addEventListener('submit', async (e) => {
                e.preventDefault();
                const rawId = $('#adm-grp-id', body).value;
                const name = $('#adm-grp-name', body).value.trim();
                const memberIds = $$('input[name="grp_member"]:checked', body).map((chk) => Number(chk.value));
                try {
                    await apiPost('admin_save_group', {
                        id: rawId ? Number(rawId) : null,
                        name,
                        member_ids: memberIds,
                    });
                    toast('Benutzergruppe gespeichert.');
                    openAdminModal('adm-groups');
                    loadCurrentView();
                } catch (err) {
                    toast(err.message, 'error');
                }
            });

            $$('[data-del-grp-adm]', body).forEach((btn) => {
                btn.addEventListener('click', async () => {
                    if (!confirm('Diese Benutzergruppe wirklich löschen?')) return;
                    try {
                        await apiPost('admin_delete_group', { id: Number(btn.dataset.delGrpAdm) });
                        toast('Benutzergruppe gelöscht.');
                        openAdminModal('adm-groups');
                        loadCurrentView();
                    } catch (err) {
                        toast(err.message, 'error');
                    }
                });
            });

            $('#admin-create-user-form', body)?.addEventListener('submit', async (e) => {
                e.preventDefault();
                const fd = new FormData(e.target);
                try {
                    await apiPost('admin_create_user', Object.fromEntries(fd.entries()));
                    toast('Benutzer erstellt.');
                    openAdminModal();
                } catch (err) {
                    toast(err.message, 'error');
                }
            });

            $('#admin-settings-form', body)?.addEventListener('submit', async (e) => {
                e.preventDefault();
                const fd = new FormData(e.target);
                try {
                    const sRes = await apiPost('admin_save_settings', {
                        app_name: fd.get('app_name'),
                        enable_webdav: fd.get('enable_webdav') === '1',
                    });
                    state.appName = sRes.settings.app_name;
                    $('#app-title-text').textContent = state.appName;
                    document.title = state.appName;
                    toast('Einstellungen gespeichert.');
                } catch (err) {
                    toast(err.message, 'error');
                }
            });

            $$('[data-del-user]', body).forEach((btn) => {
                btn.addEventListener('click', async () => {
                    if (!confirm('Diesen Benutzer und alle seine Dateien wirklich löschen?')) return;
                    try {
                        await apiPost('admin_delete_user', { id: Number(btn.dataset.delUser) });
                        toast('Benutzer gelöscht.');
                        openAdminModal();
                    } catch (err) {
                        toast(err.message, 'error');
                    }
                });
            });

            $$('[data-edit-user]', body).forEach((btn) => {
                btn.addEventListener('click', () => {
                    const u = JSON.parse(btn.dataset.editUser);
                    openEditUserModal(u);
                });
            });
        } catch (err) {
            toast(err.message, 'error');
        }
    }

    function openEditUserModal(u) {
        const isFullAdmin = state.user?.role === 'admin';
        openModal(`Benutzer bearbeiten: @${u.username}`, `
            <form id="edit-user-form">
                <div class="form-group">
                    <label>Anzeigename</label>
                    <input type="text" name="display_name" class="input" value="${esc(u.display_name)}" required>
                </div>
                <div class="form-row" style="margin-bottom:0.75rem">
                    ${isFullAdmin ? `
                    <div style="flex:1">
                        <label class="small muted">Rolle</label>
                        <select name="role" class="input" ${u.id === state.user.id ? 'disabled' : ''}>
                            <option value="user" ${u.role === 'user' ? 'selected' : ''}>Benutzer</option>
                            <option value="admin" ${u.role === 'admin' ? 'selected' : ''}>Administrator</option>
                        </select>
                    </div>` : ''}
                    <div style="flex:1">
                        <label class="small muted">Speicher-Quota in MB (0 = ∞)</label>
                        <input type="number" name="quota_mb" class="input" value="${u.quota_mb}" min="0" required>
                    </div>
                </div>
                ${u.id !== state.user.id ? `
                <div class="form-group" style="border:1px solid var(--border);border-radius:8px;padding:0.65rem;background:var(--bg)">
                    <label class="small muted" style="display:block;margin-bottom:0.35rem">Berechtigungen & delegierte Verwaltungsrechte:</label>
                    <div style="display:flex;flex-direction:column;gap:0.35rem;font-size:0.86rem">
                        <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer">
                            <input type="checkbox" name="perm_calendar" value="1" ${u.perm_calendar ? 'checked' : ''}>
                            <span>📅 Kalender verwalten (Termine eintragen, bearbeiten & löschen)</span>
                        </label>
                        ${isFullAdmin ? `
                        <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer">
                            <input type="checkbox" name="perm_members" value="1" ${u.perm_members ? 'checked' : ''}>
                            <span>🪪 Mitgliederverwaltung (Vereinsmitglieder einsehen, pflegen & CSV-Import/Export)</span>
                        </label>
                        <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer">
                            <input type="checkbox" name="perm_users" value="1" ${u.perm_users ? 'checked' : ''}>
                            <span>👥 Benutzer verwalten (Anlegen, Passwort & Quota)</span>
                        </label>
                        <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer">
                            <input type="checkbox" name="perm_groups" value="1" ${u.perm_groups ? 'checked' : ''}>
                            <span>👪 Gruppen verwalten (Gruppen & Mitglieder zuordnen)</span>
                        </label>
                        <label style="display:flex;align-items:center;gap:0.4rem;cursor:pointer">
                            <input type="checkbox" name="perm_group_folders" value="1" ${u.perm_group_folders ? 'checked' : ''}>
                            <span>📂 Gruppenordner verwalten (Gruppenordner anlegen & zuweisen)</span>
                        </label>
                        ` : ''}
                    </div>
                </div>` : ''}
                <div class="form-group">
                    <label>Neues Passwort setzen (leer lassen für unverändert)</label>
                    <input type="password" name="new_password" class="input" placeholder="Unverändert">
                </div>
                <div class="form-group">
                    <label>
                        <input type="checkbox" name="is_active" value="1" ${u.is_active ? 'checked' : ''} ${u.id === state.user.id ? 'disabled' : ''}>
                        Konto ist aktiv
                    </label>
                </div>
                <div style="display:flex;gap:0.5rem">
                    <button type="button" class="btn btn-ghost" id="btn-back-admin">← Zurück</button>
                    <button type="submit" class="btn btn-primary" style="flex:1">Speichern</button>
                </div>
            </form>
        `);
        $('#btn-back-admin')?.addEventListener('click', openAdminModal);
        $('#edit-user-form')?.addEventListener('submit', async (e) => {
            e.preventDefault();
            const fd = new FormData(e.target);
            try {
                await apiPost('admin_update_user', {
                    id: u.id,
                    display_name: fd.get('display_name'),
                    role: fd.get('role') || u.role,
                    quota_mb: Number(fd.get('quota_mb')),
                    is_active: u.id === state.user.id ? true : fd.get('is_active') === '1',
                    new_password: fd.get('new_password') || '',
                    perm_calendar: fd.get('perm_calendar') === '1',
                    perm_members: fd.get('perm_members') === '1',
                    perm_users: fd.get('perm_users') === '1',
                    perm_groups: fd.get('perm_groups') === '1',
                    perm_group_folders: fd.get('perm_group_folders') === '1',
                });
                toast('Benutzer aktualisiert.');
                openAdminModal();
                loadCurrentView();
            } catch (err) {
                toast(err.message, 'error');
            }
        });
    }

    // Boot Application
    if (state.user) {
        enterAppShell();
    }
})();
