<?php
declare(strict_types=1);

namespace Cloud;

use PDO;
use RuntimeException;

class UserManager
{
    public static function createFirstAdmin(string $username, string $displayName, string $password, string $appName = 'PureCloud'): array
    {
        if (Database::hasAnyUser()) {
            throw new RuntimeException('Es wurde bereits ein Administrator-Konto eingerichtet.');
        }

        $username    = self::validateUsername($username);
        $displayName = trim($displayName) !== '' ? trim($displayName) : $username;
        if (strlen($password) < 6) {
            throw new RuntimeException('Das Passwort muss mindestens 6 Zeichen lang sein.');
        }

        $pdo = Database::get();
        if (trim($appName) !== '') {
            Database::setSetting('app_name', trim($appName));
        }

        $stmt = $pdo->prepare("
            INSERT INTO users (username, display_name, password_hash, role, quota_bytes, is_active, created_at)
            VALUES (:u, :dn, :pw, 'admin', 0, 1, datetime('now'))
        ");
        $stmt->execute([
            ':u'  => $username,
            ':dn' => $displayName,
            ':pw' => password_hash($password, PASSWORD_DEFAULT),
        ]);

        $userId = (int)$pdo->lastInsertId();
        self::createDefaultFolders($userId);

        return Auth::completeLoginForUserId($userId);
    }

    public static function createDefaultFolders(int $userId): void
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare("
            INSERT INTO folders (owner_id, parent_id, name, created_at, updated_at)
            VALUES (:uid, NULL, :name, datetime('now'), datetime('now'))
        ");
        foreach (['Dokumente', 'Bilder', 'Videos'] as $folderName) {
            $stmt->execute([
                ':uid'  => $userId,
                ':name' => $folderName,
            ]);
        }
    }

    public static function validateUsername(string $raw): string
    {
        $u = trim($raw);
        if (!preg_match('/^[a-zA-Z0-9._-]{2,40}$/', $u)) {
            throw new RuntimeException('Der Benutzername darf nur Buchstaben, Zahlen, Punkt, Unterstrich oder Bindestrich enthalten (2–40 Zeichen).');
        }
        return $u;
    }

    public static function listUsers(): array
    {
        $pdo = Database::get();
        $rows = $pdo->query("
            SELECT u.id, u.username, u.display_name, u.role, u.quota_bytes, u.is_active, u.created_at,
                   u.perm_users, u.perm_groups, u.perm_group_folders, u.perm_calendar,
                   COALESCE((SELECT SUM(f.size_bytes) FROM files f WHERE f.owner_id = u.id), 0) AS used_bytes,
                   (SELECT COUNT(*) FROM files f WHERE f.owner_id = u.id AND f.deleted_at IS NULL) AS file_count
            FROM users u
            ORDER BY u.id ASC
        ")->fetchAll();

        return array_map(fn($r) => [
            'id'                 => (int)$r['id'],
            'username'           => $r['username'],
            'display_name'       => $r['display_name'],
            'role'               => $r['role'],
            'quota_bytes'        => (int)$r['quota_bytes'],
            'used_bytes'         => (int)$r['used_bytes'],
            'file_count'         => (int)$r['file_count'],
            'is_active'          => (int)$r['is_active'] === 1,
            'perm_users'         => (int)($r['perm_users'] ?? 0) === 1,
            'perm_groups'        => (int)($r['perm_groups'] ?? 0) === 1,
            'perm_group_folders' => (int)($r['perm_group_folders'] ?? 0) === 1,
            'perm_calendar'      => (int)($r['perm_calendar'] ?? 0) === 1,
            'created_at'         => $r['created_at'],
        ], $rows);
    }

    public static function createUser(
        string $username,
        string $displayName,
        string $password,
        string $role,
        int $quotaMb,
        bool $callerIsFullAdmin = true,
        bool $permUsers = false,
        bool $permGroups = false,
        bool $permGroupFolders = false,
        bool $permCalendar = false
    ): int {
        $username    = self::validateUsername($username);
        $displayName = trim($displayName) !== '' ? trim($displayName) : $username;
        if (strlen($password) < 6) {
            throw new RuntimeException('Das Passwort muss mindestens 6 Zeichen lang sein.');
        }
        $role = ($callerIsFullAdmin && $role === 'admin') ? 'admin' : 'user';
        $quotaBytes = $quotaMb > 0 ? ($quotaMb * 1048576) : 0;

        $pdo = Database::get();
        $chk = $pdo->prepare('SELECT id FROM users WHERE username = :u LIMIT 1');
        $chk->execute([':u' => $username]);
        if ($chk->fetchColumn()) {
            throw new RuntimeException('Dieser Benutzername ist bereits vergeben.');
        }

        $stmt = $pdo->prepare("
            INSERT INTO users (username, display_name, password_hash, role, quota_bytes, is_active, perm_users, perm_groups, perm_group_folders, perm_calendar, created_at)
            VALUES (:u, :dn, :pw, :role, :quota, 1, :pu, :pg, :pgf, :pcal, datetime('now'))
        ");
        $stmt->execute([
            ':u'     => $username,
            ':dn'    => $displayName,
            ':pw'    => password_hash($password, PASSWORD_DEFAULT),
            ':role'  => $role,
            ':quota' => $quotaBytes,
            ':pu'    => ($callerIsFullAdmin && $permUsers) ? 1 : 0,
            ':pg'    => ($callerIsFullAdmin && $permGroups) ? 1 : 0,
            ':pgf'   => ($callerIsFullAdmin && $permGroupFolders) ? 1 : 0,
            ':pcal'  => (($callerIsFullAdmin || $permCalendar) && $permCalendar) ? 1 : 0,
        ]);

        $newUserId = (int)$pdo->lastInsertId();
        self::createDefaultFolders($newUserId);

        return $newUserId;
    }

    public static function updateUser(
        int $callerUserId,
        int $targetId,
        string $displayName,
        string $role,
        int $quotaMb,
        bool $isActive,
        ?string $newPassword = null,
        bool $callerIsFullAdmin = true,
        ?bool $permUsers = null,
        ?bool $permGroups = null,
        ?bool $permGroupFolders = null,
        ?bool $permCalendar = null
    ): void {
        $pdo = Database::get();
        $tStmt = $pdo->prepare('SELECT role, perm_users, perm_groups, perm_group_folders, perm_calendar FROM users WHERE id = :id LIMIT 1');
        $tStmt->execute([':id' => $targetId]);
        $targetRow = $tStmt->fetch();
        if (!$targetRow) {
            throw new RuntimeException('Benutzer nicht gefunden.');
        }
        if (!$callerIsFullAdmin && $targetRow['role'] === 'admin') {
            throw new RuntimeException('Delegierte Benutzerverwalter können keine Administratoren bearbeiten.');
        }

        if ($targetId === $callerUserId && $callerIsFullAdmin) {
            $role     = 'admin';
            $isActive = true;
        } elseif ($callerIsFullAdmin) {
            $role = ($role === 'admin') ? 'admin' : 'user';
        } else {
            $role = 'user';
        }

        $quotaBytes = $quotaMb > 0 ? ($quotaMb * 1048576) : 0;
        $displayName = trim($displayName);
        if ($displayName === '') {
            throw new RuntimeException('Anzeigename darf nicht leer sein.');
        }

        $pu   = $callerIsFullAdmin && $permUsers !== null ? ($permUsers ? 1 : 0) : (int)$targetRow['perm_users'];
        $pg   = $callerIsFullAdmin && $permGroups !== null ? ($permGroups ? 1 : 0) : (int)$targetRow['perm_groups'];
        $pgf  = $callerIsFullAdmin && $permGroupFolders !== null ? ($permGroupFolders ? 1 : 0) : (int)$targetRow['perm_group_folders'];
        $pcal = $permCalendar !== null ? ($permCalendar ? 1 : 0) : (int)$targetRow['perm_calendar'];

        $stmt = $pdo->prepare('
            UPDATE users
            SET display_name = :dn, role = :role, quota_bytes = :quota, is_active = :act,
                perm_users = :pu, perm_groups = :pg, perm_group_folders = :pgf, perm_calendar = :pcal
            WHERE id = :id
        ');
        $stmt->execute([
            ':dn'    => $displayName,
            ':role'  => $role,
            ':quota' => $quotaBytes,
            ':act'   => $isActive ? 1 : 0,
            ':pu'    => $pu,
            ':pg'    => $pg,
            ':pgf'   => $pgf,
            ':pcal'  => $pcal,
            ':id'    => $targetId,
        ]);

        if ($newPassword !== null && trim($newPassword) !== '') {
            if (strlen($newPassword) < 6) {
                throw new RuntimeException('Das neue Passwort muss mindestens 6 Zeichen lang sein.');
            }
            $pwStmt = $pdo->prepare('UPDATE users SET password_hash = :pw WHERE id = :id');
            $pwStmt->execute([
                ':pw' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':id' => $targetId,
            ]);
        }
    }

    public static function deleteUser(int $adminUserId, int $targetId, bool $callerIsFullAdmin = true): void
    {
        if ($targetId === $adminUserId) {
            throw new RuntimeException('Sie können Ihr eigenes Konto nicht löschen.');
        }

        $pdo = Database::get();
        if (!$callerIsFullAdmin) {
            $rStmt = $pdo->prepare('SELECT role FROM users WHERE id = :id LIMIT 1');
            $rStmt->execute([':id' => $targetId]);
            if ($rStmt->fetchColumn() === 'admin') {
                throw new RuntimeException('Delegierte Benutzerverwalter können keine Administratoren löschen.');
            }
        }
        // Clean up physical files owned by this user
        $stmt = $pdo->prepare('SELECT storage_name FROM files WHERE owner_id = :uid');
        $stmt->execute([':uid' => $targetId]);
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $sname) {
            FileManager::unlinkPhysicalFile((string)$sname);
        }

        $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $targetId]);
    }

    public static function changeOwnPassword(int $userId, string $currentPassword, string $newPassword, string $displayName = '', string $email = ''): void
    {
        $pdo = Database::get();
        if (trim($displayName) !== '') {
            $pdo->prepare('UPDATE users SET display_name = :dn WHERE id = :id')
                ->execute([':dn' => trim($displayName), ':id' => $userId]);
        }

        $email = trim($email);
        if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Bitte geben Sie eine gültige E-Mail-Adresse ein.');
        }
        $pdo->prepare('UPDATE users SET email = :em WHERE id = :id')
            ->execute([':em' => $email, ':id' => $userId]);

        if (trim($newPassword) !== '') {
            $stmt = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $userId]);
            $hash = $stmt->fetchColumn();
            if (!$hash || !password_verify($currentPassword, (string)$hash)) {
                throw new RuntimeException('Das aktuelle Passwort ist nicht korrekt.');
            }
            if (strlen($newPassword) < 6) {
                throw new RuntimeException('Das neue Passwort muss mindestens 6 Zeichen lang sein.');
            }

