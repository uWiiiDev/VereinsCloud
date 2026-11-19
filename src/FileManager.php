<?php
declare(strict_types=1);

namespace Cloud;

use PDO;
use RuntimeException;
use ZipArchive;

class FileManager
{
    public static function sanitizeName(string $name): string
    {
        $name = trim(str_replace(["\0", '/', '\\'], '', $name));
        $name = preg_replace('/[\x00-\x1F\x7F]/u', '', $name) ?? '';
        if ($name === '' || $name === '.' || $name === '..') {
            return '';
        }
        return mb_substr($name, 0, 240);
    }

    /**
     * Checks if $userId can access folder $folderId.
     * Returns ['folder' => row, 'permission' => 'owner'|'write'|'read', 'effective_owner_id' => int] or null.
     */
    public static function resolveFolderAccess(int $userId, int $folderId): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM folders WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $folderId]);
        $folder = $stmt->fetch();
        if (!$folder) {
            return null;
        }

        // Walk up ancestors to check if this tree is a Group Folder and/or shared with $userId
        $curr = $folder;
        $bestPerm = null;
        $isGroupTree = false;
        while ($curr) {
            if ((int)($curr['is_group_folder'] ?? 0) === 1) {
                $isGroupTree = true;
            }
            $cid = (int)$curr['id'];
            if ($bestPerm === null) {
                $shStmt = $pdo->prepare("
                    SELECT permission FROM user_shares
                    WHERE target_user_id = :uid AND item_type = 'folder' AND item_id = :fid
                    UNION ALL
                    SELECT gs.permission
                    FROM group_shares gs
                    JOIN group_members gm ON gm.group_id = gs.target_group_id
                    WHERE gm.user_id = :uid2 AND gs.item_type = 'folder' AND gs.item_id = :fid2
                ");
                $shStmt->execute([':uid' => $userId, ':fid' => $cid, ':uid2' => $userId, ':fid2' => $cid]);
                $perms = $shStmt->fetchAll(PDO::FETCH_COLUMN);
                if (!empty($perms)) {
                    $bestPerm = in_array('write', $perms, true) ? 'write' : 'read';
                }
            }
            if ($curr['parent_id'] === null) {
                break;
            }
            $pStmt = $pdo->prepare('SELECT * FROM folders WHERE id = :id LIMIT 1');
            $pStmt->execute([':id' => (int)$curr['parent_id']]);
            $curr = $pStmt->fetch();
        }

        if (!$isGroupTree && (int)$folder['owner_id'] === $userId) {
            return [
                'folder'             => $folder,
                'permission'         => 'owner',
                'effective_owner_id' => $userId,
                'is_group_folder'    => false,
            ];
        }

        if ($bestPerm !== null && $folder['deleted_at'] === null) {
            return [
                'folder'             => $folder,
                'permission'         => $bestPerm, // 'read' or 'write'
                'effective_owner_id' => (int)$folder['owner_id'],
                'is_group_folder'    => $isGroupTree,
            ];
        }

        return null;
    }

    /**
     * Checks if $userId can access file $fileId.
     * Returns ['file' => row, 'permission' => 'owner'|'write'|'read'] or null.
     */
    public static function resolveFileAccess(int $userId, int $fileId): ?array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM files WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $fileId]);
        $file = $stmt->fetch();
        if (!$file) {
            return null;
        }

        if ((int)$file['owner_id'] === $userId) {
            return [
                'file'       => $file,
                'permission' => 'owner',
            ];
        }

        if ($file['deleted_at'] !== null) {
            return null;
        }

        // Direct file share (user_shares or group_shares)?
        $shStmt = $pdo->prepare("
            SELECT permission FROM user_shares
            WHERE target_user_id = :uid AND item_type = 'file' AND item_id = :fid
            UNION ALL
            SELECT gs.permission
            FROM group_shares gs
            JOIN group_members gm ON gm.group_id = gs.target_group_id
            WHERE gm.user_id = :uid2 AND gs.item_type = 'file' AND gs.item_id = :fid2
        ");
        $shStmt->execute([':uid' => $userId, ':fid' => $fileId, ':uid2' => $userId, ':fid2' => $fileId]);
        $perms = $shStmt->fetchAll(PDO::FETCH_COLUMN);
        if (!empty($perms)) {
            return [
                'file'       => $file,
                'permission' => in_array('write', $perms, true) ? 'write' : 'read',
            ];
        }

        // Shared via parent folder?
        if ($file['folder_id'] !== null) {
            $folderAccess = self::resolveFolderAccess($userId, (int)$file['folder_id']);
            if ($folderAccess) {
                return [
                    'file'       => $file,
                    'permission' => $folderAccess['permission'],
                ];
            }
        }

        return null;
    }

    public static function canWriteFolder(int $userId, ?int $folderId): array
    {
        if ($folderId === null) {
            return ['effective_owner_id' => $userId, 'permission' => 'owner'];
        }
        $access = self::resolveFolderAccess($userId, $folderId);
        if (!$access || !in_array($access['permission'], ['owner', 'write'], true)) {
            throw new RuntimeException('Keine Schreibberechtigung für diesen Ordner.');
        }
        if ($access['folder']['deleted_at'] !== null) {
            throw new RuntimeException('Dieser Ordner befindet sich im Papierkorb.');
        }
        return $access;
    }

    public static function listView(int $userId, ?int $folderId = null, string $view = 'files', string $search = ''): array
    {
        $pdo = Database::get();
        $search = trim($search);
        $breadcrumbs = [];
        $currentFolder = null;
        $permission = 'owner';

        if ($view === 'trash') {
            $fStmt = $pdo->prepare('
                SELECT id, name, parent_id, deleted_at, created_at, updated_at
                FROM folders
                WHERE owner_id = :uid AND deleted_at IS NOT NULL
                ORDER BY deleted_at DESC, name COLLATE NOCASE ASC
            ');
            $fStmt->execute([':uid' => $userId]);
            $folders = $fStmt->fetchAll();

            $fileStmt = $pdo->prepare('
                SELECT id, folder_id, name, mime_type, size_bytes, sha256, is_encrypted, deleted_at, created_at, updated_at
                FROM files
                WHERE owner_id = :uid AND deleted_at IS NOT NULL
                ORDER BY deleted_at DESC, name COLLATE NOCASE ASC
            ');
            $fileStmt->execute([':uid' => $userId]);
            $files = $fileStmt->fetchAll();

            return [
                'view'           => 'trash',
                'current_folder' => null,
                'permission'     => 'owner',
                'breadcrumbs'    => [['id' => null, 'name' => 'Papierkorb']],
                'folders'        => self::formatFolders($folders),
                'files'          => self::formatFiles($files),
            ];
        }

        if ($view === 'vault') {
            $fileStmt = $pdo->prepare('
                SELECT id, folder_id, name, mime_type, size_bytes, sha256, is_encrypted, deleted_at, created_at, updated_at
                FROM files
                WHERE owner_id = :uid AND is_encrypted = 1 AND deleted_at IS NULL
                ORDER BY name COLLATE NOCASE ASC
            ');
            $fileStmt->execute([':uid' => $userId]);
            $files = $fileStmt->fetchAll();

            return [
                'view'           => 'vault',
                'current_folder' => null,
                'permission'     => 'owner',
                'breadcrumbs'    => [['id' => null, 'name' => 'Tresor (Verschlüsselt)']],
                'folders'        => [],
                'files'          => self::formatFiles($files),
            ];
        }

        if ($view === 'shared' && $folderId === null) {
            // Top-level items shared with $userId directly or via group_shares
            $fStmt = $pdo->prepare("
                SELECT f.id, f.name, f.parent_id, f.created_at, f.updated_at,
                       s.permission AS shared_permission, u.display_name AS owner_name, NULL AS group_name
                FROM user_shares s
                JOIN folders f ON f.id = s.item_id AND s.item_type = 'folder'
                JOIN users u ON u.id = f.owner_id
                WHERE s.target_user_id = :uid AND f.deleted_at IS NULL
                UNION ALL
                SELECT f.id, f.name, f.parent_id, f.created_at, f.updated_at,
                       gs.permission AS shared_permission, u.display_name AS owner_name, g.name AS group_name
                FROM group_shares gs
                JOIN group_members gm ON gm.group_id = gs.target_group_id
                JOIN groups g ON g.id = gs.target_group_id
                JOIN folders f ON f.id = gs.item_id AND gs.item_type = 'folder'
                JOIN users u ON u.id = f.owner_id
                WHERE gm.user_id = :uid2 AND f.owner_id != :uid3 AND f.is_group_folder = 0 AND f.deleted_at IS NULL
                ORDER BY name COLLATE NOCASE ASC
            ");
            $fStmt->execute([':uid' => $userId, ':uid2' => $userId, ':uid3' => $userId]);
            $folders = $fStmt->fetchAll();

            $fileStmt = $pdo->prepare("
                SELECT fl.id, fl.folder_id, fl.name, fl.mime_type, fl.size_bytes, fl.sha256, fl.is_encrypted, fl.created_at, fl.updated_at,
                       s.permission AS shared_permission, u.display_name AS owner_name
                FROM user_shares s
                JOIN files fl ON fl.id = s.item_id AND s.item_type = 'file'
                JOIN users u ON u.id = fl.owner_id
                WHERE s.target_user_id = :uid AND fl.deleted_at IS NULL
                UNION ALL
                SELECT fl.id, fl.folder_id, fl.name, fl.mime_type, fl.size_bytes, fl.sha256, fl.is_encrypted, fl.created_at, fl.updated_at,
                       gs.permission AS shared_permission, u.display_name AS owner_name
                FROM group_shares gs
                JOIN group_members gm ON gm.group_id = gs.target_group_id
                JOIN files fl ON fl.id = gs.item_id AND gs.item_type = 'file'
                JOIN users u ON u.id = fl.owner_id
                WHERE gm.user_id = :uid2 AND fl.owner_id != :uid3 AND fl.deleted_at IS NULL
                ORDER BY name COLLATE NOCASE ASC
            ");
            $fileStmt->execute([':uid' => $userId, ':uid2' => $userId, ':uid3' => $userId]);
            $files = $fileStmt->fetchAll();

            return [
                'view'           => 'shared',
                'current_folder' => null,
                'permission'     => 'read',
                'breadcrumbs'    => [['id' => null, 'name' => 'Für mich freigegeben']],
                'folders'        => self::formatFolders($folders),
                'files'          => self::formatFiles($files),
            ];
        }

        // Global search across user's own non-deleted files & folders
        if ($search !== '') {
            $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $search) . '%';
            $fStmt = $pdo->prepare("
                SELECT id, name, parent_id, created_at, updated_at
                FROM folders
                WHERE owner_id = :uid AND deleted_at IS NULL AND name LIKE :q ESCAPE '\\'
                ORDER BY name COLLATE NOCASE ASC
                LIMIT 100
            ");
            $fStmt->execute([':uid' => $userId, ':q' => $like]);
            $folders = $fStmt->fetchAll();

            $fileStmt = $pdo->prepare("
                SELECT id, folder_id, name, mime_type, size_bytes, sha256, is_encrypted, created_at, updated_at
                FROM files
                WHERE owner_id = :uid AND deleted_at IS NULL AND name LIKE :q ESCAPE '\\'
                ORDER BY name COLLATE NOCASE ASC
                LIMIT 200
            ");
            $fileStmt->execute([':uid' => $userId, ':q' => $like]);
            $files = $fileStmt->fetchAll();

            return [
                'view'           => 'files',
                'current_folder' => null,
                'permission'     => 'owner',
                'breadcrumbs'    => [['id' => null, 'name' => 'Suche: "' . $search . '"']],
                'folders'        => self::formatFolders($folders),
                'files'          => self::formatFiles($files),
            ];
        }

        // Virtual "Gruppenordner" container directory ($folderId === -1)
        if ($folderId === -1) {
            $gfStmt = $pdo->prepare("
                SELECT f.id, f.name, -1 AS parent_id, f.is_group_folder, f.created_at, f.updated_at,
                       (SELECT COUNT(*) FROM public_shares ps WHERE ps.item_type = 'folder' AND ps.item_id = f.id) AS share_count,
                       (SELECT COUNT(*) FROM files fl WHERE fl.folder_id = f.id AND fl.is_encrypted = 1 AND fl.deleted_at IS NULL) AS encrypted_count,
                       GROUP_CONCAT(g.name, ', ') AS group_name
                FROM folders f
                JOIN group_shares gs ON gs.item_type = 'folder' AND gs.item_id = f.id
                JOIN group_members gm ON gm.group_id = gs.target_group_id
                JOIN groups g ON g.id = gs.target_group_id
                WHERE f.is_group_folder = 1 AND f.deleted_at IS NULL AND gm.user_id = :uid
                GROUP BY f.id
                ORDER BY f.name COLLATE NOCASE ASC
            ");
            $gfStmt->execute([':uid' => $userId]);

            return [
                'view'           => 'files',
                'current_folder' => ['id' => -1, 'name' => 'Gruppenordner', 'parent_id' => null],
                'permission'     => 'read',
                'is_group_tree'  => true,
                'breadcrumbs'    => [
                    ['id' => null, 'name' => 'Meine Dateien'],
                    ['id' => -1,   'name' => 'Gruppenordner'],
                ],
                'folders'        => self::formatFolders($gfStmt->fetchAll()),
                'files'          => [],
            ];
        }

        // Standard folder listing (own or shared folder)
        $effectiveOwnerId = $userId;
        $isGroupTree = false;
        if ($folderId !== null) {
            $access = self::resolveFolderAccess($userId, $folderId);
            if (!$access || $access['folder']['deleted_at'] !== null) {
                throw new RuntimeException('Ordner nicht gefunden oder kein Zugriff.');
            }
            $currentFolder    = $access['folder'];
            $permission       = $access['permission'];
            $effectiveOwnerId = $access['effective_owner_id'];
            $isGroupTree      = !empty($access['is_group_folder']);

            // Build breadcrumbs up to the highest accessible ancestor
            $trail = [];
            $curr = $currentFolder;
            while ($curr) {
                array_unshift($trail, [
                    'id'   => (int)$curr['id'],
                    'name' => $curr['name'],
                ]);
                if ($curr['parent_id'] === null) {
                    break;
                }
                $parentAccess = self::resolveFolderAccess($userId, (int)$curr['parent_id']);
                if (!$parentAccess) {
                    break;
                }
                $curr = $parentAccess['folder'];
            }
            if ($isGroupTree) {
                $breadcrumbs = array_merge([
                    ['id' => null, 'name' => 'Meine Dateien'],
                    ['id' => -1,   'name' => 'Gruppenordner'],
                ], $trail);
            } else {
                $rootLabel = ($effectiveOwnerId === $userId) ? 'Meine Dateien' : 'Für mich freigegeben';
                $breadcrumbs = array_merge([['id' => null, 'name' => $rootLabel]], $trail);
            }
        } else {
            $breadcrumbs = [['id' => null, 'name' => 'Meine Dateien']];
        }

        if ($folderId === null) {
            // Own personal root folders
            $fStmt = $pdo->prepare("
                SELECT f.id, f.name, f.parent_id, f.is_group_folder, f.created_at, f.updated_at,
                       (SELECT COUNT(*) FROM public_shares ps WHERE ps.item_type = 'folder' AND ps.item_id = f.id) +
                       (SELECT COUNT(*) FROM user_shares us WHERE us.item_type = 'folder' AND us.item_id = f.id) +
                       (SELECT COUNT(*) FROM group_shares gsc WHERE gsc.item_type = 'folder' AND gsc.item_id = f.id) AS share_count,
                       (SELECT COUNT(*) FROM files fl WHERE fl.folder_id = f.id AND fl.is_encrypted = 1 AND fl.deleted_at IS NULL) AS encrypted_count,
                       (SELECT g.name FROM group_shares gs JOIN groups g ON g.id = gs.target_group_id WHERE gs.item_type = 'folder' AND gs.item_id = f.id LIMIT 1) AS group_name
                FROM folders f
                WHERE f.owner_id = :uid AND f.parent_id IS NULL AND f.is_group_folder = 0 AND f.deleted_at IS NULL
                ORDER BY f.name COLLATE NOCASE ASC
            ");
            $fStmt->execute([':uid' => $effectiveOwnerId]);
            $formattedFolders = self::formatFolders($fStmt->fetchAll());

            // Check if user has any assigned Group Folders -> bundle them into one "Gruppenordner" entry!
            $gfCntStmt = $pdo->prepare("
                SELECT COUNT(DISTINCT f.id)
                FROM folders f
                JOIN group_shares gs ON gs.item_type = 'folder' AND gs.item_id = f.id
                JOIN group_members gm ON gm.group_id = gs.target_group_id
                WHERE f.is_group_folder = 1 AND f.deleted_at IS NULL AND gm.user_id = :uid
            ");
            $gfCntStmt->execute([':uid' => $userId]);
            $gfCount = (int)$gfCntStmt->fetchColumn();
            if ($gfCount > 0) {
                array_unshift($formattedFolders, [
                    'id'                => -1,
                    'type'              => 'folder',
                    'name'              => 'Gruppenordner',
                    'parent_id'         => null,
                    'is_group_folder'   => true,
                    'is_group_root'     => true,
                    'share_count'       => 0,
                    'encrypted_count'   => 0,
                    'group_name'        => $gfCount . ' Ordner',
                    'shared_permission' => 'read',
                    'owner_name'        => null,
                    'deleted_at'        => null,
                    'created_at'        => date('Y-m-d H:i:s'),
                    'updated_at'        => date('Y-m-d H:i:s'),
                ]);
            }

            $fileStmt = $pdo->prepare("
                SELECT fl.id, fl.folder_id, fl.name, fl.mime_type, fl.size_bytes, fl.sha256, fl.is_encrypted, fl.created_at, fl.updated_at,
                       (SELECT COUNT(*) FROM public_shares ps WHERE ps.item_type = 'file' AND ps.item_id = fl.id) +
                       (SELECT COUNT(*) FROM user_shares us WHERE us.item_type = 'file' AND us.item_id = fl.id) +
                       (SELECT COUNT(*) FROM group_shares gsc WHERE gsc.item_type = 'file' AND gsc.item_id = fl.id) AS share_count
                FROM files fl
                WHERE fl.owner_id = :uid AND fl.folder_id IS NULL AND fl.deleted_at IS NULL
                ORDER BY fl.name COLLATE NOCASE ASC
            ");
            $fileStmt->execute([':uid' => $effectiveOwnerId]);
        } else {
            $fStmt = $pdo->prepare("
                SELECT f.id, f.name, f.parent_id, f.is_group_folder, f.created_at, f.updated_at,
                       (SELECT COUNT(*) FROM public_shares ps WHERE ps.item_type = 'folder' AND ps.item_id = f.id) +
                       (SELECT COUNT(*) FROM user_shares us WHERE us.item_type = 'folder' AND us.item_id = f.id) +
                       (SELECT COUNT(*) FROM group_shares gsc WHERE gsc.item_type = 'folder' AND gsc.item_id = f.id) AS share_count,
                       (SELECT COUNT(*) FROM files fl WHERE fl.folder_id = f.id AND fl.is_encrypted = 1 AND fl.deleted_at IS NULL) AS encrypted_count,
                       (SELECT g.name FROM group_shares gs JOIN groups g ON g.id = gs.target_group_id WHERE gs.item_type = 'folder' AND gs.item_id = f.id LIMIT 1) AS group_name
                FROM folders f
                WHERE f.owner_id = :uid AND f.parent_id = :pid AND f.deleted_at IS NULL
                ORDER BY f.name COLLATE NOCASE ASC
            ");
            $fStmt->execute([':uid' => $effectiveOwnerId, ':pid' => $folderId]);
            $formattedFolders = self::formatFolders($fStmt->fetchAll());

            $fileStmt = $pdo->prepare("
                SELECT fl.id, fl.folder_id, fl.name, fl.mime_type, fl.size_bytes, fl.sha256, fl.is_encrypted, fl.created_at, fl.updated_at,
                       (SELECT COUNT(*) FROM public_shares ps WHERE ps.item_type = 'file' AND ps.item_id = fl.id) +
                       (SELECT COUNT(*) FROM user_shares us WHERE us.item_type = 'file' AND us.item_id = fl.id) +
                       (SELECT COUNT(*) FROM group_shares gsc WHERE gsc.item_type = 'file' AND gsc.item_id = fl.id) AS share_count
                FROM files fl
                WHERE fl.owner_id = :uid AND fl.folder_id = :fid AND fl.deleted_at IS NULL
                ORDER BY fl.name COLLATE NOCASE ASC
            ");
            $fileStmt->execute([':uid' => $effectiveOwnerId, ':fid' => $folderId]);
        }

        return [
            'view'           => ($effectiveOwnerId === $userId || $isGroupTree) ? 'files' : 'shared',
            'current_folder' => $currentFolder ? ['id' => (int)$currentFolder['id'], 'name' => $currentFolder['name'], 'parent_id' => $currentFolder['parent_id'] ? (int)$currentFolder['parent_id'] : null] : null,
            'permission'     => $permission,
            'is_group_tree'  => $isGroupTree,
            'breadcrumbs'    => $breadcrumbs,
            'folders'        => $formattedFolders,
            'files'          => self::formatFiles($fileStmt->fetchAll()),
        ];
    }

    private static function formatFolders(array $rows): array
    {
        $out = [];
        $seen = [];
        foreach ($rows as $r) {
            $fid = (int)$r['id'];
            if (isset($seen[$fid])) {
                continue;
            }
            $seen[$fid] = true;
            $out[] = [
                'id'                => $fid,
                'type'              => 'folder',
                'name'              => $r['name'],
                'parent_id'         => isset($r['parent_id']) && $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
                'is_group_folder'   => (int)($r['is_group_folder'] ?? 0) === 1,
                'share_count'       => (int)($r['share_count'] ?? 0),
                'encrypted_count'   => (int)($r['encrypted_count'] ?? 0),
                'group_name'        => $r['group_name'] ?? null,
                'shared_permission' => $r['shared_permission'] ?? null,
                'owner_name'        => $r['owner_name'] ?? null,
                'deleted_at'        => $r['deleted_at'] ?? null,
                'created_at'        => $r['created_at'],
                'updated_at'        => $r['updated_at'],
            ];
        }
        return $out;
    }

    private static function formatFiles(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $ext = strtolower(pathinfo($r['name'], PATHINFO_EXTENSION));
            $out[] = [
                'id'                => (int)$r['id'],
                'type'              => 'file',
                'folder_id'         => isset($r['folder_id']) && $r['folder_id'] !== null ? (int)$r['folder_id'] : null,
                'name'              => $r['name'],
                'ext'               => $ext,
                'mime_type'         => $r['mime_type'],
                'size_bytes'        => (int)$r['size_bytes'],
                'is_encrypted'      => (int)$r['is_encrypted'] === 1,
                'share_count'       => (int)($r['share_count'] ?? 0),
                'shared_permission' => $r['shared_permission'] ?? null,
                'owner_name'        => $r['owner_name'] ?? null,
                'deleted_at'        => $r['deleted_at'] ?? null,
                'created_at'        => $r['created_at'],
                'updated_at'        => $r['updated_at'],
                'category'          => self::categorizeFile($r['name'], $r['mime_type']),
            ];
        }
        return $out;
    }

    public static function categorizeFile(string $name, string $mime): string
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (str_starts_with($mime, 'image/') || in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp', 'ico'], true)) {
            return 'image';
        }
        if (str_starts_with($mime, 'video/') || in_array($ext, ['mp4', 'webm', 'mov', 'mkv', 'ogv', 'm4v'], true)) {
            return 'video';
        }
        if (str_starts_with($mime, 'audio/') || in_array($ext, ['mp3', 'wav', 'ogg', 'flac', 'm4a', 'aac', 'opus'], true)) {
            return 'audio';
        }
        if ($mime === 'application/pdf' || $ext === 'pdf') {
            return 'pdf';
        }
        if ($ext === 'zip' || $mime === 'application/zip' || $mime === 'application/x-zip-compressed') {
            return 'zip';
        }
        if (in_array($ext, ['txt', 'md', 'csv', 'json', 'xml', 'html', 'css', 'js', 'ts', 'php', 'py', 'sh', 'sql', 'ini', 'yaml', 'yml', 'log'], true) || str_starts_with($mime, 'text/')) {
            return 'text';
        }
        return 'other';
    }

    public static function createFolder(int $userId, ?int $parentId, string $rawName): array
    {
        $name = self::sanitizeName($rawName);
        if ($name === '') {
            throw new RuntimeException('Ungültiger Ordnername.');
        }

        $access = self::canWriteFolder($userId, $parentId);
        $ownerId = $access['effective_owner_id'];

        $pdo = Database::get();
        // Check duplicate name
        if ($parentId === null) {
            $chk = $pdo->prepare('SELECT id FROM folders WHERE owner_id = :uid AND parent_id IS NULL AND name = :name COLLATE NOCASE AND deleted_at IS NULL LIMIT 1');
            $chk->execute([':uid' => $ownerId, ':name' => $name]);
        } else {
            $chk = $pdo->prepare('SELECT id FROM folders WHERE owner_id = :uid AND parent_id = :pid AND name = :name COLLATE NOCASE AND deleted_at IS NULL LIMIT 1');
            $chk->execute([':uid' => $ownerId, ':pid' => $parentId, ':name' => $name]);
        }
        $existingId = $chk->fetchColumn();
        if ($existingId) {
            return ['id' => (int)$existingId, 'name' => $name, 'existed' => true];
        }

        $stmt = $pdo->prepare("INSERT INTO folders (owner_id, parent_id, name, created_at, updated_at) VALUES (:uid, :pid, :name, datetime('now'), datetime('now'))");
        $stmt->execute([
            ':uid'  => $ownerId,
            ':pid'  => $parentId,
            ':name' => $name,
        ]);

        return [
            'id'      => (int)$pdo->lastInsertId(),
            'name'    => $name,
            'existed' => false,
        ];
    }

    public static function ensureSubfolderPath(int $userId, ?int $baseFolderId, string $relativeDir): ?int
    {
        $parts = array_filter(explode('/', str_replace('\\', '/', trim($relativeDir, '/\\'))), fn($p) => $p !== '' && $p !== '.' && $p !== '..');
        $currId = $baseFolderId;
        foreach ($parts as $part) {
            $clean = self::sanitizeName($part);
            if ($clean === '') {
                continue;
            }
            $res = self::createFolder($userId, $currId, $clean);
            $currId = $res['id'];
        }
        return $currId;
    }

    public static function renameItem(int $userId, string $type, int $id, string $rawNewName): void
    {
        $name = self::sanitizeName($rawNewName);
        if ($name === '') {
            throw new RuntimeException('Ungültiger Name.');
        }

        $pdo = Database::get();
        if ($type === 'folder') {
            $access = self::resolveFolderAccess($userId, $id);
            if (!$access || !in_array($access['permission'], ['owner', 'write'], true)) {
                throw new RuntimeException('Keine Berechtigung zum Umbenennen dieses Ordners.');
            }
            $folder = $access['folder'];
            if ((int)($folder['is_group_folder'] ?? 0) === 1) {
                throw new RuntimeException('Gruppenordner können nur in der Administration umbenannt werden.');
            }
            $ownerId = (int)$folder['owner_id'];
            $parentId = $folder['parent_id'] !== null ? (int)$folder['parent_id'] : null;

            if ($parentId === null) {
                $chk = $pdo->prepare('SELECT id FROM folders WHERE owner_id = :uid AND parent_id IS NULL AND name = :name COLLATE NOCASE AND id != :id AND deleted_at IS NULL LIMIT 1');
                $chk->execute([':uid' => $ownerId, ':name' => $name, ':id' => $id]);
            } else {
                $chk = $pdo->prepare('SELECT id FROM folders WHERE owner_id = :uid AND parent_id = :pid AND name = :name COLLATE NOCASE AND id != :id AND deleted_at IS NULL LIMIT 1');
                $chk->execute([':uid' => $ownerId, ':pid' => $parentId, ':name' => $name, ':id' => $id]);
            }
            if ($chk->fetchColumn()) {
                throw new RuntimeException('Ein Ordner mit diesem Namen existiert bereits am Zielort.');
            }

            $stmt = $pdo->prepare("UPDATE folders SET name = :name, updated_at = datetime('now') WHERE id = :id");
            $stmt->execute([':name' => $name, ':id' => $id]);
        } else {
            $access = self::resolveFileAccess($userId, $id);
            if (!$access || !in_array($access['permission'], ['owner', 'write'], true)) {
                throw new RuntimeException('Keine Berechtigung zum Umbenennen dieser Datei.');
            }
            $file = $access['file'];
            $ownerId = (int)$file['owner_id'];
            $folderId = $file['folder_id'] !== null ? (int)$file['folder_id'] : null;

            if ($folderId === null) {
                $chk = $pdo->prepare('SELECT id FROM files WHERE owner_id = :uid AND folder_id IS NULL AND name = :name COLLATE NOCASE AND id != :id AND deleted_at IS NULL LIMIT 1');
                $chk->execute([':uid' => $ownerId, ':name' => $name, ':id' => $id]);
            } else {
                $chk = $pdo->prepare('SELECT id FROM files WHERE owner_id = :uid AND folder_id = :fid AND name = :name COLLATE NOCASE AND id != :id AND deleted_at IS NULL LIMIT 1');
                $chk->execute([':uid' => $ownerId, ':fid' => $folderId, ':name' => $name, ':id' => $id]);
            }
            if ($chk->fetchColumn()) {
                throw new RuntimeException('Eine Datei mit diesem Namen existiert bereits in diesem Ordner.');
            }

            $stmt = $pdo->prepare("UPDATE files SET name = :name, updated_at = datetime('now') WHERE id = :id");
            $stmt->execute([':name' => $name, ':id' => $id]);
        }
    }

    public static function moveItems(int $userId, array $items, ?int $targetFolderId, ?array &$undoMovesOut = null): int
    {
        $targetAccess = self::canWriteFolder($userId, $targetFolderId);
        $targetOwnerId = $targetAccess['effective_owner_id'];
        $pdo = Database::get();
        $moved = 0;
        $undoMovesOut = [];

        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            $id   = (int)($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if ($type === 'folder') {
                if ($targetFolderId === $id) {
                    continue;
                }
                $access = self::resolveFolderAccess($userId, $id);
                if (!$access || !in_array($access['permission'], ['owner', 'write'], true)) {
                    continue;
                }
                if ((int)($access['folder']['is_group_folder'] ?? 0) === 1) {
                    throw new RuntimeException('Gruppenordner können nicht verschoben werden.');
                }
                if ((int)$access['folder']['owner_id'] !== $targetOwnerId) {
                    throw new RuntimeException('Ordner können nicht zwischen verschiedenen Eigentümern verschoben werden.');
                }
                if ($targetFolderId !== null && self::isDescendantFolder($pdo, $targetFolderId, $id)) {
                    throw new RuntimeException('Ein Ordner kann nicht in sich selbst oder einen seiner Unterordner verschoben werden.');
                }

                $prevParent = $access['folder']['parent_id'] !== null ? (int)$access['folder']['parent_id'] : null;
                $stmt = $pdo->prepare("UPDATE folders SET parent_id = :pid, updated_at = datetime('now') WHERE id = :id");
                $stmt->execute([':pid' => $targetFolderId, ':id' => $id]);
                $undoMovesOut[] = ['type' => 'folder', 'id' => $id, 'name' => $access['folder']['name'], 'prev_folder_id' => $prevParent];
                $moved++;
            } elseif ($type === 'file') {
                $access = self::resolveFileAccess($userId, $id);
                if (!$access || !in_array($access['permission'], ['owner', 'write'], true)) {
                    continue;
                }
                if ((int)$access['file']['owner_id'] !== $targetOwnerId) {
                    throw new RuntimeException('Dateien können nicht zwischen verschiedenen Eigentümern verschoben werden.');
                }
                $prevFolder = $access['file']['folder_id'] !== null ? (int)$access['file']['folder_id'] : null;
                $stmt = $pdo->prepare("UPDATE files SET folder_id = :fid, updated_at = datetime('now') WHERE id = :id");
                $stmt->execute([':fid' => $targetFolderId, ':id' => $id]);
                $undoMovesOut[] = ['type' => 'file', 'id' => $id, 'name' => $access['file']['name'], 'prev_folder_id' => $prevFolder];
                $moved++;
            }
        }

        return $moved;
    }

    public static function undoMoves(int $userId, array $moves): int
    {
        $pdo = Database::get();
        $restored = 0;
        foreach ($moves as $m) {
            $type   = (string)($m['type'] ?? '');
            $id     = (int)($m['id'] ?? 0);
            $prevId = isset($m['prev_folder_id']) && $m['prev_folder_id'] !== '' && $m['prev_folder_id'] !== null
                ? (int)$m['prev_folder_id']
                : null;
            if ($prevId !== null) {
                $chk = $pdo->prepare('SELECT id FROM folders WHERE id = :id AND deleted_at IS NULL LIMIT 1');
                $chk->execute([':id' => $prevId]);
                if (!$chk->fetchColumn()) {
                    $prevId = null;
                }
            }
            if ($id > 0 && in_array($type, ['file', 'folder'], true)) {
                $restored += self::moveItems($userId, [['type' => $type, 'id' => $id]], $prevId);
            }
        }
        return $restored;
    }

    public static function copyItems(int $userId, array $items, ?int $targetFolderId, ?array &$createdOut = null): int
    {
        $targetAccess  = self::canWriteFolder($userId, $targetFolderId);
        $targetOwnerId = $targetAccess['effective_owner_id'];
        $pdo           = Database::get();
        $copied        = 0;
        $createdOut    = [];

        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            $id   = (int)($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }

            if ($type === 'file') {
                $access = self::resolveFileAccess($userId, $id);
                if (!$access) {
                    continue;
                }
                $newFileId = self::copySingleFile($pdo, $access['file'], $targetOwnerId, $targetFolderId);
                $createdOut[] = ['type' => 'file', 'id' => $newFileId, 'name' => $access['file']['name']];
                $copied++;
            } elseif ($type === 'folder') {
                $access = self::resolveFolderAccess($userId, $id);
                if (!$access) {
                    continue;
                }
                if ($targetFolderId !== null && ($targetFolderId === $id || self::isDescendantFolder($pdo, $targetFolderId, $id))) {
                    throw new RuntimeException('Ein Ordner kann nicht in sich selbst oder einen seiner Unterordner kopiert werden.');
                }
                $newFolderId = self::copyFolderRecursive($pdo, $userId, $access['folder'], $targetOwnerId, $targetFolderId);
                $createdOut[] = ['type' => 'folder', 'id' => $newFolderId, 'name' => $access['folder']['name']];
                $copied++;
            }
        }

        return $copied;
    }

    private static function copySingleFile(PDO $pdo, array $srcFile, int $targetOwnerId, ?int $targetFolderId): int
    {
        $size = (int)$srcFile['size_bytes'];
        if (!Auth::canFitBytes($targetOwnerId, $size)) {
            throw new RuntimeException('Speicherplatz-Quota beim Kopieren überschritten.');
        }

        $srcPath = Database::FILES_DIR . '/' . basename($srcFile['storage_name']);
        if (!is_file($srcPath)) {
            throw new RuntimeException('Quelldatei physisch nicht gefunden.');
        }

        $targetName = $srcFile['name'];
        // If a file with the same name already exists in target folder, append " (Kopie)" instead of overwriting
        if ($targetFolderId === null) {
            $chk = $pdo->prepare('SELECT id FROM files WHERE owner_id = :uid AND folder_id IS NULL AND name = :name COLLATE NOCASE AND deleted_at IS NULL LIMIT 1');
            $chk->execute([':uid' => $targetOwnerId, ':name' => $targetName]);
        } else {
            $chk = $pdo->prepare('SELECT id FROM files WHERE owner_id = :uid AND folder_id = :fid AND name = :name COLLATE NOCASE AND deleted_at IS NULL LIMIT 1');
            $chk->execute([':uid' => $targetOwnerId, ':fid' => $targetFolderId, ':name' => $targetName]);
        }
        if ($chk->fetchColumn()) {
            $ext  = pathinfo($targetName, PATHINFO_EXTENSION);
            $base = pathinfo($targetName, PATHINFO_FILENAME);
            $targetName = $base . ' (Kopie)' . ($ext !== '' ? '.' . $ext : '');
        }

        $newStorageName = bin2hex(random_bytes(16));
        $destPath       = Database::FILES_DIR . '/' . $newStorageName;
        if (!@copy($srcPath, $destPath)) {
            throw new RuntimeException('Datei konnte nicht kopiert werden.');
        }

        $ins = $pdo->prepare("
            INSERT INTO files (owner_id, folder_id, name, storage_name, mime_type, size_bytes, sha256, is_encrypted, created_at, updated_at)
            VALUES (:uid, :fid, :name, :sname, :mime, :size, :sha, :enc, datetime('now'), datetime('now'))
        ");
        $ins->execute([
            ':uid'   => $targetOwnerId,
            ':fid'   => $targetFolderId,
            ':name'  => $targetName,
            ':sname' => $newStorageName,
            ':mime'  => $srcFile['mime_type'],
            ':size'  => $size,
            ':sha'   => $srcFile['sha256'] ?? '',
            ':enc'   => (int)$srcFile['is_encrypted'],
        ]);

        return (int)$pdo->lastInsertId();
    }

    private static function copyFolderRecursive(PDO $pdo, int $userId, array $srcFolder, int $targetOwnerId, ?int $targetParentId): int
    {
        $folderName = $srcFolder['name'];
        if ($targetParentId !== null && isset($srcFolder['parent_id']) && (int)$srcFolder['parent_id'] === $targetParentId) {
            $folderName .= ' (Kopie)';
        } elseif ($targetParentId === null && $srcFolder['parent_id'] === null && (int)$srcFolder['owner_id'] === $targetOwnerId) {
            $folderName .= ' (Kopie)';
        }

        $created = self::createFolder($userId, $targetParentId, $folderName);
        $newFolderId = (int)$created['id'];

        $flStmt = $pdo->prepare('SELECT * FROM files WHERE folder_id = :fid AND deleted_at IS NULL');
        $flStmt->execute([':fid' => (int)$srcFolder['id']]);
        foreach ($flStmt->fetchAll() as $fl) {
            self::copySingleFile($pdo, $fl, $targetOwnerId, $newFolderId);
        }

        $subStmt = $pdo->prepare('SELECT * FROM folders WHERE parent_id = :pid AND deleted_at IS NULL');
        $subStmt->execute([':pid' => (int)$srcFolder['id']]);
        foreach ($subStmt->fetchAll() as $sub) {
            self::copyFolderRecursive($pdo, $userId, $sub, $targetOwnerId, $newFolderId);
        }

        return $newFolderId;
    }

    private static function isDescendantFolder(PDO $pdo, int $candidateId, int $ancestorId): bool
    {
        $currId = $candidateId;
        $depth = 0;
        while ($currId !== null && $depth < 100) {
            if ($currId === $ancestorId) {
                return true;
            }
            $stmt = $pdo->prepare('SELECT parent_id FROM folders WHERE id = :id LIMIT 1');
            $stmt->execute([':id' => $currId]);
            $parent = $stmt->fetchColumn();
            $currId = ($parent !== false && $parent !== null) ? (int)$parent : null;
            $depth++;
        }
        return false;
    }

    public static function trashItems(int $userId, array $items): int
    {
        $pdo = Database::get();
        $count = 0;
        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            $id   = (int)($item['id'] ?? 0);
            if ($type === 'folder') {
                $access = self::resolveFolderAccess($userId, $id);
                if ($access && (int)($access['folder']['is_group_folder'] ?? 0) === 1) {
                    throw new RuntimeException('Gruppenordner können nur in der Administration gelöscht werden.');
                }
                if ($access && in_array($access['permission'], ['owner', 'write'], true)) {
                    self::setFolderTreeTrashState($pdo, $id, true);
                    $count++;
                }
            } elseif ($type === 'file') {
                $access = self::resolveFileAccess($userId, $id);
                if ($access && in_array($access['permission'], ['owner', 'write'], true)) {
                    $stmt = $pdo->prepare("UPDATE files SET deleted_at = datetime('now') WHERE id = :id");
                    $stmt->execute([':id' => $id]);
                    $count++;
                }
            }
        }
        return $count;
    }

    private static function setFolderTreeTrashState(PDO $pdo, int $folderId, bool $trash): void
    {
        $tsExpr = $trash ? "datetime('now')" : 'NULL';
        $pdo->prepare("UPDATE folders SET deleted_at = $tsExpr WHERE id = :id")->execute([':id' => $folderId]);
        $pdo->prepare("UPDATE files SET deleted_at = $tsExpr WHERE folder_id = :fid")->execute([':fid' => $folderId]);

        $subStmt = $pdo->prepare('SELECT id FROM folders WHERE parent_id = :pid');
        $subStmt->execute([':pid' => $folderId]);
        foreach ($subStmt->fetchAll(PDO::FETCH_COLUMN) as $childId) {
            self::setFolderTreeTrashState($pdo, (int)$childId, $trash);
        }
    }

    public static function restoreItems(int $userId, array $items): int
    {
        $pdo = Database::get();
        $count = 0;
        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            $id   = (int)($item['id'] ?? 0);
            if ($type === 'folder') {
                $stmt = $pdo->prepare('SELECT id, parent_id FROM folders WHERE id = :id AND owner_id = :uid LIMIT 1');
                $stmt->execute([':id' => $id, ':uid' => $userId]);
                $folder = $stmt->fetch();
                if ($folder) {
                    // If parent is still trashed or missing, restore to root
                    if ($folder['parent_id'] !== null) {
                        $pStmt = $pdo->prepare('SELECT deleted_at FROM folders WHERE id = :pid LIMIT 1');
                        $pStmt->execute([':pid' => (int)$folder['parent_id']]);
                        $pRow = $pStmt->fetch();
                        if (!$pRow || $pRow['deleted_at'] !== null) {
                            $pdo->prepare('UPDATE folders SET parent_id = NULL WHERE id = :id')->execute([':id' => $id]);
                        }
                    }
                    self::setFolderTreeTrashState($pdo, $id, false);
                    $count++;
                }
            } elseif ($type === 'file') {
                $stmt = $pdo->prepare('SELECT id, folder_id FROM files WHERE id = :id AND owner_id = :uid LIMIT 1');
                $stmt->execute([':id' => $id, ':uid' => $userId]);
                $file = $stmt->fetch();
                if ($file) {
                    if ($file['folder_id'] !== null) {
                        $pStmt = $pdo->prepare('SELECT deleted_at FROM folders WHERE id = :fid LIMIT 1');
                        $pStmt->execute([':fid' => (int)$file['folder_id']]);
                        $pRow = $pStmt->fetch();
                        if (!$pRow || $pRow['deleted_at'] !== null) {
                            $pdo->prepare('UPDATE files SET folder_id = NULL WHERE id = :id')->execute([':id' => $id]);
                        }
                    }
                    $pdo->prepare('UPDATE files SET deleted_at = NULL WHERE id = :id')->execute([':id' => $id]);
                    $count++;
                }
            }
        }
        return $count;
    }

    public static function deletePermanently(int $userId, array $items): int
    {
        $pdo = Database::get();
        $count = 0;
        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            $id   = (int)($item['id'] ?? 0);
            if ($type === 'folder') {
                $stmt = $pdo->prepare('SELECT id FROM folders WHERE id = :id AND owner_id = :uid LIMIT 1');
                $stmt->execute([':id' => $id, ':uid' => $userId]);
                if ($stmt->fetchColumn()) {
                    self::purgeFolderTree($pdo, $id);
                    $count++;
                }
            } elseif ($type === 'file') {
                $stmt = $pdo->prepare('SELECT id, storage_name FROM files WHERE id = :id AND owner_id = :uid LIMIT 1');
                $stmt->execute([':id' => $id, ':uid' => $userId]);
                $file = $stmt->fetch();
                if ($file) {
                    self::unlinkPhysicalFile($file['storage_name']);
                    $pdo->prepare("DELETE FROM public_shares WHERE item_type = 'file' AND item_id = :id")->execute([':id' => $id]);
                    $pdo->prepare("DELETE FROM user_shares WHERE item_type = 'file' AND item_id = :id")->execute([':id' => $id]);
                    $pdo->prepare('DELETE FROM files WHERE id = :id')->execute([':id' => $id]);
                    $count++;
                }
            }
        }
        return $count;
    }

    public static function emptyTrash(int $userId): int
    {
        $pdo = Database::get();
        $fStmt = $pdo->prepare('SELECT id FROM folders WHERE owner_id = :uid AND deleted_at IS NOT NULL');
        $fStmt->execute([':uid' => $userId]);
        $folderIds = $fStmt->fetchAll(PDO::FETCH_COLUMN);
        $count = 0;
        foreach ($folderIds as $fid) {
            self::purgeFolderTree($pdo, (int)$fid);
            $count++;
        }

        $flStmt = $pdo->prepare('SELECT id, storage_name FROM files WHERE owner_id = :uid AND deleted_at IS NOT NULL');
        $flStmt->execute([':uid' => $userId]);
        foreach ($flStmt->fetchAll() as $fl) {
            self::unlinkPhysicalFile($fl['storage_name']);
            $pdo->prepare("DELETE FROM public_shares WHERE item_type = 'file' AND item_id = :id")->execute([':id' => (int)$fl['id']]);
            $pdo->prepare("DELETE FROM user_shares WHERE item_type = 'file' AND item_id = :id")->execute([':id' => (int)$fl['id']]);
            $pdo->prepare('DELETE FROM files WHERE id = :id')->execute([':id' => (int)$fl['id']]);
            $count++;
        }
        return $count;
    }

    public static function purgeFolderTree(PDO $pdo, int $folderId): void
    {
        $subStmt = $pdo->prepare('SELECT id FROM folders WHERE parent_id = :pid');
        $subStmt->execute([':pid' => $folderId]);
        foreach ($subStmt->fetchAll(PDO::FETCH_COLUMN) as $childId) {
            self::purgeFolderTree($pdo, (int)$childId);
        }

        $flStmt = $pdo->prepare('SELECT id, storage_name FROM files WHERE folder_id = :fid');
        $flStmt->execute([':fid' => $folderId]);
        foreach ($flStmt->fetchAll() as $fl) {
            self::unlinkPhysicalFile($fl['storage_name']);
            $pdo->prepare("DELETE FROM public_shares WHERE item_type = 'file' AND item_id = :id")->execute([':id' => (int)$fl['id']]);
            $pdo->prepare("DELETE FROM user_shares WHERE item_type = 'file' AND item_id = :id")->execute([':id' => (int)$fl['id']]);
            $pdo->prepare("DELETE FROM group_shares WHERE item_type = 'file' AND item_id = :id")->execute([':id' => (int)$fl['id']]);
        }
        $pdo->prepare('DELETE FROM files WHERE folder_id = :fid')->execute([':fid' => $folderId]);
        $pdo->prepare("DELETE FROM public_shares WHERE item_type = 'folder' AND item_id = :id")->execute([':id' => $folderId]);
        $pdo->prepare("DELETE FROM user_shares WHERE item_type = 'folder' AND item_id = :id")->execute([':id' => $folderId]);
        $pdo->prepare("DELETE FROM group_shares WHERE item_type = 'folder' AND item_id = :id")->execute([':id' => $folderId]);
        $pdo->prepare('DELETE FROM folders WHERE id = :id')->execute([':id' => $folderId]);
    }

    public static function unlinkPhysicalFile(string $storageName): void
    {
        $storageName = basename($storageName);
        if ($storageName === '') {
            return;
        }
        $filePath = Database::FILES_DIR . '/' . $storageName;
        if (is_file($filePath)) {
            @unlink($filePath);
        }
        self::purgeThumbnail($storageName);
    }

    public static function purgeThumbnail(string $storageName): void
    {
        $storageName = basename($storageName);
        foreach (['.webp', '.jpg'] as $ext) {
            $thumb = Database::THUMBS_DIR . '/' . $storageName . $ext;
            if (is_file($thumb)) {
                @unlink($thumb);
            }
        }
    }

    /**
     * Handles chunked upload or single-shot upload and registers the file in SQLite.
     */
    public static function handleChunkUpload(
        int $userId,
        ?int $folderId,
        string $uploadId,
        int $chunkIndex,
        int $totalChunks,
        string $rawFileName,
        int $totalSize,
        string $chunkTmpPath,
        string $relativePath = ''
    ): array {
        $fileName = self::sanitizeName($rawFileName);
        if ($fileName === '') {
            throw new RuntimeException('Ungültiger Dateiname.');
        }

        $access = self::canWriteFolder($userId, $folderId);
        $effectiveOwnerId = $access['effective_owner_id'];

        if (!Auth::canFitBytes($effectiveOwnerId, $totalSize)) {
            throw new RuntimeException('Speicherplatz-Quota überschritten.');
        }

        $cleanUploadId = preg_replace('/[^a-zA-Z0-9_-]/', '', $uploadId) ?: bin2hex(random_bytes(12));
        $partFile = Database::CHUNKS_DIR . '/' . $userId . '_' . $cleanUploadId . '.part';

        if ($chunkIndex === 0 && is_file($partFile)) {
            @unlink($partFile);
        }

        $in = fopen($chunkTmpPath, 'rb');
        $out = fopen($partFile, $chunkIndex === 0 ? 'wb' : 'ab');
        if (!$in || !$out) {
            throw new RuntimeException('Upload-Chunk konnte nicht verarbeitet werden.');
        }
        stream_copy_to_stream($in, $out);
        fclose($in);
        fclose($out);

        if ($chunkIndex + 1 < $totalChunks) {
            return [
                'completed'   => false,
                'chunk_index' => $chunkIndex,
            ];
        }

        // All chunks received -> finalize file
        $targetFolderId = $folderId;
        if ($relativePath !== '') {
            $relDir = dirname(str_replace('\\', '/', $relativePath));
            if ($relDir !== '' && $relDir !== '.') {
                $targetFolderId = self::ensureSubfolderPath($userId, $folderId, $relDir);
            }
        }

        $fileRecord = self::storeAssembledFile($effectiveOwnerId, $targetFolderId, $fileName, $partFile);
        return [
            'completed' => true,
            'file'      => $fileRecord,
        ];
    }

    public static function storeAssembledFile(int $ownerId, ?int $folderId, string $fileName, string $sourceFilePath): array
    {
        $actualSize = (int)filesize($sourceFilePath);
        if (!Auth::canFitBytes($ownerId, $actualSize)) {
            @unlink($sourceFilePath);
            throw new RuntimeException('Speicherplatz-Quota überschritten.');
        }

        $mimeType = self::detectMimeType($sourceFilePath, $fileName);
        $sha256   = hash_file('sha256', $sourceFilePath) ?: '';
        $pdo      = Database::get();

        // Check if a file with the same name already exists in this folder (overwrite in-place without versioning bloat)
        if ($folderId === null) {
            $chk = $pdo->prepare('SELECT * FROM files WHERE owner_id = :uid AND folder_id IS NULL AND name = :name COLLATE NOCASE AND deleted_at IS NULL LIMIT 1');
            $chk->execute([':uid' => $ownerId, ':name' => $fileName]);
        } else {
            $chk = $pdo->prepare('SELECT * FROM files WHERE owner_id = :uid AND folder_id = :fid AND name = :name COLLATE NOCASE AND deleted_at IS NULL LIMIT 1');
            $chk->execute([':uid' => $ownerId, ':fid' => $folderId, ':name' => $fileName]);
        }
        $existing = $chk->fetch();

        if ($existing) {
            $storageName = $existing['storage_name'];
            $destPath    = Database::FILES_DIR . '/' . $storageName;
            self::purgeThumbnail($storageName);
            if (!rename($sourceFilePath, $destPath)) {
                copy($sourceFilePath, $destPath);
                @unlink($sourceFilePath);
            }
            $upd = $pdo->prepare("
                UPDATE files
                SET mime_type = :mime, size_bytes = :size, sha256 = :sha, is_encrypted = 0, updated_at = datetime('now')
                WHERE id = :id
            ");
            $upd->execute([
                ':mime' => $mimeType,
                ':size' => $actualSize,
                ':sha'  => $sha256,
                ':id'   => (int)$existing['id'],
            ]);
            $fileId = (int)$existing['id'];
        } else {
            $storageName = bin2hex(random_bytes(16));
            $destPath    = Database::FILES_DIR . '/' . $storageName;
            if (!rename($sourceFilePath, $destPath)) {
                copy($sourceFilePath, $destPath);
                @unlink($sourceFilePath);
            }
            $ins = $pdo->prepare("
                INSERT INTO files (owner_id, folder_id, name, storage_name, mime_type, size_bytes, sha256, is_encrypted, created_at, updated_at)
                VALUES (:uid, :fid, :name, :sname, :mime, :size, :sha, 0, datetime('now'), datetime('now'))
            ");
            $ins->execute([
                ':uid'   => $ownerId,
                ':fid'   => $folderId,
                ':name'  => $fileName,
                ':sname' => $storageName,
                ':mime'  => $mimeType,
                ':size'  => $actualSize,
                ':sha'   => $sha256,
            ]);
            $fileId = (int)$pdo->lastInsertId();
        }

        return [
            'id'         => $fileId,
            'name'       => $fileName,
            'size_bytes' => $actualSize,
            'mime_type'  => $mimeType,
        ];
    }

    public static function detectMimeType(string $filePath, string $fileName): string
    {
        $ext = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        $extMap = [
            'txt'  => 'text/plain',
            'md'   => 'text/markdown',
            'csv'  => 'text/csv',
            'json' => 'application/json',
            'pdf'  => 'application/pdf',
            'zip'  => 'application/zip',
            'mp4'  => 'video/mp4',
            'webm' => 'video/webm',
            'mp3'  => 'audio/mpeg',
            'wav'  => 'audio/wav',
            'ogg'  => 'audio/ogg',
            'jpg'  => 'image/jpeg',
            'jpeg' => 'image/jpeg',
            'png'  => 'image/png',
            'gif'  => 'image/gif',
            'webp' => 'image/webp',
            'svg'  => 'image/svg+xml',
        ];
        if (isset($extMap[$ext])) {
            return $extMap[$ext];
        }
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_file($finfo, $filePath);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }
        return 'application/octet-stream';
    }

    public static function saveTextFile(int $userId, ?int $folderId, ?int $fileId, string $rawName, string $content): array
    {
        if ($fileId !== null && $fileId > 0) {
            $access = self::resolveFileAccess($userId, $fileId);
            if (!$access || !in_array($access['permission'], ['owner', 'write'], true)) {
                throw new RuntimeException('Keine Schreibberechtigung für diese Datei.');
            }
            $file = $access['file'];
            if ((int)$file['is_encrypted'] === 1) {
                throw new RuntimeException('Verschlüsselte Dateien müssen vor dem Bearbeiten entschlüsselt werden.');
            }
            $ownerId = (int)$file['owner_id'];
            $diffBytes = strlen($content) - (int)$file['size_bytes'];
            if ($diffBytes > 0 && !Auth::canFitBytes($ownerId, $diffBytes)) {
                throw new RuntimeException('Speicherplatz-Quota überschritten.');
            }
            $filePath = Database::FILES_DIR . '/' . basename($file['storage_name']);
            if (file_put_contents($filePath, $content, LOCK_EX) === false) {
                throw new RuntimeException('Datei konnte nicht gespeichert werden.');
            }
            $newSize = strlen($content);
            $sha256  = hash('sha256', $content);
            $pdo = Database::get();
            $pdo->prepare("UPDATE files SET size_bytes = :s, sha256 = :sha, updated_at = datetime('now') WHERE id = :id")
                ->execute([':s' => $newSize, ':sha' => $sha256, ':id' => $fileId]);
            return ['id' => $fileId, 'name' => $file['name'], 'size_bytes' => $newSize];
        }

        $name = self::sanitizeName($rawName);
        if ($name === '') {
            throw new RuntimeException('Ungültiger Dateiname.');
        }
        if (!str_contains($name, '.')) {
            $name .= '.txt';
        }
        $access = self::canWriteFolder($userId, $folderId);
        $tmp = Database::CHUNKS_DIR . '/txt_' . bin2hex(random_bytes(8)) . '.tmp';
        file_put_contents($tmp, $content);
        return self::storeAssembledFile($access['effective_owner_id'], $folderId, $name, $tmp);
    }

    public static function saveBinaryBase64File(int $userId, ?int $folderId, ?int $fileId, string $rawName, string $b64Data): array
    {
        $rawBytes = base64_decode($b64Data, true);
        if ($rawBytes === false) {
            throw new RuntimeException('Ungültige Base64-Daten.');
        }

        if ($fileId !== null && $fileId > 0) {
            $access = self::resolveFileAccess($userId, $fileId);
            if (!$access || !in_array($access['permission'], ['owner', 'write'], true)) {
                throw new RuntimeException('Keine Schreibberechtigung für diese Datei.');
            }
            $file = $access['file'];
            if ((int)$file['is_encrypted'] === 1) {
                throw new RuntimeException('Verschlüsselte Dateien müssen vor dem Bearbeiten entschlüsselt werden.');
            }
            $ownerId = (int)$file['owner_id'];
            $diffBytes = strlen($rawBytes) - (int)$file['size_bytes'];
            if ($diffBytes > 0 && !Auth::canFitBytes($ownerId, $diffBytes)) {
                throw new RuntimeException('Speicherplatz-Quota überschritten.');
            }
            $filePath = Database::FILES_DIR . '/' . basename($file['storage_name']);
            if (file_put_contents($filePath, $rawBytes, LOCK_EX) === false) {
                throw new RuntimeException('Datei konnte nicht gespeichert werden.');
            }
            $newSize = strlen($rawBytes);
            $sha256  = hash('sha256', $rawBytes);
            $pdo = Database::get();
            $pdo->prepare("UPDATE files SET size_bytes = :s, sha256 = :sha, updated_at = datetime('now') WHERE id = :id")
                ->execute([':s' => $newSize, ':sha' => $sha256, ':id' => $fileId]);
            return ['id' => $fileId, 'name' => $file['name'], 'size_bytes' => $newSize];
        }

        $name = self::sanitizeName($rawName);
        if ($name === '') {
            throw new RuntimeException('Ungültiger Dateiname.');
        }
        $access = self::canWriteFolder($userId, $folderId);
        $tmp = Database::CHUNKS_DIR . '/bin_' . bin2hex(random_bytes(8)) . '.tmp';
        file_put_contents($tmp, $rawBytes);
        return self::storeAssembledFile($access['effective_owner_id'], $folderId, $name, $tmp);
    }

    /**
     * Inspects a .zip file and returns its entries.
     */
    public static function inspectZip(int $userId, int $fileId): array
    {
        $access = self::resolveFileAccess($userId, $fileId);
        if (!$access) {
            throw new RuntimeException('Kein Zugriff auf dieses ZIP-Archiv.');
        }
        $file = $access['file'];
        if ((int)$file['is_encrypted'] === 1) {
            throw new RuntimeException('Verschlüsseltes ZIP-Archiv muss zuerst entschlüsselt werden.');
        }
        $filePath = Database::FILES_DIR . '/' . basename($file['storage_name']);
        if (!is_file($filePath)) {
            throw new RuntimeException('Physische Datei nicht gefunden.');
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('ZIP-Archiv konnte nicht geöffnet werden.');
        }

        $entries = [];
        $totalUncompressed = 0;
        $maxEntries = min($zip->numFiles, 500);
        for ($i = 0; $i < $maxEntries; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) {
                continue;
            }
            $name = $stat['name'];
            $isDir = str_ends_with($name, '/');
            $size = (int)$stat['size'];
            $totalUncompressed += $size;
            $entries[] = [
                'name'   => $name,
                'is_dir' => $isDir,
                'size'   => $size,
            ];
        }
        $totalCount = $zip->numFiles;
        $zip->close();

        return [
            'file_name'          => $file['name'],
            'total_entries'      => $totalCount,
            'total_uncompressed' => $totalUncompressed,
            'entries'            => $entries,
        ];
    }

    /**
     * Safely extracts a .zip file on the server into $targetFolderId (with Zip-Slip and Quota protection).
     */
    public static function extractZip(int $userId, int $fileId, ?int $targetFolderId): array
    {
        $access = self::resolveFileAccess($userId, $fileId);
        if (!$access) {
            throw new RuntimeException('Kein Zugriff auf dieses ZIP-Archiv.');
        }
        $file = $access['file'];
        if ((int)$file['is_encrypted'] === 1) {
            throw new RuntimeException('Verschlüsseltes ZIP-Archiv muss zuerst entschlüsselt werden.');
        }

        if ($targetFolderId === null && $file['folder_id'] !== null) {
            $targetFolderId = (int)$file['folder_id'];
        }
        $folderAccess = self::canWriteFolder($userId, $targetFolderId);
        $effectiveOwnerId = $folderAccess['effective_owner_id'];

        $filePath = Database::FILES_DIR . '/' . basename($file['storage_name']);
        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('ZIP-Archiv konnte nicht geöffnet werden.');
        }

        if ($zip->numFiles > 2500) {
            $zip->close();
            throw new RuntimeException('Das ZIP-Archiv enthält zu viele Einzeldateien (max. 2.500 pro Archiv).');
        }

        $maxExtractBytes = 2 * 1073741824; // 2 GB max per single extraction against ZIP bombs
        $totalUncompressed = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if ($stat) {
                $totalUncompressed += (int)$stat['size'];
            }
        }

        if ($totalUncompressed > $maxExtractBytes) {
            $zip->close();
            throw new RuntimeException('Das entpackte ZIP-Archiv überschreitet das Sicherheitslimit von 2 GB.');
        }

        if (!Auth::canFitBytes($effectiveOwnerId, $totalUncompressed)) {
            $zip->close();
            throw new RuntimeException('Nicht genügend freier Speicherplatz (Quota) zum Entpacken des Archivs.');
        }

        $extractedFiles = 0;
        $actualExtractedBytes = 0;
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            if (!$stat) {
                continue;
            }
            // Skip UNIX symlink entries inside ZIP archives
            if (isset($stat['opsys'], $stat['external_attributes']) && $stat['opsys'] === ZipArchive::OPSYS_UNIX) {
                $mode = ($stat['external_attributes'] >> 16) & 0170000;
                if ($mode === 0120000) {
                    continue;
                }
            }

            $entryName = str_replace('\\', '/', $stat['name']);
            // Prevent Zip-Slip traversal or macOS metadata clutter
            if (str_contains($entryName, '../') || str_starts_with($entryName, '/') || str_starts_with($entryName, '__MACOSX/')) {
                continue;
            }
            if (str_ends_with($entryName, '/')) {
                self::ensureSubfolderPath($userId, $targetFolderId, $entryName);
                continue;
            }

            $baseName = self::sanitizeName(basename($entryName));
            if ($baseName === '') {
                continue;
            }
            $dirName = dirname($entryName);
            $destFolderId = $targetFolderId;
            if ($dirName !== '' && $dirName !== '.') {
                $destFolderId = self::ensureSubfolderPath($userId, $targetFolderId, $dirName);
            }

            $stream = $zip->getStream($stat['name']);
            if (!$stream) {
                continue;
            }
            $tmpFile = Database::CHUNKS_DIR . '/unzip_' . bin2hex(random_bytes(8)) . '.tmp';
            $out = fopen($tmpFile, 'wb');
            while (!feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $actualExtractedBytes += strlen($chunk);
                if ($actualExtractedBytes > $maxExtractBytes) {
                    fclose($stream);
                    fclose($out);
                    @unlink($tmpFile);
                    $zip->close();
                    throw new RuntimeException('Entpacken abgebrochen: ZIP-Bombe oder Größenlimit überschritten.');
                }
                fwrite($out, $chunk);
            }
            fclose($stream);
            fclose($out);

            self::storeAssembledFile($effectiveOwnerId, $destFolderId, $baseName, $tmpFile);
            $extractedFiles++;
        }

        $zip->close();
        return ['extracted_files' => $extractedFiles];
    }

    /**
     * Builds a temporary .zip file from multiple files/folders for download.
     */
    public static function buildMultiItemZip(int $userId, array $items): string
    {
        $tmpZip = Database::CHUNKS_DIR . '/dl_' . bin2hex(random_bytes(10)) . '.zip';
        $zip = new ZipArchive();
        if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('ZIP-Download konnte nicht erstellt werden.');
        }

        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            $id   = (int)($item['id'] ?? 0);
            if ($type === 'file') {
                $access = self::resolveFileAccess($userId, $id);
                if ($access) {
                    $fl = $access['file'];
                    $path = Database::FILES_DIR . '/' . basename($fl['storage_name']);
                    if (is_file($path)) {
                        $zip->addFile($path, $fl['name']);
                    }
                }
            } elseif ($type === 'folder') {
                $access = self::resolveFolderAccess($userId, $id);
                if ($access) {
                    self::addFolderToZipRecursive($userId, (int)$access['folder']['id'], $access['folder']['name'], $zip);
                }
            }
        }

        $zip->close();
        return $tmpZip;
    }

    public static function addFolderToZipRecursive(int $userId, int $folderId, string $zipPrefix, ZipArchive $zip): void
    {
        $zip->addEmptyDir($zipPrefix);
        $pdo = Database::get();

        $flStmt = $pdo->prepare('SELECT name, storage_name FROM files WHERE folder_id = :fid AND deleted_at IS NULL');
        $flStmt->execute([':fid' => $folderId]);
        foreach ($flStmt->fetchAll() as $fl) {
            $path = Database::FILES_DIR . '/' . basename($fl['storage_name']);
            if (is_file($path)) {
                $zip->addFile($path, $zipPrefix . '/' . $fl['name']);
            }
        }

        $subStmt = $pdo->prepare('SELECT id, name FROM folders WHERE parent_id = :pid AND deleted_at IS NULL');
        $subStmt->execute([':pid' => $folderId]);
        foreach ($subStmt->fetchAll() as $sub) {
            self::addFolderToZipRecursive($userId, (int)$sub['id'], $zipPrefix . '/' . $sub['name'], $zip);
        }
    }

    /**
     * Streams a physical file to the client with HTTP 206 Partial Content (Range) support
     * and strict sandboxed Content-Security-Policy against Stored XSS.
     */
    public static function streamPhysicalFile(string $filePath, string $downloadName, string $mimeType, bool $inline = false): never
    {
        if (!is_file($filePath) || !is_readable($filePath)) {
            http_response_code(404);
            exit('Datei nicht gefunden.');
        }

        // Prevent Stored XSS: never serve HTML/XML/JS inline; force attachment and apply strict CSP sandbox
        $lowerMime = strtolower(trim(explode(';', $mimeType)[0]));
        $unsafeInlineMimes = [
            'text/html',
            'application/xhtml+xml',
            'text/xml',
            'application/xml',
            'text/javascript',
            'application/javascript',
            'application/x-javascript',
        ];
        if ($inline && in_array($lowerMime, $unsafeInlineMimes, true)) {
            $inline = false;
        }

        $size = (int)filesize($filePath);
        $disposition = $inline ? 'inline' : 'attachment';
        $safeFilename = str_replace(['"', "\r", "\n"], '', $downloadName);

        header('Content-Type: ' . $mimeType);
        header('Accept-Ranges: bytes');
        header('Content-Disposition: ' . $disposition . '; filename="' . $safeFilename . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
        header('X-Content-Type-Options: nosniff');
        header("Content-Security-Policy: default-src 'none'; img-src 'self' data:; media-src 'self'; style-src 'unsafe-inline'; sandbox");

        $start = 0;
        $end   = $size > 0 ? $size - 1 : 0;

        if (!empty($_SERVER['HTTP_RANGE']) && $size > 0 && preg_match('/bytes=(\d*)-(\d*)/i', (string)$_SERVER['HTTP_RANGE'], $m)) {
            $reqStart = $m[1] !== '' ? (int)$m[1] : null;
            $reqEnd   = $m[2] !== '' ? (int)$m[2] : null;

            if ($reqStart === null && $reqEnd !== null) {
                $start = max(0, $size - $reqEnd);
            } elseif ($reqStart !== null) {
                $start = $reqStart;
                if ($reqEnd !== null && $reqEnd < $size) {
                    $end = $reqEnd;
                }
            }

            if ($start > $end || $start >= $size) {
                http_response_code(416);
                header("Content-Range: bytes */$size");
                exit;
            }

            http_response_code(206);
            header("Content-Range: bytes $start-$end/$size");
            header('Content-Length: ' . ($end - $start + 1));
        } else {
            http_response_code(200);
            header('Content-Length: ' . $size);
        }

        if (strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') {
            exit;
        }

        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $fp = fopen($filePath, 'rb');
        if ($fp) {
            if ($start > 0) {
                fseek($fp, $start);
            }
            $remaining = $end - $start + 1;
            $chunkSize = 65536;
            while (!feof($fp) && $remaining > 0 && !connection_aborted()) {
                $read = min($chunkSize, $remaining);
                $buf  = fread($fp, $read);
                if ($buf === false) {
                    break;
                }
                echo $buf;
                $remaining -= strlen($buf);
                flush();
            }
            fclose($fp);
        }
        exit;
    }

    /**
     * Generates and serves a 240x240 thumbnail for images.
     */
    public static function serveThumbnail(array $file): never
    {
        if ((int)$file['is_encrypted'] === 1) {
            http_response_code(403);
            exit;
        }
        $storageName = basename($file['storage_name']);
        $srcPath     = Database::FILES_DIR . '/' . $storageName;
        if (!is_file($srcPath)) {
            http_response_code(404);
            exit;
        }

        if ($file['mime_type'] === 'image/svg+xml') {
            self::streamPhysicalFile($srcPath, $file['name'], 'image/svg+xml', true);
        }

        $useWebp   = function_exists('imagewebp');
        $thumbExt  = $useWebp ? '.webp' : '.jpg';
        $thumbMime = $useWebp ? 'image/webp' : 'image/jpeg';
        $thumbPath = Database::THUMBS_DIR . '/' . $storageName . $thumbExt;

        if (!is_file($thumbPath) && function_exists('imagecreatefromstring')) {
            $raw = @file_get_contents($srcPath);
            $img = $raw !== false ? @imagecreatefromstring($raw) : false;
            unset($raw);
            if ($img) {
                $w = imagesx($img);
                $h = imagesy($img);
                $maxDim = 240;
                $scale  = min($maxDim / max(1, $w), $maxDim / max(1, $h), 1.0);
                $nw     = max(1, (int)round($w * $scale));
                $nh     = max(1, (int)round($h * $scale));
                $dst    = imagecreatetruecolor($nw, $nh);
                imagealphablending($dst, false);
                imagesavealpha($dst, true);
                imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
                if ($useWebp) {
                    @imagewebp($dst, $thumbPath, 80);
                } else {
                    @imagejpeg($dst, $thumbPath, 82);
                }
                imagedestroy($dst);
                imagedestroy($img);
            }
        }

        if (is_file($thumbPath)) {
            header('Cache-Control: private, max-age=86400');
            self::streamPhysicalFile($thumbPath, 'thumb' . $thumbExt, $thumbMime, true);
        }

        self::streamPhysicalFile($srcPath, $file['name'], $file['mime_type'], true);
    }

    public static function getFolderTree(int $userId): array
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT id, parent_id, name FROM folders WHERE owner_id = :uid AND deleted_at IS NULL ORDER BY name COLLATE NOCASE ASC');
        $stmt->execute([':uid' => $userId]);
        $rows = $stmt->fetchAll();
        return array_map(fn($r) => [
            'id'        => (int)$r['id'],
            'parent_id' => $r['parent_id'] !== null ? (int)$r['parent_id'] : null,
            'name'      => $r['name'],
        ], $rows);
    }

    /**
     * Collects all folder IDs in a folder subtree (the folder itself + all non-deleted descendants).
     */
    public static function collectSubtreeFolderIds(PDO $pdo, int $rootFolderId): array
    {
        $ids   = [$rootFolderId];
        $queue = [$rootFolderId];
        $stmt  = $pdo->prepare('SELECT id FROM folders WHERE parent_id = :pid AND deleted_at IS NULL');
        while (!empty($queue)) {
            $curr = array_shift($queue);
            $stmt->execute([':pid' => $curr]);
            foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $childId) {
                $cid = (int)$childId;
                $ids[]   = $cid;
                $queue[] = $cid;
            }
        }
        return $ids;
    }

    /**
     * Encrypts all unencrypted files in a folder and its subfolders with AES-256-GCM.
     */
    public static function encryptFolderTree(int $userId, int $folderId, string $password): array
    {
        $access = self::resolveFolderAccess($userId, $folderId);
        if (!$access || !in_array($access['permission'], ['owner', 'write'], true)) {
            throw new RuntimeException('Keine Berechtigung zum Verschlüsseln dieses Ordners.');
        }

        $pdo       = Database::get();
        $folderIds = self::collectSubtreeFolderIds($pdo, $folderId);
        $inPlaceholders = implode(',', array_fill(0, count($folderIds), '?'));

        $stmt = $pdo->prepare("
            SELECT id, storage_name, is_encrypted
            FROM files
            WHERE folder_id IN ($inPlaceholders) AND deleted_at IS NULL AND is_encrypted = 0
        ");
        $stmt->execute($folderIds);
        $files = $stmt->fetchAll();

        $encryptedCount = 0;
        $upd = $pdo->prepare("UPDATE files SET is_encrypted = 1, size_bytes = :s, sha256 = '', updated_at = datetime('now') WHERE id = :id");

        foreach ($files as $fl) {
            $path = Database::FILES_DIR . '/' . basename($fl['storage_name']);
            if (!is_file($path)) {
                continue;
            }
            $newSize = CryptoVault::encryptFileInPlace($path, $password);
            self::purgeThumbnail($fl['storage_name']);
            $upd->execute([':s' => $newSize, ':id' => (int)$fl['id']]);
            $encryptedCount++;
        }

        return ['encrypted_count' => $encryptedCount];
    }

    /**
     * Decrypts all encrypted files in a folder and its subfolders with the provided Vault password.
     */
    public static function decryptFolderTree(int $userId, int $folderId, string $password): array
    {
        $access = self::resolveFolderAccess($userId, $folderId);
        if (!$access || !in_array($access['permission'], ['owner', 'write'], true)) {
            throw new RuntimeException('Keine Berechtigung zum Entschlüsseln dieses Ordners.');
        }

        $pdo       = Database::get();
        $folderIds = self::collectSubtreeFolderIds($pdo, $folderId);
        $inPlaceholders = implode(',', array_fill(0, count($folderIds), '?'));

        $stmt = $pdo->prepare("
            SELECT id, name, storage_name
            FROM files
            WHERE folder_id IN ($inPlaceholders) AND deleted_at IS NULL AND is_encrypted = 1
        ");
        $stmt->execute($folderIds);
        $files = $stmt->fetchAll();

        if (empty($files)) {
            throw new RuntimeException('Dieser Ordner enthält keine verschlüsselten Dateien.');
        }

        $decryptedCount = 0;
        $failedCount    = 0;
        $upd = $pdo->prepare("UPDATE files SET is_encrypted = 0, size_bytes = :s, sha256 = :sha, updated_at = datetime('now') WHERE id = :id");

        foreach ($files as $fl) {
            $path = Database::FILES_DIR . '/' . basename($fl['storage_name']);
            if (!is_file($path)) {
                continue;
            }
            try {
                $newSize = CryptoVault::decryptFileInPlace($path, $password);
                $sha256  = hash_file('sha256', $path) ?: '';
                $upd->execute([':s' => $newSize, ':sha' => $sha256, ':id' => (int)$fl['id']]);
                $decryptedCount++;
            } catch (\Throwable) {
                $failedCount++;
            }
        }

        if ($decryptedCount === 0 && $failedCount > 0) {
            throw new RuntimeException('Falsches Tresor-Passwort – keine Datei im Ordner konnte entschlüsselt werden.');
        }

        return [
            'decrypted_count' => $decryptedCount,
            'failed_count'    => $failedCount,
        ];
    }

    /**
     * Encrypts a mixed list of files and/or folders (recursively) in one batch.
     */
    public static function encryptItemsBatch(int $userId, array $items, string $password): int
    {
        $pdo = Database::get();
        $upd = $pdo->prepare("UPDATE files SET is_encrypted = 1, size_bytes = :s, sha256 = '', updated_at = datetime('now') WHERE id = :id");
        $totalEncrypted = 0;

        foreach ($items as $item) {
            $type = $item['type'] ?? '';
            $id   = (int)($item['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            if ($type === 'folder') {
                $res = self::encryptFolderTree($userId, $id, $password);
                $totalEncrypted += (int)$res['encrypted_count'];
            } elseif ($type === 'file') {
                $access = self::resolveFileAccess($userId, $id);
                if (!$access || !in_array($access['permission'], ['owner', 'write'], true)) {
                    continue;
                }
                $fl = $access['file'];
                if ((int)$fl['is_encrypted'] === 1) {
                    continue;
                }
                $path = Database::FILES_DIR . '/' . basename($fl['storage_name']);
                if (!is_file($path)) {
                    continue;
                }
                $newSize = CryptoVault::encryptFileInPlace($path, $password);
                self::purgeThumbnail($fl['storage_name']);
                $upd->execute([':s' => $newSize, ':id' => $id]);
                $totalEncrypted++;
            }
        }

        return $totalEncrypted;
    }
}
