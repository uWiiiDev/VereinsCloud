<?php
declare(strict_types=1);

namespace Cloud;

use PDO;
use RuntimeException;

class CalendarManager
{
    public static function listEvents(int $userId, bool $isAdmin, bool $canManageCalendar, string $monthYm = ''): array
    {
        if (!preg_match('/^\d{4}-\d{2}$/', $monthYm)) {
            $monthYm = date('Y-m');
        }

        $firstDay = $monthYm . '-01';
        $tsFirst  = strtotime($firstDay) ?: time();
        // Include 7 days before and 14 days after month boundaries for calendar grid
        $rangeStart = date('Y-m-d', strtotime('-7 days', $tsFirst));
        $rangeEnd   = date('Y-m-d', strtotime('+42 days', $tsFirst)) . ' 23:59:59';
        $today      = date('Y-m-d');

        $pdo = Database::get();

        $accessSql = $isAdmin
            ? '1=1'
            : '(e.group_id IS NULL OR e.creator_id = :uid OR EXISTS (SELECT 1 FROM group_members gm WHERE gm.group_id = e.group_id AND gm.user_id = :uid2))';

        $stmt = $pdo->prepare("
            SELECT e.*, u.display_name AS creator_name, g.name AS group_name
            FROM calendar_events e
            JOIN users u ON u.id = e.creator_id
            LEFT JOIN groups g ON g.id = e.group_id
            WHERE ($accessSql)
              AND (
                  (e.start_at >= :rstart AND e.start_at <= :rend)
                  OR (e.end_at IS NOT NULL AND e.end_at >= :rstart2 AND e.start_at <= :rend2)
              )
            ORDER BY e.start_at ASC, e.id ASC
        ");
        $params = [
            ':rstart'  => $rangeStart,
            ':rend'    => $rangeEnd,
            ':rstart2' => $rangeStart,
            ':rend2'   => $rangeEnd,
        ];
        if (!$isAdmin) {
            $params[':uid']  = $userId;
            $params[':uid2'] = $userId;
        }
        $stmt->execute($params);
        $monthEvents = array_map(fn($r) => self::formatEvent($r, $userId, $isAdmin, $canManageCalendar), $stmt->fetchAll());

        // Upcoming events from today
        $upStmt = $pdo->prepare("
            SELECT e.*, u.display_name AS creator_name, g.name AS group_name
            FROM calendar_events e
            JOIN users u ON u.id = e.creator_id
            LEFT JOIN groups g ON g.id = e.group_id
            WHERE ($accessSql)
              AND (e.start_at >= :today OR (e.end_at IS NOT NULL AND e.end_at >= :today2))
            ORDER BY e.start_at ASC, e.id ASC
            LIMIT 20
        ");
        $upParams = [
            ':today'  => $today,
            ':today2' => $today,
        ];
        if (!$isAdmin) {
            $upParams[':uid']  = $userId;
            $upParams[':uid2'] = $userId;
        }
        $upStmt->execute($upParams);
        $upcoming = array_map(fn($r) => self::formatEvent($r, $userId, $isAdmin, $canManageCalendar), $upStmt->fetchAll());

        $groups = array_map(fn($g) => [
            'id'   => (int)$g['id'],
            'name' => $g['name'],
        ], $pdo->query('SELECT id, name FROM groups ORDER BY name COLLATE NOCASE ASC')->fetchAll());

        return [
            'month'      => $monthYm,
            'can_manage' => $isAdmin || $canManageCalendar,
            'events'     => $monthEvents,
            'upcoming'   => $upcoming,
            'groups'     => $groups,
        ];
    }

    private static function formatEvent(array $r, int $userId, bool $isAdmin, bool $canManageCalendar): array
    {
        $creatorId = (int)$r['creator_id'];
        return [
            'id'           => (int)$r['id'],
            'creator_id'   => $creatorId,
            'creator_name' => $r['creator_name'] ?? '',
            'group_id'     => $r['group_id'] !== null ? (int)$r['group_id'] : null,
            'group_name'   => $r['group_name'] ?? null,
            'title'        => $r['title'],
            'start_at'     => $r['start_at'],
            'end_at'       => $r['end_at'],
            'all_day'      => (int)$r['all_day'] === 1,
            'location'     => $r['location'] ?? '',
            'description'  => $r['description'] ?? '',
            'color'        => $r['color'] ?: '#3b82f6',
            'can_edit'     => $isAdmin || ($canManageCalendar && $creatorId === $userId) || $canManageCalendar,
        ];
    }

    public static function saveEvent(int $userId, bool $isAdmin, ?int $eventId, array $input): int
    {
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 160) {
            throw new RuntimeException('Bitte geben Sie einen gültigen Titel für den Termin ein.');
        }

        $allDay   = !empty($input['all_day']) ? 1 : 0;
        $startRaw = trim((string)($input['start_at'] ?? ''));
        $endRaw   = trim((string)($input['end_at'] ?? ''));

        if ($startRaw === '') {
            throw new RuntimeException('Bitte geben Sie ein Startdatum an.');
        }

        $startAt = str_replace('T', ' ', $startRaw);
        $endAt   = $endRaw !== '' ? str_replace('T', ' ', $endRaw) : null;
        if ($allDay) {
            $startAt = substr($startAt, 0, 10);
            if ($endAt !== null) {
                $endAt = substr($endAt, 0, 10);
            }
        }

        $groupId     = !empty($input['group_id']) && (int)$input['group_id'] > 0 ? (int)$input['group_id'] : null;
        $location    = mb_substr(trim((string)($input['location'] ?? '')), 0, 200);
        $description = mb_substr(trim((string)($input['description'] ?? '')), 0, 4000);
        $allowedColors = ['#3b82f6', '#10b981', '#ef4444', '#f59e0b', '#8b5cf6', '#ec4899'];
        $color = in_array($input['color'] ?? '', $allowedColors, true) ? (string)$input['color'] : '#3b82f6';

        $pdo = Database::get();

        if ($eventId !== null && $eventId > 0) {
            $chk = $pdo->prepare('SELECT creator_id FROM calendar_events WHERE id = :id LIMIT 1');
            $chk->execute([':id' => $eventId]);
            $row = $chk->fetch();
            if (!$row) {
                throw new RuntimeException('Termin nicht gefunden.');
            }
            if (!$isAdmin && (int)$row['creator_id'] !== $userId) {
                throw new RuntimeException('Sie können nur eigene Termine bearbeiten.');
            }

            $upd = $pdo->prepare('
                UPDATE calendar_events
                SET group_id = :gid, title = :t, start_at = :sa, end_at = :ea, all_day = :ad,
                    location = :loc, description = :desc, color = :col
                WHERE id = :id
            ');
            $upd->execute([
                ':gid'  => $groupId,
                ':t'    => $title,
                ':sa'   => $startAt,
                ':ea'   => $endAt,
                ':ad'   => $allDay,
                ':loc'  => $location,
                ':desc' => $description,
                ':col'  => $color,
                ':id'   => $eventId,
            ]);
            return $eventId;
        }

        $ins = $pdo->prepare("
            INSERT INTO calendar_events (creator_id, group_id, title, start_at, end_at, all_day, location, description, color, created_at)
            VALUES (:uid, :gid, :t, :sa, :ea, :ad, :loc, :desc, :col, datetime('now'))
        ");
        $ins->execute([
            ':uid'  => $userId,
            ':gid'  => $groupId,
            ':t'    => $title,
            ':sa'   => $startAt,
            ':ea'   => $endAt,
            ':ad'   => $allDay,
            ':loc'  => $location,
            ':desc' => $description,
            ':col'  => $color,
        ]);

        return (int)$pdo->lastInsertId();
    }

    public static function deleteEvent(int $userId, bool $isAdmin, int $eventId): void
    {
        $pdo = Database::get();
        $chk = $pdo->prepare('SELECT creator_id FROM calendar_events WHERE id = :id LIMIT 1');
        $chk->execute([':id' => $eventId]);
        $row = $chk->fetch();
        if (!$row) {
            throw new RuntimeException('Termin nicht gefunden.');
        }
        if (!$isAdmin && (int)$row['creator_id'] !== $userId) {
            throw new RuntimeException('Sie können nur eigene Termine löschen.');
        }
        $pdo->prepare('DELETE FROM calendar_events WHERE id = :id')->execute([':id' => $eventId]);
    }

    public static function streamEventIcs(int $userId, bool $isAdmin, int $eventId): never
    {
        $pdo = Database::get();
        $stmt = $pdo->prepare('SELECT * FROM calendar_events WHERE id = :id LIMIT 1');
        $stmt->execute([':id' => $eventId]);
        $ev = $stmt->fetch();
        if (!$ev) {
            http_response_code(404);
            exit('Termin nicht gefunden.');
        }

        $allDay = (int)$ev['all_day'] === 1;
        $startTs = strtotime((string)$ev['start_at']) ?: time();
        $endTs   = !empty($ev['end_at']) ? (strtotime((string)$ev['end_at']) ?: $startTs) : ($allDay ? $startTs : $startTs + 3600);

        if ($allDay) {
            $dtStart = 'DTSTART;VALUE=DATE:' . date('Ymd', $startTs);
            // In iCalendar, DTEND for all-day events is exclusive (+1 day)
            $dtEnd   = 'DTEND;VALUE=DATE:' . date('Ymd', strtotime('+1 day', $endTs));
        } else {
            $dtStart = 'DTSTART:' . date('Ymd\THis', $startTs);
            $dtEnd   = 'DTEND:' . date('Ymd\THis', $endTs);
        }

        $escIcs = fn(string $s) => str_replace(["\r\n", "\n", "\r", ',', ';'], ['\n', '\n', '', '\,', '\;'], $s);

        $lines = [
            'BEGIN:VCALENDAR',
            'VERSION:2.0',
            'PRODID:-//VereinsCloud//DE',
            'CALSCALE:GREGORIAN',
            'BEGIN:VEVENT',
            'UID:vereinscloud-event-' . $ev['id'] . '@' . ($_SERVER['HTTP_HOST'] ?? 'localhost'),
            'DTSTAMP:' . gmdate('Ymd\THis\Z'),
            $dtStart,
            $dtEnd,
            'SUMMARY:' . $escIcs((string)$ev['title']),
            'LOCATION:' . $escIcs((string)$ev['location']),
            'DESCRIPTION:' . $escIcs((string)$ev['description']),
            'END:VEVENT',
            'END:VCALENDAR',
        ];

        $ics = implode("\r\n", $lines) . "\r\n";
        $safeName = preg_replace('/[^a-zA-Z0-9._-]/', '_', (string)$ev['title']) ?: 'termin';

        header('Content-Type: text/calendar; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $safeName . '.ics"');
        header('Content-Length: ' . strlen($ics));
        echo $ics;
        exit;
    }
}
