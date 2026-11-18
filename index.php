<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

use Cloud\Auth;
use Cloud\Database;

Auth::startSession();
Auth::sendSecurityHeaders();
header('Cache-Control: no-store, no-cache, must-revalidate');

$settings   = Database::getSettings();
$hasAnyUser = Database::hasAnyUser();
$user       = $hasAnyUser ? Auth::currentUser() : null;
$csrfToken  = Auth::csrfToken();
$appName    = htmlspecialchars($settings['app_name'] ?? 'PureCloud', ENT_QUOTES, 'UTF-8');

$proto   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host    = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseDir = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php'), '/\\');
$baseUrl = $proto . '://' . $host . ($baseDir !== '' ? $baseDir : '');

$bootData = [
    'app_name'     => $settings['app_name'] ?? 'PureCloud',
    'has_any_user' => $hasAnyUser,
    'user'         => $user,
    'csrf_token'   => $csrfToken,
    'base_url'     => $baseUrl,
    'webdav_url'   => $baseUrl . '/webdav.php/',
];
?>
<!DOCTYPE html>
<html lang="de" data-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $appName ?></title>
    <meta name="theme-color" content="#0f172a">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="apple-mobile-web-app-title" content="<?= $appName ?>">
    <link rel="manifest" href="manifest.php">
    <link rel="icon" type="image/png" href="manifest.php?icon=192">
    <link rel="apple-touch-icon" href="manifest.php?icon=192">
    <link rel="stylesheet" href="assets/css/app.css?v=<?= @filemtime(__DIR__ . '/assets/css/app.css') ?: 1 ?>">
