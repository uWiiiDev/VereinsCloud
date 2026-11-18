<?php
declare(strict_types=1);

require_once __DIR__ . '/src/bootstrap.php';

use Cloud\Auth;
use Cloud\CalendarManager;
use Cloud\CryptoVault;
use Cloud\Database;
use Cloud\FileManager;
use Cloud\MemberManager;
use Cloud\ShareManager;
use Cloud\TwoFactor;
use Cloud\UserManager;

Auth::startSession();
Auth::sendSecurityHeaders();

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');
$input  = [];
if ($method === 'POST') {
    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($contentType, 'application/json') !== false) {
        $raw = file_get_contents('php://input');
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($decoded)) {
            $input = $decoded;
        }
    } else {
        $input = $_POST;
    }
}

$action = (string)($_GET['action'] ?? $input['action'] ?? '');

function jsonResponse(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

try {
    // 1. Unauthenticated / Setup / Auth endpoints
    if ($action === 'setup_admin' && $method === 'POST') {
        $user = UserManager::createFirstAdmin(
            (string)($input['username'] ?? ''),
            (string)($input['display_name'] ?? ''),
            (string)($input['password'] ?? ''),
            (string)($input['app_name'] ?? 'PureCloud')
        );
        jsonResponse([
            'ok'         => true,
            'user'       => $user,
            'csrf_token' => Auth::csrfToken(),
        ]);
    }

    if ($action === 'login' && $method === 'POST') {
        $loginRes = Auth::login(
            (string)($input['username'] ?? ''),
            (string)($input['password'] ?? ''),
            !empty($input['remember'])
        );
        if (!$loginRes) {
            jsonResponse(['ok' => false, 'error' => 'Ungültiger Benutzername oder Passwort.'], 401);
        }
        if (!empty($loginRes['two_factor_required'])) {
            jsonResponse(['ok' => true] + $loginRes);
        }
        jsonResponse([
            'ok'                  => true,
            'two_factor_required' => false,
            'user'                => $loginRes['user'],
            'csrf_token'          => Auth::csrfToken(),
        ]);
    }

    if ($action === 'verify_2fa' && $method === 'POST') {
        Auth::assertNotRateLimited('2fa');
        $pendingUid = (int)($_SESSION['pending_2fa_user_id'] ?? 0);
        if ($pendingUid <= 0) {
            jsonResponse(['ok' => false, 'error' => 'Keine offene 2FA-Anmeldung gefunden. Bitte erneut anmelden.'], 401);
        }
        $attempts = (int)($_SESSION['pending_2fa_attempts'] ?? 0) + 1;
        $_SESSION['pending_2fa_attempts'] = $attempts;
        if ($attempts > 5) {
            unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_attempts']);
            Auth::recordFailedAttempt('2fa', 6, 900, 900);
            jsonResponse(['ok' => false, 'error' => 'Zu viele ungültige 2FA-Versuche. Bitte melden Sie sich erneut an.'], 429);
        }

        $mType = (string)($input['method'] ?? 'totp');
        $code  = (string)($input['code'] ?? '');
        $pdo   = Database::get();
        $stmt  = $pdo->prepare('SELECT totp_secret, totp_enabled FROM users WHERE id = :id AND is_active = 1 LIMIT 1');
        $stmt->execute([':id' => $pendingUid]);
        $uRow  = $stmt->fetch();
        if (!$uRow) {
            jsonResponse(['ok' => false, 'error' => 'Benutzerkonto nicht gefunden.'], 401);
        }

        if ($mType === 'email') {
            if (!TwoFactor::verifyEmailCode($pendingUid, $code)) {
                Auth::recordFailedAttempt('2fa', 6, 900, 900);
                jsonResponse(['ok' => false, 'error' => 'Ungültiger oder abgelaufener E-Mail-Sicherheitscode.'], 401);
            }
        } else {
            if (!TwoFactor::verifyTotpCode((string)$uRow['totp_secret'], $code)) {
                Auth::recordFailedAttempt('2fa', 6, 900, 900);
                jsonResponse(['ok' => false, 'error' => 'Ungültiger 6-stelliger Authenticator-Code.'], 401);
            }
        }

        $loggedInUser = Auth::completeLoginForUserId($pendingUid);
        jsonResponse([
            'ok'         => true,
            'user'       => $loggedInUser,
            'csrf_token' => Auth::csrfToken(),
        ]);
    }

    if ($action === 'send_email_2fa' && $method === 'POST') {
        Auth::assertNotRateLimited('2fa_mail');
        $pendingUid = (int)($_SESSION['pending_2fa_user_id'] ?? 0);
        if ($pendingUid <= 0) {
            jsonResponse(['ok' => false, 'error' => 'Keine offene 2FA-Anmeldung gefunden.'], 401);
        }
        Auth::recordFailedAttempt('2fa_mail', 5, 300, 300);
        $masked = TwoFactor::sendEmailCode($pendingUid);
        jsonResponse(['ok' => true, 'masked_email' => $masked]);
    }

    if ($action === 'passkey_login_options' && $method === 'POST') {
        $pendingUid = !empty($_SESSION['pending_2fa_user_id']) ? (int)$_SESSION['pending_2fa_user_id'] : null;
        $username   = (string)($input['username'] ?? '');
        $opts       = TwoFactor::getPasskeyLoginOptions($pendingUid, $username);
        jsonResponse(['ok' => true] + $opts);
    }

    if ($action === 'passkey_login_verify' && $method === 'POST') {
        $pendingUid   = !empty($_SESSION['pending_2fa_user_id']) ? (int)$_SESSION['pending_2fa_user_id'] : null;
        $loggedInUser = TwoFactor::verifyPasskeyLogin($input, $pendingUid);
        jsonResponse([
            'ok'         => true,
            'user'       => $loggedInUser,
            'csrf_token' => Auth::csrfToken(),
        ]);
    }

    if ($action === 'logout' && $method === 'POST') {
        Auth::logout();
        jsonResponse(['ok' => true]);
    }

    // 2. Require authenticated user for all remaining endpoints
    $user = Auth::currentUser();
    if (!$user) {
        jsonResponse(['ok' => false, 'error' => 'Nicht angemeldet.', 'auth_required' => true], 401);
    }
    $userId = (int)$user['id'];

    // Verify CSRF on all POST requests
    if ($method === 'POST') {
        $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? ($input['csrf_token'] ?? null);
        if (!Auth::verifyCsrf(is_string($csrfHeader) ? $csrfHeader : null)) {
            jsonResponse(['ok' => false, 'error' => 'Ungültiges Sicherheits-Token (CSRF). Bitte Seite neu laden.'], 403);
        }
    }

    switch ($action) {
        case 'list':
            $view     = (string)($_GET['view'] ?? 'files');
            $folderId = isset($_GET['folder_id']) && $_GET['folder_id'] !== '' ? (int)$_GET['folder_id'] : null;
            $search   = (string)($_GET['search'] ?? '');
            $listing  = FileManager::listView($userId, $folderId, $view, $search);
            $user     = Auth::currentUser();
            jsonResponse([
                'ok'   => true,
                'data' => $listing,
                'user' => [
                    'used_bytes'  => $user['used_bytes'],
                    'quota_bytes' => $user['quota_bytes'],
                ],
            ]);

        case 'folder_tree':
            jsonResponse([
                'ok'      => true,
                'folders' => FileManager::getFolderTree($userId),
            ]);

        case 'thumb':
            $fileId = (int)($_GET['id'] ?? 0);
            $access = FileManager::resolveFileAccess($userId, $fileId);
            if (!$access) {
                http_response_code(404);
                exit;
            }
            FileManager::serveThumbnail($access['file']);

        case 'download':
            $fileId = (int)($_GET['id'] ?? $input['id'] ?? 0);
            $inline = !empty($_GET['inline']) || !empty($input['inline']);
            $access = FileManager::resolveFileAccess($userId, $fileId);
            if (!$access) {
                http_response_code(404);
                exit('Datei nicht gefunden oder kein Zugriff.');
            }
            $file = $access['file'];
            $path = Database::FILES_DIR . '/' . basename($file['storage_name']);

            // If encrypted and a vault_password is supplied, decrypt on the fly
            $vaultPassword = (string)($input['vault_password'] ?? $_GET['vault_password'] ?? '');
            if ((int)$file['is_encrypted'] === 1 && $vaultPassword !== '') {
                Auth::assertNotRateLimited('vault');
                try {
                    $plaintext = CryptoVault::decryptToBytes($path, $vaultPassword);
                } catch (Throwable $ve) {
                    Auth::recordFailedAttempt('vault', 10, 900, 900);
                    throw $ve;
                }
                Auth::clearRateLimit('vault');
                $safeName  = str_replace(['"', "\r", "\n"], '', $file['name']);
                header('Content-Type: ' . $file['mime_type']);
                header('Content-Length: ' . strlen($plaintext));
                header('Content-Disposition: attachment; filename="' . $safeName . '"; filename*=UTF-8\'\'' . rawurlencode($file['name']));
                header('X-Content-Type-Options: nosniff');
                header("Content-Security-Policy: default-src 'none'; sandbox");
                echo $plaintext;
                exit;
            }

            FileManager::streamPhysicalFile($path, $file['name'], $file['mime_type'], $inline);

        case 'read_text':
            $fileId = (int)($_GET['id'] ?? 0);
            $access = FileManager::resolveFileAccess($userId, $fileId);
            if (!$access) {
                throw new RuntimeException('Kein Zugriff auf diese Datei.');
            }
            $file = $access['file'];
            if ((int)$file['is_encrypted'] === 1) {
                throw new RuntimeException('Diese Datei ist verschlüsselt.');
            }
            if ((int)$file['size_bytes'] > 5 * 1048576) {
                throw new RuntimeException('Datei ist zu groß für den Texteditor (max. 5 MB).');
            }
            $path = Database::FILES_DIR . '/' . basename($file['storage_name']);
            $content = is_file($path) ? (string)file_get_contents($path) : '';
            if ($content !== '' && function_exists('mb_check_encoding') && !mb_check_encoding($content, 'UTF-8')) {
                $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252, ISO-8859-1, UTF-8');
            }
            jsonResponse([
                'ok'         => true,
                'id'         => (int)$file['id'],
                'name'       => $file['name'],
                'permission' => $access['permission'],
                'content'    => $content,
            ]);

        case 'save_text':
            $folderId = isset($input['folder_id']) && $input['folder_id'] !== '' && $input['folder_id'] !== null ? (int)$input['folder_id'] : null;
            $fileId   = isset($input['file_id']) && $input['file_id'] !== '' && $input['file_id'] !== null ? (int)$input['file_id'] : null;
            $name     = (string)($input['name'] ?? '');
            $content  = (string)($input['content'] ?? '');
            $saved    = FileManager::saveTextFile($userId, $folderId, $fileId, $name, $content);
            jsonResponse(['ok' => true, 'file' => $saved]);

        case 'create_folder':
            $parentId = isset($input['parent_id']) && $input['parent_id'] !== '' && $input['parent_id'] !== null ? (int)$input['parent_id'] : null;
            $name     = (string)($input['name'] ?? '');
            $folder   = FileManager::createFolder($userId, $parentId, $name);
            jsonResponse(['ok' => true, 'folder' => $folder]);

        case 'rename':
            FileManager::renameItem(
                $userId,
                (string)($input['type'] ?? ''),
                (int)($input['id'] ?? 0),
                (string)($input['name'] ?? '')
            );
            jsonResponse(['ok' => true]);

        case 'move':
            $items    = is_array($input['items'] ?? null) ? $input['items'] : [];
            $targetId = isset($input['target_folder_id']) && $input['target_folder_id'] !== '' && $input['target_folder_id'] !== null
                ? (int)$input['target_folder_id']
                : null;
            $undoMoves = [];
            $moved = FileManager::moveItems($userId, $items, $targetId, $undoMoves);
            jsonResponse(['ok' => true, 'moved' => $moved, 'undo_moves' => $undoMoves]);

        case 'undo_moves':
            $moves    = is_array($input['moves'] ?? null) ? $input['moves'] : [];
            $restored = FileManager::undoMoves($userId, $moves);
            jsonResponse(['ok' => true, 'restored' => $restored]);

        case 'copy':
            $items    = is_array($input['items'] ?? null) ? $input['items'] : [];
            $targetId = isset($input['target_folder_id']) && $input['target_folder_id'] !== '' && $input['target_folder_id'] !== null
                ? (int)$input['target_folder_id']
                : null;
            $createdItems = [];
            $copied = FileManager::copyItems($userId, $items, $targetId, $createdItems);
            jsonResponse(['ok' => true, 'copied' => $copied, 'created_items' => $createdItems]);

        case 'trash':
            $items = is_array($input['items'] ?? null) ? $input['items'] : [];
            $count = FileManager::trashItems($userId, $items);
            jsonResponse(['ok' => true, 'count' => $count]);

        case 'restore':
            $items = is_array($input['items'] ?? null) ? $input['items'] : [];
            $count = FileManager::restoreItems($userId, $items);
            jsonResponse(['ok' => true, 'count' => $count]);

        case 'delete_permanent':
            $items = is_array($input['items'] ?? null) ? $input['items'] : [];
            $count = FileManager::deletePermanently($userId, $items);
            jsonResponse(['ok' => true, 'count' => $count]);

        case 'empty_trash':
            $count = FileManager::emptyTrash($userId);
            jsonResponse(['ok' => true, 'count' => $count]);

        case 'upload_chunk':
            if (empty($_FILES['chunk']['tmp_name']) || !is_uploaded_file($_FILES['chunk']['tmp_name'])) {
                throw new RuntimeException('Kein gültiger Datei-Chunk empfangen.');
            }
            $folderId    = isset($_POST['folder_id']) && $_POST['folder_id'] !== '' ? (int)$_POST['folder_id'] : null;
            $uploadId    = (string)($_POST['upload_id'] ?? '');
            $chunkIndex  = (int)($_POST['chunk_index'] ?? 0);
            $totalChunks = max(1, (int)($_POST['total_chunks'] ?? 1));
            $fileName    = (string)($_POST['file_name'] ?? 'upload.bin');
            $totalSize   = (int)($_POST['total_size'] ?? 0);
            $relPath     = (string)($_POST['relative_path'] ?? '');

            $res = FileManager::handleChunkUpload(
                $userId,
                $folderId,
                $uploadId,
                $chunkIndex,
                $totalChunks,
                $fileName,
                $totalSize,
                $_FILES['chunk']['tmp_name'],
                $relPath
            );
            jsonResponse(['ok' => true] + $res);

        case 'zip_inspect':
            $fileId = (int)($_GET['id'] ?? 0);
            $info   = FileManager::inspectZip($userId, $fileId);
            jsonResponse(['ok' => true] + $info);

        case 'zip_extract':
            $fileId   = (int)($input['file_id'] ?? 0);
            $targetId = isset($input['target_folder_id']) && $input['target_folder_id'] !== '' && $input['target_folder_id'] !== null
                ? (int)$input['target_folder_id']
                : null;
            $res = FileManager::extractZip($userId, $fileId, $targetId);
            jsonResponse(['ok' => true] + $res);

        case 'download_zip':
            $rawItems = $input['items'] ?? ($_GET['items'] ?? '[]');
            $items    = is_string($rawItems) ? (json_decode($rawItems, true) ?: []) : (is_array($rawItems) ? $rawItems : []);
            if (empty($items)) {
                throw new RuntimeException('Keine Elemente ausgewählt.');
            }
            $tmpZip = FileManager::buildMultiItemZip($userId, $items);
            register_shutdown_function(function () use ($tmpZip) {
                if (is_file($tmpZip)) {
                    @unlink($tmpZip);
                }
            });
            $dlName = count($items) === 1 && !empty($items[0]['name'])
                ? preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$items[0]['name']) . '.zip'
                : 'cloud-export-' . date('Ymd-His') . '.zip';
            FileManager::streamPhysicalFile($tmpZip, $dlName, 'application/zip', false);

        case 'vault_encrypt':
            $fileId   = (int)($input['file_id'] ?? 0);
            $password = (string)($input['password'] ?? '');
            $access   = FileManager::resolveFileAccess($userId, $fileId);
            if (!$access || !in_array($access['permission'], ['owner', 'write'], true)) {
                throw new RuntimeException('Keine Berechtigung zum Verschlüsseln dieser Datei.');
            }
            $file = $access['file'];
            $path = Database::FILES_DIR . '/' . basename($file['storage_name']);
            $newSize = CryptoVault::encryptFileInPlace($path, $password);
            FileManager::purgeThumbnail($file['storage_name']);
            Database::get()->prepare("
                UPDATE files SET is_encrypted = 1, size_bytes = :s, sha256 = '', updated_at = datetime('now') WHERE id = :id
            ")->execute([':s' => $newSize, ':id' => $fileId]);
            jsonResponse(['ok' => true, 'size_bytes' => $newSize]);

        case 'vault_decrypt':
            Auth::assertNotRateLimited('vault');
            $fileId   = (int)($input['file_id'] ?? 0);
            $password = (string)($input['password'] ?? '');
            $access   = FileManager::resolveFileAccess($userId, $fileId);
            if (!$access || !in_array($access['permission'], ['owner', 'write'], true)) {
                throw new RuntimeException('Keine Berechtigung zum Entschlüsseln dieser Datei.');
            }
            $file = $access['file'];
            $path = Database::FILES_DIR . '/' . basename($file['storage_name']);
            try {
                $newSize = CryptoVault::decryptFileInPlace($path, $password);
            } catch (Throwable $ve) {
                Auth::recordFailedAttempt('vault', 10, 900, 900);
                throw $ve;
            }
            Auth::clearRateLimit('vault');
            $sha256  = hash_file('sha256', $path) ?: '';
            Database::get()->prepare("
                UPDATE files SET is_encrypted = 0, size_bytes = :s, sha256 = :sha, updated_at = datetime('now') WHERE id = :id
            ")->execute([':s' => $newSize, ':sha' => $sha256, ':id' => $fileId]);
            jsonResponse(['ok' => true, 'size_bytes' => $newSize]);

        case 'vault_encrypt_folder':
            $folderId = (int)($input['folder_id'] ?? 0);
            $password = (string)($input['password'] ?? '');
            $res      = FileManager::encryptFolderTree($userId, $folderId, $password);
            jsonResponse(['ok' => true] + $res);

        case 'vault_encrypt_items':
            $items    = is_array($input['items'] ?? null) ? $input['items'] : [];
            $password = (string)($input['password'] ?? '');
            $count    = FileManager::encryptItemsBatch($userId, $items, $password);
            jsonResponse(['ok' => true, 'encrypted_count' => $count]);

        case 'vault_decrypt_folder':
            Auth::assertNotRateLimited('vault');
            $folderId = (int)($input['folder_id'] ?? 0);
            $password = (string)($input['password'] ?? '');
            try {
                $res = FileManager::decryptFolderTree($userId, $folderId, $password);
            } catch (Throwable $ve) {
                Auth::recordFailedAttempt('vault', 10, 900, 900);
                throw $ve;
            }
            Auth::clearRateLimit('vault');
            jsonResponse(['ok' => true] + $res);

        case 'item_shares':
            $itemType = (string)($_GET['item_type'] ?? '');
            $itemId   = (int)($_GET['item_id'] ?? 0);
            $data     = ShareManager::getItemShares($userId, $itemType, $itemId);
            jsonResponse(['ok' => true] + $data);

        case 'my_shares':
            $data = ShareManager::listMyOutboundShares($userId);
            jsonResponse(['ok' => true] + $data);

        case 'create_public_share':
            $res = ShareManager::createPublicShare(
                $userId,
                (string)($input['item_type'] ?? ''),
                (int)($input['item_id'] ?? 0),
                (string)($input['mode'] ?? 'download'),
                isset($input['password']) ? (string)$input['password'] : null,
                isset($input['expires_at']) ? (string)$input['expires_at'] : null,
                isset($input['max_downloads']) && $input['max_downloads'] !== '' ? (int)$input['max_downloads'] : null,
                (string)($input['label'] ?? '')
            );
            jsonResponse(['ok' => true] + $res);

        case 'delete_public_share':
            ShareManager::deletePublicShare($userId, (int)($input['share_id'] ?? 0));
            jsonResponse(['ok' => true]);

        case 'set_user_share':
            ShareManager::setUserShare(
                $userId,
                (string)($input['item_type'] ?? ''),
                (int)($input['item_id'] ?? 0),
                (int)($input['target_user_id'] ?? 0),
                (string)($input['permission'] ?? 'read')
            );
            jsonResponse(['ok' => true]);

        case 'delete_user_share':
            ShareManager::deleteUserShare($userId, (int)($input['share_id'] ?? 0));
            jsonResponse(['ok' => true]);

        case 'set_group_share':
            ShareManager::setGroupShare(
                $userId,
                (string)($input['item_type'] ?? ''),
                (int)($input['item_id'] ?? 0),
                (int)($input['target_group_id'] ?? 0),
                (string)($input['permission'] ?? 'read')
            );
            jsonResponse(['ok' => true]);

        case 'delete_group_share':
            ShareManager::deleteGroupShare($userId, (int)($input['share_id'] ?? 0));
            jsonResponse(['ok' => true]);

        case 'change_password':
            UserManager::changeOwnPassword(
                $userId,
                (string)($input['current_password'] ?? ''),
                (string)($input['new_password'] ?? ''),
                (string)($input['display_name'] ?? ''),
                (string)($input['email'] ?? '')
            );
            jsonResponse(['ok' => true, 'user' => Auth::currentUser()]);

        case '2fa_status':
            $curr = Auth::currentUser();
            jsonResponse([
                'ok'                   => true,
                'email'                => $curr['email'] ?? '',
                'totp_enabled'         => !empty($curr['totp_enabled']),
                'email_2fa_enabled'    => !empty($curr['email_2fa_enabled']),
                'passkey_2fa_enabled'  => !empty($curr['passkey_2fa_enabled']),
                'passkeys'             => TwoFactor::listPasskeys($userId),
            ]);

        case 'totp_setup':
            $secret  = TwoFactor::generateTotpSecret(32);
            $_SESSION['pending_totp_secret'] = $secret;
            $appName = Database::getSettings()['app_name'] ?? 'PureCloud';
            $uri     = TwoFactor::buildOtpAuthUri($appName, $user['username'], $secret);
            $qrSvg   = TwoFactor::renderQrCodeSvg($uri, 180);
            jsonResponse([
                'ok'     => true,
                'secret' => $secret,
                'uri'    => $uri,
                'qr_svg' => $qrSvg,
            ]);

        case 'totp_enable':
            $secret = (string)($_SESSION['pending_totp_secret'] ?? '');
            $code   = (string)($input['code'] ?? '');
            if ($secret === '') {
                throw new RuntimeException('Bitte starten Sie zuerst die TOTP-Einrichtung.');
            }
            if (!TwoFactor::verifyTotpCode($secret, $code)) {
                throw new RuntimeException('Der eingegebene 6-stellige Code ist ungültig. Bitte prüfen Sie die Uhrzeit Ihres Smartphones.');
            }
            unset($_SESSION['pending_totp_secret']);
            Database::get()->prepare('UPDATE users SET totp_secret = :s, totp_enabled = 1 WHERE id = :id')
                ->execute([':s' => $secret, ':id' => $userId]);
            jsonResponse(['ok' => true, 'user' => Auth::currentUser()]);

        case 'totp_disable':
            Database::get()->prepare("UPDATE users SET totp_secret = '', totp_enabled = 0 WHERE id = :id")
                ->execute([':id' => $userId]);
            jsonResponse(['ok' => true, 'user' => Auth::currentUser()]);

        case 'email_2fa_setup':
            $curr = Auth::currentUser();
            if (trim((string)($curr['email'] ?? '')) === '') {
                throw new RuntimeException('Bitte hinterlegen Sie zuerst im Reiter „👤 Profil“ Ihre E-Mail-Adresse.');
            }
            $masked = TwoFactor::sendEmailCode($userId);
            jsonResponse(['ok' => true, 'masked_email' => $masked]);

        case 'email_2fa_enable':
            $code = (string)($input['code'] ?? '');
            if (!TwoFactor::verifyEmailCode($userId, $code)) {
                throw new RuntimeException('Ungültiger oder abgelaufener 6-stelliger E-Mail-Code.');
            }
            Database::get()->prepare('UPDATE users SET email_2fa_enabled = 1 WHERE id = :id')
                ->execute([':id' => $userId]);
            jsonResponse(['ok' => true, 'user' => Auth::currentUser()]);

        case 'email_2fa_disable':
            Database::get()->prepare('UPDATE users SET email_2fa_enabled = 0 WHERE id = :id')
                ->execute([':id' => $userId]);
            jsonResponse(['ok' => true, 'user' => Auth::currentUser()]);

        case 'toggle_2fa_flags':
            $passkey2fa = !empty($input['passkey_2fa_enabled']) ? 1 : 0;
            $curr       = Auth::currentUser();
            if ($passkey2fa === 1 && (int)($curr['passkey_count'] ?? 0) === 0) {
                throw new RuntimeException('Bitte registrieren Sie zuerst mindestens einen Passkey / Biometrie-Schlüssel.');
            }
            Database::get()->prepare('UPDATE users SET passkey_2fa_enabled = :p WHERE id = :id')
                ->execute([':p' => $passkey2fa, ':id' => $userId]);
            jsonResponse(['ok' => true, 'user' => Auth::currentUser()]);

        case 'passkey_register_options':
            $opts = TwoFactor::getPasskeyRegisterOptions($user);
            jsonResponse(['ok' => true] + $opts);

        case 'passkey_register_verify':
            $pk = TwoFactor::registerPasskey($userId, $input);
            jsonResponse(['ok' => true, 'passkey' => $pk, 'user' => Auth::currentUser()]);

        case 'passkey_delete':
            TwoFactor::deletePasskey($userId, (int)($input['passkey_id'] ?? 0));
            $curr = Auth::currentUser();
            if ((int)($curr['passkey_count'] ?? 0) === 0) {
                Database::get()->prepare('UPDATE users SET passkey_2fa_enabled = 0 WHERE id = :id')
                    ->execute([':id' => $userId]);
            }
            jsonResponse(['ok' => true, 'user' => Auth::currentUser()]);

        case 'generate_webdav_token':
            $parts = [];
            for ($i = 0; $i < 4; $i++) {
                $parts[] = bin2hex(random_bytes(2));
            }
            $appPass = implode('-', $parts);
            Database::get()->prepare('UPDATE users SET webdav_token_hash = :h WHERE id = :id')
                ->execute([':h' => password_hash($appPass, PASSWORD_DEFAULT), ':id' => $userId]);
            jsonResponse(['ok' => true, 'app_password' => $appPass, 'user' => Auth::currentUser()]);

        case 'revoke_webdav_token':
            Database::get()->prepare('UPDATE users SET webdav_token_hash = NULL WHERE id = :id')
                ->execute([':id' => $userId]);
            jsonResponse(['ok' => true, 'user' => Auth::currentUser()]);

        case 'calendar_list':
            $month = (string)($_GET['month'] ?? '');
            $data  = CalendarManager::listEvents($userId, $user['role'] === 'admin', !empty($user['perm_calendar']), $month);
            jsonResponse(['ok' => true] + $data);

        case 'calendar_save':
            if (empty($user['perm_calendar'])) {
                throw new RuntimeException('Keine Berechtigung zum Eintragen oder Bearbeiten von Kalendereinträgen.');
            }
            $evId = isset($input['id']) && $input['id'] !== '' && $input['id'] !== null ? (int)$input['id'] : null;
            $savedId = CalendarManager::saveEvent($userId, true, $evId, $input);
            jsonResponse(['ok' => true, 'id' => $savedId]);

        case 'calendar_delete':
            if (empty($user['perm_calendar'])) {
                throw new RuntimeException('Keine Berechtigung zum Löschen von Kalendereinträgen.');
            }
            CalendarManager::deleteEvent($userId, true, (int)($input['id'] ?? 0));
            jsonResponse(['ok' => true]);

        case 'calendar_ics':
            CalendarManager::streamEventIcs($userId, $user['role'] === 'admin', (int)($_GET['id'] ?? 0));

        case 'calendar_year':
            $year = (int)($_GET['year'] ?? date('Y'));
            $data = CalendarManager::listYearEvents($userId, $user['role'] === 'admin', !empty($user['perm_calendar']), $year);
            jsonResponse(['ok' => true] + $data);

        case 'calendar_ics_all':
            CalendarManager::streamAllEventsIcs($userId, $user['role'] === 'admin');

        case 'members_list':
            if (empty($user['perm_members'])) {
                throw new RuntimeException('Keine Berechtigung für die Mitgliederverwaltung.');
            }
            jsonResponse(['ok' => true] + MemberManager::listMembers());

        case 'members_save':
            if (empty($user['perm_members'])) {
                throw new RuntimeException('Keine Berechtigung für die Mitgliederverwaltung.');
            }
            $mId = isset($input['id']) && $input['id'] !== '' && $input['id'] !== null ? (int)$input['id'] : null;
            $savedMid = MemberManager::saveMember($mId, $input);
            jsonResponse(['ok' => true, 'id' => $savedMid]);

        case 'members_delete':
            if (empty($user['perm_members'])) {
                throw new RuntimeException('Keine Berechtigung für die Mitgliederverwaltung.');
            }
            MemberManager::deleteMember((int)($input['id'] ?? 0));
            jsonResponse(['ok' => true]);

        case 'members_export_csv':
            if (empty($user['perm_members'])) {
                throw new RuntimeException('Keine Berechtigung für die Mitgliederverwaltung.');
            }
            MemberManager::streamCsvExport();

        case 'members_import_csv':
            if (empty($user['perm_members'])) {
                throw new RuntimeException('Keine Berechtigung für die Mitgliederverwaltung.');
            }
            $csvText = (string)($input['csv'] ?? '');
            $imported = MemberManager::importCsv($csvText);
            jsonResponse(['ok' => true, 'imported' => $imported]);

        case 'admin_users':
            if (empty($user['has_admin_panel'])) {
                throw new RuntimeException('Keine Administrationsberechtigung.');
            }
            jsonResponse([
                'ok'            => true,
                'users'         => (!empty($user['perm_users']) || !empty($user['perm_groups'])) ? UserManager::listUsers() : [],
                'groups'        => (!empty($user['perm_groups']) || !empty($user['perm_group_folders'])) ? UserManager::listGroups() : [],
                'group_folders' => !empty($user['perm_group_folders']) ? UserManager::listGroupFolders() : [],
                'settings'      => Database::getSettings(),
            ]);

        case 'admin_save_group_folder':
            if (empty($user['perm_group_folders'])) {
                throw new RuntimeException('Keine Berechtigung zum Verwalten von Gruppenordnern.');
            }
            $fid = UserManager::saveGroupFolder(
                $userId,
                isset($input['id']) && $input['id'] !== '' ? (int)$input['id'] : null,
                (string)($input['name'] ?? ''),
                is_array($input['group_ids'] ?? null) ? $input['group_ids'] : [],
                (string)($input['permission'] ?? 'write')
            );
            jsonResponse(['ok' => true, 'id' => $fid]);

        case 'admin_delete_group_folder':
            if (empty($user['perm_group_folders'])) {
                throw new RuntimeException('Keine Berechtigung zum Verwalten von Gruppenordnern.');
            }
            UserManager::deleteGroupFolder((int)($input['id'] ?? 0));
            jsonResponse(['ok' => true]);

        case 'admin_save_group':
            if (empty($user['perm_groups'])) {
                throw new RuntimeException('Keine Berechtigung zum Verwalten von Benutzergruppen.');
            }
            $gid = UserManager::saveGroup(
                isset($input['id']) && $input['id'] !== '' ? (int)$input['id'] : null,
                (string)($input['name'] ?? ''),
                is_array($input['member_ids'] ?? null) ? $input['member_ids'] : []
            );
            jsonResponse(['ok' => true, 'id' => $gid]);

        case 'admin_delete_group':
            if (empty($user['perm_groups'])) {
                throw new RuntimeException('Keine Berechtigung zum Verwalten von Benutzergruppen.');
            }
            UserManager::deleteGroup((int)($input['id'] ?? 0));
            jsonResponse(['ok' => true]);

        case 'admin_create_user':
            if (empty($user['perm_users'])) {
                throw new RuntimeException('Keine Berechtigung zum Verwalten von Benutzern.');
            }
            $id = UserManager::createUser(
                (string)($input['username'] ?? ''),
                (string)($input['display_name'] ?? ''),
                (string)($input['password'] ?? ''),
                (string)($input['role'] ?? 'user'),
                (int)($input['quota_mb'] ?? 5120),
                $user['role'] === 'admin',
                !empty($input['perm_users']),
                !empty($input['perm_groups']),
                !empty($input['perm_group_folders']),
                !empty($input['perm_calendar']),
                !empty($input['perm_members'])
            );
            jsonResponse(['ok' => true, 'id' => $id]);

        case 'admin_update_user':
            if (empty($user['perm_users'])) {
                throw new RuntimeException('Keine Berechtigung zum Verwalten von Benutzern.');
            }
            UserManager::updateUser(
                $userId,
                (int)($input['id'] ?? 0),
                (string)($input['display_name'] ?? ''),
                (string)($input['role'] ?? 'user'),
                (int)($input['quota_mb'] ?? 5120),
                !empty($input['is_active']),
                isset($input['new_password']) ? (string)$input['new_password'] : null,
                $user['role'] === 'admin',
                isset($input['perm_users']) ? !empty($input['perm_users']) : null,
                isset($input['perm_groups']) ? !empty($input['perm_groups']) : null,
                isset($input['perm_group_folders']) ? !empty($input['perm_group_folders']) : null,
                isset($input['perm_calendar']) ? !empty($input['perm_calendar']) : null,
                isset($input['perm_members']) ? !empty($input['perm_members']) : null
            );
            jsonResponse(['ok' => true]);

        case 'admin_delete_user':
            if (empty($user['perm_users'])) {
                throw new RuntimeException('Keine Berechtigung zum Verwalten von Benutzern.');
            }
            UserManager::deleteUser($userId, (int)($input['id'] ?? 0), $user['role'] === 'admin');
            jsonResponse(['ok' => true]);

        case 'admin_save_settings':
            if ($user['role'] !== 'admin') {
                throw new RuntimeException('Nur für Voll-Administratoren.');
            }
            if (isset($input['app_name']) && trim((string)$input['app_name']) !== '') {
                Database::setSetting('app_name', trim((string)$input['app_name']));
            }
            if (isset($input['enable_webdav'])) {
                Database::setSetting('enable_webdav', !empty($input['enable_webdav']) ? '1' : '0');
            }
            jsonResponse(['ok' => true, 'settings' => Database::getSettings()]);

        default:
            jsonResponse(['ok' => false, 'error' => 'Unbekannte Aktion: ' . $action], 400);
    }
} catch (Throwable $e) {
    jsonResponse(['ok' => false, 'error' => $e->getMessage()], 400);
}
