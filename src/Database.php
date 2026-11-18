<?php
declare(strict_types=1);

namespace Cloud;

use PDO;

class Database
{
    private static ?PDO $pdo = null;

    public const STORAGE_DIR = __DIR__ . '/../storage';
    public const FILES_DIR   = self::STORAGE_DIR . '/files';
    public const CHUNKS_DIR  = self::STORAGE_DIR . '/chunks';
    public const THUMBS_DIR  = self::STORAGE_DIR . '/thumbs';
    public const DB_FILE     = self::STORAGE_DIR . '/database.sqlite';

    public static function ensureDirectories(): void
    {
        foreach ([self::STORAGE_DIR, self::FILES_DIR, self::CHUNKS_DIR, self::THUMBS_DIR] as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0750, true);
            }
        }
    }

    public static function get(): PDO
    {
        if (self::$pdo !== null) {
            return self::$pdo;
        }

        self::ensureDirectories();

        $isNew = !file_exists(self::DB_FILE);

        $pdo = new PDO('sqlite:' . self::DB_FILE, null, null, [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]);

        $pdo->exec('PRAGMA journal_mode = WAL;');
        $pdo->exec('PRAGMA synchronous = NORMAL;');
        $pdo->exec('PRAGMA foreign_keys = ON;');
        $pdo->exec('PRAGMA busy_timeout = 5000;');

        self::$pdo = $pdo;
        self::migrate($pdo, $isNew);

        return $pdo;
    }

    private static function migrate(PDO $pdo, bool $isNew): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                key TEXT PRIMARY KEY,
                value TEXT NOT NULL
            );

            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                username TEXT NOT NULL UNIQUE COLLATE NOCASE,
                display_name TEXT NOT NULL,
                password_hash TEXT NOT NULL,
                role TEXT NOT NULL DEFAULT 'user',
                quota_bytes INTEGER NOT NULL DEFAULT 5368709120,
                is_active INTEGER NOT NULL DEFAULT 1,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS folders (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_id INTEGER NOT NULL,
                parent_id INTEGER NULL,
                name TEXT NOT NULL,
                is_group_folder INTEGER NOT NULL DEFAULT 0,
                deleted_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (parent_id) REFERENCES folders(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_folders_owner_parent ON folders(owner_id, parent_id, deleted_at);

            CREATE TABLE IF NOT EXISTS files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_id INTEGER NOT NULL,
                folder_id INTEGER NULL,
                name TEXT NOT NULL,
                storage_name TEXT NOT NULL UNIQUE,
                mime_type TEXT NOT NULL DEFAULT 'application/octet-stream',
                size_bytes INTEGER NOT NULL DEFAULT 0,
                sha256 TEXT NOT NULL DEFAULT '',
                is_encrypted INTEGER NOT NULL DEFAULT 0,
                deleted_at TEXT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                updated_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (folder_id) REFERENCES folders(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_files_owner_folder ON files(owner_id, folder_id, deleted_at);

            CREATE TABLE IF NOT EXISTS public_shares (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_id INTEGER NOT NULL,
                item_type TEXT NOT NULL CHECK(item_type IN ('file', 'folder')),
                item_id INTEGER NOT NULL,
                token TEXT NOT NULL UNIQUE,
                label TEXT NOT NULL DEFAULT '',
                mode TEXT NOT NULL DEFAULT 'download' CHECK(mode IN ('download', 'upload', 'readwrite')),
                password_hash TEXT NULL,
                expires_at TEXT NULL,
                max_downloads INTEGER NULL,
                download_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_public_shares_token ON public_shares(token);
            CREATE INDEX IF NOT EXISTS idx_public_shares_item ON public_shares(item_type, item_id);

            CREATE TABLE IF NOT EXISTS user_shares (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_id INTEGER NOT NULL,
                target_user_id INTEGER NOT NULL,
                item_type TEXT NOT NULL CHECK(item_type IN ('file', 'folder')),
                item_id INTEGER NOT NULL,
                permission TEXT NOT NULL DEFAULT 'read' CHECK(permission IN ('read', 'write')),
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                 UNIQUE(target_user_id, item_type, item_id),
                FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_user_shares_target ON user_shares(target_user_id);
            CREATE INDEX IF NOT EXISTS idx_user_shares_item ON user_shares(item_type, item_id);

            CREATE TABLE IF NOT EXISTS webdav_locks (
                token TEXT PRIMARY KEY,
                path TEXT NOT NULL,
                owner TEXT NOT NULL DEFAULT '',
                expires_at INTEGER NOT NULL
            );

            CREATE TABLE IF NOT EXISTS user_passkeys (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                credential_id TEXT NOT NULL UNIQUE,
                public_key_pem TEXT NOT NULL,
                cose_alg INTEGER NOT NULL DEFAULT -7,
                device_name TEXT NOT NULL DEFAULT 'Passkey',
                sign_count INTEGER NOT NULL DEFAULT 0,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                last_used_at TEXT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_user_passkeys_uid ON user_passkeys(user_id);

            CREATE TABLE IF NOT EXISTS rate_limits (
                rate_key TEXT PRIMARY KEY,
                attempts INTEGER NOT NULL DEFAULT 0,
                window_start INTEGER NOT NULL DEFAULT 0,
                locked_until INTEGER NOT NULL DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS remember_tokens (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id INTEGER NOT NULL,
                token_hash TEXT NOT NULL UNIQUE,
                expires_at INTEGER NOT NULL,
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_remember_tokens_hash ON remember_tokens(token_hash);

            CREATE TABLE IF NOT EXISTS groups (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL UNIQUE COLLATE NOCASE,
                created_at TEXT NOT NULL DEFAULT (datetime('now'))
            );

            CREATE TABLE IF NOT EXISTS group_members (
                group_id INTEGER NOT NULL,
                user_id INTEGER NOT NULL,
                PRIMARY KEY (group_id, user_id),
                FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_group_members_user ON group_members(user_id);

            CREATE TABLE IF NOT EXISTS group_shares (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                owner_id INTEGER NOT NULL,
                target_group_id INTEGER NOT NULL,
                item_type TEXT NOT NULL CHECK(item_type IN ('file', 'folder')),
                item_id INTEGER NOT NULL,
                permission TEXT NOT NULL DEFAULT 'read' CHECK(permission IN ('read', 'write')),
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                UNIQUE(target_group_id, item_type, item_id),
                FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (target_group_id) REFERENCES groups(id) ON DELETE CASCADE
            );

            CREATE INDEX IF NOT EXISTS idx_group_shares_group ON group_shares(target_group_id);
            CREATE INDEX IF NOT EXISTS idx_group_shares_item ON group_shares(item_type, item_id);

            CREATE TABLE IF NOT EXISTS calendar_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                creator_id INTEGER NOT NULL,
                group_id INTEGER NULL,
                title TEXT NOT NULL,
                start_at TEXT NOT NULL,
                end_at TEXT NULL,
                all_day INTEGER NOT NULL DEFAULT 0,
                location TEXT NOT NULL DEFAULT '',
                description TEXT NOT NULL DEFAULT '',
                color TEXT NOT NULL DEFAULT '#3b82f6',
                created_at TEXT NOT NULL DEFAULT (datetime('now')),
                FOREIGN KEY (creator_id) REFERENCES users(id) ON DELETE CASCADE,
                FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE SET NULL
            );

            CREATE INDEX IF NOT EXISTS idx_calendar_events_start ON calendar_events(start_at);
        ");

        // Auto-migrate 2FA & delegated permission columns on existing users table
        $existingCols = [];
        foreach ($pdo->query("PRAGMA table_info(users)")->fetchAll() as $col) {
            $existingCols[$col['name']] = true;
        }
        $addCols = [
            'email'                => "TEXT NOT NULL DEFAULT ''",
            'totp_secret'          => "TEXT NOT NULL DEFAULT ''",
            'totp_enabled'         => "INTEGER NOT NULL DEFAULT 0",
            'email_2fa_enabled'    => "INTEGER NOT NULL DEFAULT 0",
            'passkey_2fa_enabled'  => "INTEGER NOT NULL DEFAULT 0",
            'email_code_hash'      => "TEXT NULL",
            'email_code_expires'   => "INTEGER NOT NULL DEFAULT 0",
            'webdav_token_hash'    => "TEXT NULL",
            'perm_users'           => "INTEGER NOT NULL DEFAULT 0",
            'perm_groups'          => "INTEGER NOT NULL DEFAULT 0",
            'perm_group_folders'   => "INTEGER NOT NULL DEFAULT 0",
            'perm_calendar'        => "INTEGER NOT NULL DEFAULT 0",
        ];
        foreach ($addCols as $cName => $cDef) {
            if (!isset($existingCols[$cName])) {
                $pdo->exec("ALTER TABLE users ADD COLUMN $cName $cDef");
            }
        }

        // Auto-migrate is_group_folder column on existing folders table
        $folderCols = [];
        foreach ($pdo->query("PRAGMA table_info(folders)")->fetchAll() as $col) {
            $folderCols[$col['name']] = true;
        }
        if (!isset($folderCols['is_group_folder'])) {
            $pdo->exec("ALTER TABLE folders ADD COLUMN is_group_folder INTEGER NOT NULL DEFAULT 0");
        }

        if ($isNew) {
            $defaults = [
                'app_name'             => 'PureCloud',
                'enable_webdav'        => '1',
                'trash_retention_days' => '30',
                'default_quota_mb'     => '5120',
            ];
            $stmt = $pdo->prepare('INSERT OR IGNORE INTO settings (key, value) VALUES (:k, :v)');
            foreach ($defaults as $k => $v) {
                $stmt->execute([':k' => $k, ':v' => $v]);
            }
        }
    }

    public static function getSettings(): array
    {
        $pdo = self::get();
        $rows = $pdo->query('SELECT key, value FROM settings')->fetchAll();
        $out = [
            'app_name'             => 'PureCloud',
            'enable_webdav'        => '1',
            'trash_retention_days' => '30',
            'default_quota_mb'     => '5120',
        ];
        foreach ($rows as $r) {
            $out[$r['key']] = $r['value'];
        }
        return $out;
    }

    public static function setSetting(string $key, string $value): void
    {
        $pdo = self::get();
        $stmt = $pdo->prepare('INSERT INTO settings (key, value) VALUES (:k, :v) ON CONFLICT(key) DO UPDATE SET value = excluded.value');
        $stmt->execute([':k' => $key, ':v' => $value]);
    }

    public static function hasAnyUser(): bool
    {
        $pdo = self::get();
        return (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0;
    }
}
