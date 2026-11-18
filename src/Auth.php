<?php
declare(strict_types=1);

namespace Cloud;

use PDO;
use RuntimeException;

class Auth
{
    public const REMEMBER_COOKIE = 'cloud_remember';
    public const REMEMBER_DAYS   = 30;

    public static function sendSecurityHeaders(): void
    {
        header('X-Content-Type-Options: nosniff');
        header('X-Frame-Options: SAMEORIGIN');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
    }

    public static function startSession(): void
    {
        if (session_status() === PHP_SESSION_NONE) {
            @ini_set('session.use_strict_mode', '1');
            @ini_set('session.use_only_cookies', '1');
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            session_start();
        }
    }

    public static function clientIp(): string
    {
        return trim((string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'));
    }

    /**
     * Checks if a rate-limit key (e.g. "login:1.2.3.4") is currently locked out.
     * Throws RuntimeException if locked.
     */
    public static function assertNotRateLimited(string $scope): void
    {
        $key = $scope . ':' . self::clientIp();
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT locked_until FROM rate_limits WHERE rate_key = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $lockedUntil = (int)$stmt->fetchColumn();
        if ($lockedUntil > time()) {
            $waitMin = max(1, (int)ceil(($lockedUntil - time()) / 60));
            throw new RuntimeException("Zu viele Fehlversuche. Bitte warten Sie ca. {$waitMin} Minute(n).");
        }
    }

    public static function recordFailedAttempt(string $scope, int $maxAttempts = 8, int $windowSec = 900, int $lockSec = 900): void
    {
        $key = $scope . ':' . self::clientIp();
        $now = time();
        $pdo = Database::get();

        // Periodically clean up expired entries
        if (random_int(1, 25) === 1) {
            $pdo->prepare('DELETE FROM rate_limits WHERE window_start < :old AND locked_until < :now')
                ->execute([':old' => $now - 3600, ':now' => $now]);
        }

        $stmt = $pdo->prepare('SELECT attempts, window_start FROM rate_limits WHERE rate_key = :k LIMIT 1');
        $stmt->execute([':k' => $key]);
        $row = $stmt->fetch();

        if (!$row || ($now - (int)$row['window_start']) > $windowSec) {
            $attempts    = 1;
            $windowStart = $now;
        } else {
            $attempts    = (int)$row['attempts'] + 1;
            $windowStart = (int)$row['window_start'];
        }

        $lockedUntil = ($attempts >= $maxAttempts) ? ($now + $lockSec) : 0;

        $up = $pdo->prepare('
            INSERT INTO rate_limits (rate_key, attempts, window_start, locked_until)
            VALUES (:k, :a, :w, :l)
            ON CONFLICT(rate_key) DO UPDATE SET
                attempts = excluded.attempts,
                window_start = excluded.window_start,
                locked_until = excluded.locked_until
        ');
        $up->execute([
            ':k' => $key,
            ':a' => $attempts,
            ':w' => $windowStart,
            ':l' => $lockedUntil,
        ]);
    }

    public static function clearRateLimit(string $scope): void
    {
        $key = $scope . ':' . self::clientIp();
        Database::get()->prepare('DELETE FROM rate_limits WHERE rate_key = :k')->execute([':k' => $key]);
    }

    public static function csrfToken(): string
    {
        self::startSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::startSession();
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function issueRememberToken(int $userId): void
    {
        $rawToken  = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = time() + (self::REMEMBER_DAYS * 86400);

        $pdo = Database::get();
        $pdo->prepare('DELETE FROM remember_tokens WHERE expires_at < :now')->execute([':now' => time()]);
        $pdo->prepare('INSERT INTO remember_tokens (user_id, token_hash, expires_at) VALUES (:uid, :th, :exp)')
            ->execute([':uid' => $userId, ':th' => $tokenHash, ':exp' => $expiresAt]);

        $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');

        setcookie(self::REMEMBER_COOKIE, $rawToken, [
            'expires'  => $expiresAt,
            'path'     => '/',
            'secure'   => $isHttps,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }

    public static function clearRememberToken(): void
    {
        if (!empty($_COOKIE[self::REMEMBER_COOKIE])) {
            $tokenHash = hash('sha256', (string)$_COOKIE[self::REMEMBER_COOKIE]);
            Database::get()->prepare('DELETE FROM remember_tokens WHERE token_hash = :th')
                ->execute([':th' => $tokenHash]);
            $isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
            setcookie(self::REMEMBER_COOKIE, '', [
                'expires'  => time() - 42000,
                'path'     => '/',
                'secure'   => $isHttps,
                'httponly' => true,
                'samesite' => 'Lax',
            ]);
            unset($_COOKIE[self::REMEMBER_COOKIE]);
        }
    }

    public static function currentUser(): ?array
    {
        self::startSession();
        $pdo = Database::get();

        if (empty($_SESSION['user_id']) && !empty($_COOKIE[self::REMEMBER_COOKIE])) {
            $tokenHash = hash('sha256', (string)$_COOKIE[self::REMEMBER_COOKIE]);
            $rStmt = $pdo->prepare('
                SELECT rt.id AS rt_id, rt.user_id
                FROM remember_tokens rt
                JOIN users u ON u.id = rt.user_id
                WHERE rt.token_hash = :th AND rt.expires_at > :now AND u.is_active = 1
                LIMIT 1
            ');
            $rStmt->execute([':th' => $tokenHash, ':now' => time()]);
            $rtRow = $rStmt->fetch();
            if ($rtRow) {
                session_regenerate_id(true);
                $_SESSION['user_id'] = (int)$rtRow['user_id'];
            } else {
                self::clearRememberToken();
            }
        }

        if (empty($_SESSION['user_id'])) {
            return null;
        }
        $stmt = $pdo->prepare('
            SELECT id, username, display_name, email, role, quota_bytes, is_active,
                   totp_enabled, email_2fa_enabled, passkey_2fa_enabled,
                   perm_users, perm_groups, perm_group_folders, perm_calendar,
                   (webdav_token_hash IS NOT NULL AND webdav_token_hash != \'\') AS has_webdav_token,
                   created_at,
                   (SELECT COUNT(*) FROM user_passkeys pk WHERE pk.user_id = users.id) AS passkey_count
            FROM users
            WHERE id = :id AND is_active = 1
            LIMIT 1
        ');
        $stmt->execute([':id' => (int)$_SESSION['user_id']]);
        $user = $stmt->fetch();
        if (!$user) {
            unset($_SESSION['user_id']);
            return null;
        }
        $isAdmin = $user['role'] === 'admin';
        $user['id']                  = (int)$user['id'];
        $user['quota_bytes']         = (int)$user['quota_bytes'];
        $user['used_bytes']          = self::getUsedBytes($user['id']);
        $user['totp_enabled']        = (int)$user['totp_enabled'] === 1;
        $user['email_2fa_enabled']   = (int)$user['email_2fa_enabled'] === 1;
        $user['passkey_2fa_enabled'] = (int)$user['passkey_2fa_enabled'] === 1;
        $user['has_webdav_token']    = (int)$user['has_webdav_token'] === 1;
        $user['passkey_count']       = (int)$user['passkey_count'];
        $user['perm_users']          = $isAdmin || (int)($user['perm_users'] ?? 0) === 1;
        $user['perm_groups']         = $isAdmin || (int)($user['perm_groups'] ?? 0) === 1;
        $user['perm_group_folders']  = $isAdmin || (int)($user['perm_group_folders'] ?? 0) === 1;
        $user['perm_calendar']       = $isAdmin || (int)($user['perm_calendar'] ?? 0) === 1;
        $user['has_admin_panel']     = $isAdmin || $user['perm_users'] || $user['perm_groups'] || $user['perm_group_folders'];
        return $user;
    }

    public static function completeLoginForUserId(int $userId, ?bool $remember = null): array
    {
        self::startSession();
        if ($remember === null) {
            $remember = !empty($_SESSION['pending_2fa_remember']);
        }
        session_regenerate_id(true);
        unset($_SESSION['pending_2fa_user_id'], $_SESSION['pending_2fa_attempts'], $_SESSION['pending_2fa_remember']);
        self::clearRateLimit('login');
        self::clearRateLimit('2fa');
        $_SESSION['user_id'] = $userId;
        if ($remember) {
            self::issueRememberToken($userId);
        }
        return self::currentUser() ?? [];
    }

    public static function login(string $username, string $password, bool $remember = false): ?array
    {
        self::assertNotRateLimited('login');

        $pdo = Database::get();
        $stmt = $pdo->prepare('
            SELECT *, (SELECT COUNT(*) FROM user_passkeys pk WHERE pk.user_id = users.id) AS passkey_count
            FROM users
            WHERE username = :u AND is_active = 1
            LIMIT 1
        ');
        $stmt->execute([':u' => trim($username)]);
        $user = $stmt->fetch();
        if (!$user || !password_verify($password, $user['password_hash'])) {
            self::recordFailedAttempt('login', 8, 900, 900);
            return null;
        }

        $uid          = (int)$user['id'];
        $totpActive   = (int)$user['totp_enabled'] === 1 && trim((string)$user['totp_secret']) !== '';
        $emailActive  = (int)$user['email_2fa_enabled'] === 1 && trim((string)$user['email']) !== '';
        $passkeyCount = (int)$user['passkey_count'];
        $pk2faActive  = (int)$user['passkey_2fa_enabled'] === 1 && $passkeyCount > 0;

        if ($totpActive || $emailActive || $pk2faActive) {
            self::startSession();
            $_SESSION['pending_2fa_user_id']  = $uid;
            $_SESSION['pending_2fa_attempts'] = 0;
            $_SESSION['pending_2fa_remember'] = $remember;

            $methods = [];
            if ($totpActive) {
                $methods[] = 'totp';
            }
            if ($passkeyCount > 0) {
                $methods[] = 'passkey';
            }
            if ($emailActive) {
                $methods[] = 'email';
            }

            $maskedEmail = trim((string)$user['email']) !== '' ? TwoFactor::maskEmail((string)$user['email']) : '';
            if (($methods[0] ?? '') === 'email') {
                try {
                    $maskedEmail = TwoFactor::sendEmailCode($uid);
                } catch (\Throwable) {
                }
            }

            return [
                'two_factor_required' => true,
                'methods'             => $methods,
                'default_method'      => $methods[0] ?? 'totp',
                'masked_email'        => $maskedEmail,
            ];
        }

        return [
            'two_factor_required' => false,
            'user'                => self::completeLoginForUserId($uid, $remember),
        ];
    }

    public static function verifyBasicAuth(string $username, string $password): ?array
    {
        try {
            self::assertNotRateLimited('webdav');
        } catch (\Throwable) {
            return null;
        }

        $pdo = Database::get();
        $stmt = $pdo->prepare('
            SELECT id, username, display_name, role, quota_bytes, password_hash, webdav_token_hash,
                   totp_enabled, totp_secret, email_2fa_enabled, passkey_2fa_enabled, is_active
            FROM users
            WHERE username = :u AND is_active = 1
            LIMIT 1
        ');
        $stmt->execute([':u' => trim($username)]);
        $user = $stmt->fetch();
        if (!$user) {
            self::recordFailedAttempt('webdav', 10, 900, 900);
            return null;
        }

        $has2fa = ((int)$user['totp_enabled'] === 1 && $user['totp_secret'] !== '')
            || (int)$user['email_2fa_enabled'] === 1
            || (int)$user['passkey_2fa_enabled'] === 1;

        $valid = false;
        // 1. Check dedicated WebDAV App-Password if set
        if (!empty($user['webdav_token_hash']) && password_verify($password, (string)$user['webdav_token_hash'])) {
            $valid = true;
        } elseif (!$has2fa && password_verify($password, (string)$user['password_hash'])) {
            // 2. If 2FA is not enabled, allow standard account password
            $valid = true;
        } elseif ((int)$user['totp_enabled'] === 1 && strlen($password) > 6) {
            // 3. If TOTP 2FA is active, also allow "AccountPassword" + "6-digit TOTP code" appended
            $basePw = substr($password, 0, -6);
            $totpCode = substr($password, -6);
            if (password_verify($basePw, (string)$user['password_hash']) && TwoFactor::verifyTotpCode((string)$user['totp_secret'], $totpCode)) {
                $valid = true;
            }
        }

        if (!$valid) {
            self::recordFailedAttempt('webdav', 10, 900, 900);
            return null;
        }

        self::clearRateLimit('webdav');
        unset($user['password_hash'], $user['webdav_token_hash'], $user['totp_secret']);
        $user['id']          = (int)$user['id'];
        $user['quota_bytes'] = (int)$user['quota_bytes'];
        $user['used_bytes']  = self::getUsedBytes($user['id']);
        return $user;
    }

    public static function logout(): void
    {
        self::startSession();
        self::clearRememberToken();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', (bool)$params['secure'], (bool)$params['httponly']);
        }
        session_destroy();
    }

    public static function getUsedBytes(int $userId): int
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT COALESCE(SUM(size_bytes), 0) FROM files WHERE owner_id = :uid');
        $stmt->execute([':uid' => $userId]);
        return (int)$stmt->fetchColumn();
    }

    public static function canFitBytes(int $userId, int $additionalBytes): bool
    {
        if ($additionalBytes <= 0) {
            return true;
        }
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT quota_bytes FROM users WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $userId]);
        $quota = (int)$stmt->fetchColumn();
        if ($quota <= 0) {
            return true;
        }
        $used = self::getUsedBytes($userId);
        return ($used + $additionalBytes) <= $quota;
    }
}