            $upd = $pdo->prepare('UPDATE users SET password_hash = :pw WHERE id = :id');
            $upd->execute([
                ':pw' => password_hash($newPassword, PASSWORD_DEFAULT),
                ':id' => $userId,
            ]);
        }
    }

    public static function listGroups(): array
    {
        $pdo = Database::get();
        $groups = $pdo->query("
            SELECT g.id, g.name, g.created_at,
                   (SELECT COUNT(*) FROM group_shares gs WHERE gs.target_group_id = g.id) AS share_count
            FROM groups g
            ORDER BY g.name COLLATE NOCASE ASC
        ")->fetchAll();

        $mStmt = $pdo->prepare('
            SELECT gm.user_id, u.username, u.display_name
            FROM group_members gm
            JOIN users u ON u.id = gm.user_id
            WHERE gm.group_id = :gid
            ORDER BY u.display_name COLLATE NOCASE ASC
        ');

        $out = [];
        foreach ($groups as $g) {
            $gid = (int)$g['id'];
            $mStmt->execute([':gid' => $gid]);
            $members = array_map(fn($m) => [
                'id'           => (int)$m['user_id'],
                'username'     => $m['username'],
                'display_name' => $m['display_name'],
            ], $mStmt->fetchAll());

            $out[] = [
                'id'          => $gid,
                'name'        => $g['name'],
                'share_count' => (int)$g['share_count'],
                'members'     => $members,
                'member_ids'  => array_map(fn($m) => $m['id'], $members),
                'created_at'  => $g['created_at'],
            ];
        }
        return $out;
    }

    public static function saveGroup(?int $groupId, string $rawName, array $memberIds): int
    {
        $name = trim($rawName);
        if ($name === '' || mb_strlen($name) > 80) {
            throw new RuntimeException('Bitte geben Sie einen gültigen Gruppennamen ein (1–80 Zeichen).');
        }

        $pdo = Database::get();
        if ($groupId !== null && $groupId > 0) {
            $chk = $pdo->prepare('SELECT id FROM groups WHERE name = :n COLLATE NOCASE AND id != :id LIMIT 1');
            $chk->execute([':n' => $name, ':id' => $groupId]);
            if ($chk->fetchColumn()) {
                throw new RuntimeException('Eine Gruppe mit diesem Namen existiert bereits.');
            }
            $pdo->prepare('UPDATE groups SET name = :n WHERE id = :id')->execute([':n' => $name, ':id' => $groupId]);
            $gid = $groupId;
        } else {
            $chk = $pdo->prepare('SELECT id FROM groups WHERE name = :n COLLATE NOCASE LIMIT 1');
            $chk->execute([':n' => $name]);
            if ($chk->fetchColumn()) {
                throw new RuntimeException('Eine Gruppe mit diesem Namen existiert bereits.');
            }
            $pdo->prepare("INSERT INTO groups (name, created_at) VALUES (:n, datetime('now'))")->execute([':n' => $name]);
            $gid = (int)$pdo->lastInsertId();
        }

        $pdo->prepare('DELETE FROM group_members WHERE group_id = :gid')->execute([':gid' => $gid]);
        $insM = $pdo->prepare('INSERT OR IGNORE INTO group_members (group_id, user_id) VALUES (:gid, :uid)');
        foreach ($memberIds as $uid) {
            $u = (int)$uid;
            if ($u > 0) {
                $insM->execute([':gid' => $gid, ':uid' => $u]);
            }
        }

        return $gid;
    }

    public static function deleteGroup(int $groupId): void
    {
        Database::get()->prepare('DELETE FROM groups WHERE id = :id')->execute([':id' => $groupId]);
    }

    public static function listGroupFolders(): array
    {
        $pdo = Database::get();
        $folders = $pdo->query("
            SELECT f.id, f.name, f.created_at
            FROM folders f
            WHERE f.is_group_folder = 1 AND f.deleted_at IS NULL
            ORDER BY f.name COLLATE NOCASE ASC
        ")->fetchAll();

        $statStmt = $pdo->prepare("
            WITH RECURSIVE tree(id) AS (
                SELECT :fid
                UNION ALL
                SELECT sub.id FROM folders sub JOIN tree t ON sub.parent_id = t.id WHERE sub.deleted_at IS NULL
            )
            SELECT COUNT(*) AS file_count, COALESCE(SUM(fl.size_bytes), 0) AS used_bytes
            FROM files fl
            WHERE fl.folder_id IN (SELECT id FROM tree) AND fl.deleted_at IS NULL
        ");

        $gStmt = $pdo->prepare("
            SELECT gs.target_group_id, gs.permission, g.name AS group_name
            FROM group_shares gs
            JOIN groups g ON g.id = gs.target_group_id
            WHERE gs.item_type = 'folder' AND gs.item_id = :fid
            ORDER BY g.name COLLATE NOCASE ASC
        ");

        $out = [];
        foreach ($folders as $f) {
            $fid = (int)$f['id'];
            $statStmt->execute([':fid' => $fid]);
            $stats = $statStmt->fetch() ?: ['file_count' => 0, 'used_bytes' => 0];

            $gStmt->execute([':fid' => $fid]);
            $shares = $gStmt->fetchAll();
            $groupIds = [];
            $groupNames = [];
            $perm = 'write';
            foreach ($shares as $s) {
                $groupIds[]   = (int)$s['target_group_id'];
                $groupNames[] = $s['group_name'];
                $perm         = $s['permission'];
            }

            $out[] = [
                'id'          => $fid,
                'name'        => $f['name'],
                'file_count'  => (int)$stats['file_count'],
                'used_bytes'  => (int)$stats['used_bytes'],
                'group_ids'   => $groupIds,
                'group_names' => $groupNames,
                'permission'  => $perm,
                'created_at'  => $f['created_at'],
            ];
        }
        return $out;
    }

    public static function saveGroupFolder(int $adminUserId, ?int $folderId, string $rawName, array $groupIds, string $permission): int
    {
        $name = FileManager::sanitizeName($rawName);
        if ($name === '') {
            throw new RuntimeException('Bitte geben Sie einen gültigen Ordnernamen ein.');
        }
        $cleanGroupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), fn($id) => $id > 0)));
        if (empty($cleanGroupIds)) {
            throw new RuntimeException('Bitte wählen Sie mindestens eine Benutzergruppe für diesen Gruppenordner aus.');
        }
        $perm = ($permission === 'read') ? 'read' : 'write';

        $pdo = Database::get();
        if ($folderId !== null && $folderId > 0) {
            $chk = $pdo->prepare('SELECT id FROM folders WHERE is_group_folder = 1 AND name = :n COLLATE NOCASE AND id != :id AND deleted_at IS NULL LIMIT 1');
            $chk->execute([':n' => $name, ':id' => $folderId]);
            if ($chk->fetchColumn()) {
                throw new RuntimeException('Ein Gruppenordner mit diesem Namen existiert bereits.');
            }
            $pdo->prepare("UPDATE folders SET name = :n, updated_at = datetime('now') WHERE id = :id AND is_group_folder = 1")
                ->execute([':n' => $name, ':id' => $folderId]);
            $fid = $folderId;
        } else {
            $chk = $pdo->prepare('SELECT id FROM folders WHERE is_group_folder = 1 AND name = :n COLLATE NOCASE AND deleted_at IS NULL LIMIT 1');
            $chk->execute([':n' => $name]);
            if ($chk->fetchColumn()) {
                throw new RuntimeException('Ein Gruppenordner mit diesem Namen existiert bereits.');
            }
            $stmt = $pdo->prepare("
                INSERT INTO folders (owner_id, parent_id, name, is_group_folder, created_at, updated_at)
                VALUES (:uid, NULL, :n, 1, datetime('now'), datetime('now'))
            ");
            $stmt->execute([':uid' => $adminUserId, ':n' => $name]);
            $fid = (int)$pdo->lastInsertId();
        }

        $pdo->prepare("DELETE FROM group_shares WHERE item_type = 'folder' AND item_id = :fid")->execute([':fid' => $fid]);
        $ins = $pdo->prepare("
            INSERT INTO group_shares (owner_id, target_group_id, item_type, item_id, permission, created_at)
            VALUES (:uid, :gid, 'folder', :fid, :perm, datetime('now'))
        ");
        foreach ($cleanGroupIds as $gid) {
            $ins->execute([
                ':uid'  => $adminUserId,
                ':gid'  => $gid,
                ':fid'  => $fid,
                ':perm' => $perm,
            ]);
        }

        return $fid;
    }

    public static function deleteGroupFolder(int $folderId): void
    {
        $pdo = Database::get();
        $chk = $pdo->prepare('SELECT id FROM folders WHERE id = :id AND is_group_folder = 1 LIMIT 1');
        $chk->execute([':id' => $folderId]);
        if ($chk->fetchColumn()) {
            FileManager::purgeFolderTree($pdo, $folderId);
        }
    }
}