</head>
<body>
    <!-- Non-blocking Drag & Drop Indicator Banner -->
    <div id="drop-overlay" class="drop-overlay hidden">
        <div class="drop-overlay-box">
            <span class="drop-icon">📥</span>
            <span id="drop-target-label">Dateien hier ablegen (oder direkt auf einen Ordner ziehen)</span>
        </div>
    </div>

    <!-- Auth / Setup Screen -->
    <div id="auth-screen" class="auth-body <?= $user ? 'hidden' : '' ?>">
        <!-- First-Run Admin Setup -->
        <div id="setup-card" class="auth-card <?= $hasAnyUser ? 'hidden' : '' ?>">
            <div class="auth-logo">☁️</div>
            <h1>Willkommen bei PureCloud</h1>
            <p class="muted">Erstellen Sie das erste Administrator-Konto. Die SQLite-Datenbank wurde bereits automatisch eingerichtet.</p>
            <div id="setup-error" class="alert alert-error hidden"></div>
            <form id="setup-form">
                <div class="form-group">
                    <label>Name der Cloud</label>
                    <input type="text" name="app_name" class="input" value="PureCloud" required>
                </div>
                <div class="form-group">
                    <label>Admin-Benutzername</label>
                    <input type="text" name="username" class="input" placeholder="admin" required autocomplete="username">
                </div>
                <div class="form-group">
                    <label>Anzeigename</label>
                    <input type="text" name="display_name" class="input" placeholder="Administrator" required>
                </div>
                <div class="form-group">
                    <label>Passwort (mind. 6 Zeichen)</label>
                    <input type="password" name="password" class="input" placeholder="••••••••" required minlength="6" autocomplete="new-password">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Cloud jetzt starten</button>
            </form>
        </div>

        <!-- Login Card -->
        <div id="login-card" class="auth-card <?= !$hasAnyUser ? 'hidden' : '' ?>">
            <div class="auth-logo">☁️</div>
            <h1 id="login-app-title"><?= $appName ?></h1>
            <p class="muted">Melden Sie sich an, um auf Ihre Dateien zuzugreifen.</p>
            <div id="login-error" class="alert alert-error hidden"></div>
            <form id="login-form">
                <div class="form-group">
                    <label>Benutzername</label>
                    <input type="text" name="username" id="login-username-input" class="input" placeholder="Benutzername" required autofocus autocomplete="username">
                </div>
                <div class="form-group">
                    <label>Passwort</label>
                    <input type="password" name="password" class="input" placeholder="••••••••" required autocomplete="current-password">
                </div>
                <div class="form-group" style="margin-bottom:0.85rem">
                    <label style="display:flex;align-items:center;gap:0.45rem;font-weight:500;color:var(--text);cursor:pointer;margin:0">
                        <input type="checkbox" name="remember" id="login-remember-chk" value="1" checked style="width:16px;height:16px">
                        <span>Angemeldet bleiben (30 Tage)</span>
                    </label>
                </div>
                <button type="submit" class="btn btn-primary btn-block">Anmelden</button>
            </form>
            <div style="margin-top:0.65rem">
                <button type="button" class="btn btn-ghost btn-block" id="btn-passkey-direct-login">
                    🔑 Mit Biometrie / Passkey anmelden
                </button>
            </div>
        </div>

        <!-- 2FA Verification Card -->
        <div id="twofactor-card" class="auth-card hidden">
            <div class="auth-logo">🔐</div>
            <h1>Zwei-Faktor-Prüfung</h1>
            <p class="muted small" id="twofactor-subtitle">Bitte bestätigen Sie Ihre Anmeldung mit dem zweiten Faktor.</p>
            <div id="twofactor-error" class="alert alert-error hidden"></div>

            <!-- Method Switcher Pills -->
            <div id="twofactor-method-tabs" class="tabs" style="justify-content:center"></div>

            <!-- TOTP / Email 6-Digit Code Form -->
            <form id="twofactor-code-form">
                <div class="form-group">
                    <label id="twofactor-code-label">6-stelliger Sicherheitscode</label>
                    <input type="text" name="code" id="twofactor-code-input" class="input" placeholder="123456" inputmode="numeric" pattern="[0-9]*" maxlength="6" autocomplete="one-time-code" style="text-align:center;font-size:1.25rem;letter-spacing:0.25em;font-weight:700">
                </div>
                <button type="submit" class="btn btn-primary btn-block">Bestätigen & Anmelden</button>
                <button type="button" class="btn btn-ghost btn-sm btn-block hidden" id="btn-resend-email-2fa" style="margin-top:0.5rem">
                    ✉️ E-Mail-Code senden / erneut anfordern
                </button>
            </form>

            <!-- Biometric / Passkey 2FA Button -->
            <div id="twofactor-passkey-box" class="hidden" style="margin:1rem 0">
                <button type="button" class="btn btn-primary btn-block" id="btn-verify-passkey-2fa">
                    🔑 Jetzt mit Fingerabdruck / FaceID bestätigen
                </button>
            </div>

            <div style="margin-top:0.85rem">
                <button type="button" class="btn btn-ghost btn-sm" id="btn-cancel-2fa">← Zurück zum Login</button>
            </div>
        </div>
    </div>

    <!-- Main SPA Workspace -->
    <div id="app-shell" class="app-shell <?= !$user ? 'hidden' : '' ?>">
        <!-- Mobile Sidebar Backdrop -->
        <div id="sidebar-backdrop" class="sidebar-backdrop hidden"></div>

        <!-- Left Sidebar -->
        <aside class="sidebar" id="sidebar">
            <div class="sidebar-brand">
                <span class="brand-icon">☁️</span>
                <span id="app-title-text" class="brand-title"><?= $appName ?></span>
                <button type="button" class="btn btn-ghost btn-sm sidebar-close-btn" id="btn-close-sidebar" title="Menü schließen">✕</button>
            </div>

            <div class="sidebar-upload-btn-wrap">
                <button type="button" class="btn btn-primary btn-block" id="btn-upload-trigger">
                    ⬆️ Hochladen
                </button>
                <input type="file" id="file-input-multi" multiple class="hidden">
                <input type="file" id="folder-input-multi" webkitdirectory directory multiple class="hidden">
            </div>

            <nav class="sidebar-nav">
                <button type="button" class="nav-item active" data-view="files">
                    <span class="nav-icon">📁</span>
                    <span style="flex:1">Dateien</span>
                    <span class="nav-chevron" id="btn-toggle-files-sub" title="Unterpunkte ein-/ausklappen">▾</span>
                </button>
                <div class="nav-sub-group" id="nav-files-sub">
                    <button type="button" class="nav-item nav-sub-item" data-view="shared">
                        <span class="nav-icon">👥</span>
                        <span>Geteilt</span>
                    </button>
                    <button type="button" class="nav-item nav-sub-item" data-view="my_shares">
                        <span class="nav-icon">🔗</span>
                        <span>Freigaben</span>
                    </button>
                    <button type="button" class="nav-item nav-sub-item" data-view="vault">
                        <span class="nav-icon">🔒</span>
                        <span>Tresor</span>
                    </button>
                    <button type="button" class="nav-item nav-sub-item" data-view="trash">
                        <span class="nav-icon">🗑️</span>
                        <span>Papierkorb</span>
                    </button>
                </div>

                <div class="nav-section-label">Verein</div>
                <button type="button" class="nav-item" data-view="calendar">
                    <span class="nav-icon">📅</span>
                    <span>Kalender</span>
                </button>
                <div id="nav-members-wrap" class="hidden">
                    <button type="button" class="nav-item" id="nav-item-members" data-view="members">
                        <span class="nav-icon">🪪</span>
                        <span style="flex:1">Mitglieder</span>
                        <span class="nav-chevron" id="btn-toggle-members-sub" title="Unterpunkte ein-/ausklappen">▾</span>
                    </button>
                    <div class="nav-sub-group" id="nav-members-sub">
                        <button type="button" class="nav-item nav-sub-item" data-view="members_stats">
                            <span class="nav-icon">📊</span>
                            <span>Statistik</span>
                        </button>
                        <button type="button" class="nav-item nav-sub-item" data-view="members_jubilees">
                            <span class="nav-icon">🎂</span>
                            <span>Jubiläen & Geburtstage</span>
                        </button>
                        <button type="button" class="nav-item nav-sub-item" data-view="members_fees">
                            <span class="nav-icon">💶</span>
                            <span>Beiträge & SEPA</span>
                        </button>
                    </div>
                </div>
            </nav>

            <div class="sidebar-spacer"></div>

            <!-- Quota & User Footer -->
            <div class="sidebar-footer">
                <div class="quota-box">
                    <div class="quota-labels">
                        <span>Speicherplatz</span>
                        <span id="quota-text">0 B / ∞</span>
                    </div>
                    <div class="quota-bar">
                        <div id="quota-fill" class="quota-fill" style="width:0%"></div>
                    </div>
                </div>

                <div class="user-row">
                    <div class="user-info" id="btn-profile-modal" title="Konto, Design, WebDAV & Einstellungen öffnen">
                        <div class="user-avatar" id="user-avatar">U</div>
                        <div class="user-meta">
                            <strong id="user-display-name">Benutzer</strong>
                            <span class="muted small" id="user-role-badge">user</span>
                        </div>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm hidden" id="btn-admin-modal" title="Administration öffnen">⚙️</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="btn-logout" title="Abmelden">🚪</button>
                </div>
            </div>
        </aside>

        <!-- Main Content Area -->
        <main class="main-area">
            <!-- Top Header Bar -->
            <header class="top-bar">
                <button type="button" class="btn btn-ghost btn-sm mobile-menu-btn" id="btn-mobile-sidebar" title="Menü">☰</button>

                <div class="search-wrap">
                    <span class="search-icon">🔍</span>
                    <input type="search" id="search-input" class="search-input" placeholder="Suchen…">
                </div>

                <div class="top-actions" id="folder-write-actions">
                    <div class="dropdown-wrap">
                        <button type="button" class="btn btn-ghost btn-sm" id="btn-undo" title="Keine Aktion zum Rückgängigmachen (Strg+Z)" disabled style="opacity:0.4">↩️</button>
                        <div id="undo-history-menu" class="dropdown-menu hidden" style="min-width:275px;max-width:340px"></div>
                    </div>
                    <div class="dropdown-wrap">
                        <button type="button" class="btn btn-primary btn-sm" id="btn-new-menu-toggle" title="Neu erstellen oder hochladen">
                            ➕ <span class="hide-mobile">Neu</span>
                        </button>
                        <div id="new-action-menu" class="dropdown-menu hidden">
                            <button type="button" class="dropdown-item" id="menu-upload-files">⬆️ Dateien hochladen</button>
                            <button type="button" class="dropdown-item" id="menu-upload-folder">📂 Ordner hochladen</button>
                            <div class="dropdown-divider"></div>
                            <button type="button" class="dropdown-item" id="btn-new-folder">📁 Neuer Ordner</button>
                            <button type="button" class="dropdown-item" id="btn-new-text">📝 Neue Textdatei</button>
                        </div>
                    </div>
                </div>

                <div class="view-mode-toggle">
                    <button type="button" class="btn btn-ghost btn-sm active" id="btn-layout-list" title="Listenansicht">☰</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="btn-layout-grid" title="Kachelansicht">⊞</button>
                    <button type="button" class="btn btn-ghost btn-sm hide-mobile" id="btn-layout-split" title="Zweifenstermodus (Split-View)">◫</button>
                </div>
            </header>

            <!-- Sub-Header: Breadcrumbs, Clipboard Paste Bar & Bulk Selection Toolbar -->
            <div class="sub-bar">
                <nav id="breadcrumbs" class="breadcrumbs"></nav>

                <!-- Clipboard Paste Bar when items were copied or cut -->
                <div id="clipboard-toolbar" class="bulk-toolbar hidden">
                    <button type="button" class="btn btn-primary btn-sm" id="btn-clipboard-paste">
                        📥 Hier einfügen
                    </button>
                    <button type="button" class="btn btn-ghost btn-sm" id="btn-clipboard-clear" title="Zwischenablage leeren">✕</button>
                </div>

                <!-- Bulk Action Toolbar when items are selected -->
                <div id="bulk-toolbar" class="bulk-toolbar hidden">
                    <span id="bulk-count" class="bulk-count">0 ausgewählt</span>
                    <button type="button" class="btn btn-ghost btn-sm" id="btn-bulk-copy" title="Auswahl kopieren">📋 Kopieren</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="btn-bulk-cut" title="Auswahl ausschneiden">✂️ Ausschneiden</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="btn-bulk-download">📦 ZIP</button>
                    <button type="button" class="btn btn-ghost btn-sm" id="btn-bulk-trash">🗑️ Löschen</button>
                    <button type="button" class="btn btn-ghost btn-sm hidden" id="btn-bulk-restore">♻️ Wiederherstellen</button>
                    <button type="button" class="btn btn-danger btn-sm hidden" id="btn-bulk-purge">❌ Endgültig löschen</button>
                </div>

                <!-- Trash Empty Button -->
                <div id="trash-toolbar" class="hidden">
                    <button type="button" class="btn btn-danger btn-sm" id="btn-empty-trash">🗑️ Papierkorb leeren</button>
                </div>
            </div>

            <!-- Upload Progress Bar -->
            <div id="upload-progress-panel" class="upload-progress-panel hidden">
                <div class="upload-progress-header">
                    <strong id="upload-status-label">Lade hoch…</strong>
                    <span id="upload-percent-label">0%</span>
                </div>
                <div class="quota-bar">
                    <div id="upload-progress-fill" class="quota-fill" style="width:0%"></div>
                </div>
            </div>

            <!-- File & Folder Container -->
            <div id="file-viewport" class="file-viewport">
                <div id="items-container"></div>
            </div>
        </main>
    </div>

    <!-- Context Menu (Right-Click & Long-Touch) -->
    <div id="context-menu-backdrop" class="context-menu-backdrop hidden"></div>
    <div id="context-menu" class="context-menu hidden"></div>

    <!-- Universal Modal Backdrop -->
    <div id="modal-backdrop" class="modal-backdrop hidden">
        <div id="modal-dialog" class="modal-dialog">
            <div class="modal-header">
                <h3 id="modal-title">Dialog</h3>
                <button type="button" class="btn btn-ghost btn-sm" id="modal-close">✕</button>
            </div>
            <div id="modal-body" class="modal-body"></div>
        </div>
    </div>

    <!-- Toast Notification Container -->
    <div id="toast-container" class="toast-container"></div>

    <script>
        window.CLOUD_BOOT = <?= json_encode($bootData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
    </script>
    <script src="assets/js/app.js?v=<?= @filemtime(__DIR__ . '/assets/js/app.js') ?: 1 ?>"></script>
</body>
</html>
