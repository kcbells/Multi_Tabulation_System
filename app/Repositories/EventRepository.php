<?php
declare(strict_types=1);

namespace App\Repositories;

final class EventRepository extends Repository
{
    public function exists(int $id): bool
    {
        return (bool) $this->db->value('SELECT 1 FROM events WHERE id = ?', [$id]);
    }

    public function isOwnedBy(int $id, int $userId): bool
    {
        return (bool) $this->db->value('SELECT 1 FROM events WHERE id = ? AND owner_id = ?', [$id, $userId]);
    }

    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT e.*, u.name AS owner_name, ab.name AS archived_by_name FROM events e LEFT JOIN users u ON u.id = e.owner_id LEFT JOIN users ab ON ab.id = e.archived_by WHERE e.id = ?',
            [$id]
        );
    }

    /** All events (admin) or only those owned by $ownerId, with summary counts. */
    public function listWithStats(?int $ownerId): array
    {
        $where = $ownerId === null ? '1=1' : 'e.owner_id = ?';
        return $this->db->all(
            "SELECT e.*, u.name AS owner_name, ab.name AS archived_by_name,
                    (SELECT COUNT(*) FROM activities a WHERE a.event_id = e.id) AS activities,
                    (SELECT COUNT(*) FROM activities a WHERE a.event_id = e.id AND a.status = 'open') AS open_activities,
                    (SELECT COUNT(*) FROM activities a WHERE a.event_id = e.id AND a.status = 'closed') AS closed_activities,
                    (SELECT COUNT(*) FROM teams t WHERE t.event_id = e.id) AS teams,
                    (SELECT COUNT(*) FROM access_codes c WHERE c.event_id = e.id AND c.role = 'judge') AS judges
             FROM events e LEFT JOIN users u ON u.id = e.owner_id LEFT JOIN users ab ON ab.id = e.archived_by
             WHERE $where
             ORDER BY e.archived_at IS NOT NULL, FIELD(e.status, 'ongoing', 'upcoming', 'draft', 'completed', 'cancelled'), e.start_at DESC, e.id DESC",
            $ownerId === null ? [] : [$ownerId]
        );
    }

    public function create(array $d): int
    {
        return $this->db->insert(
            'INSERT INTO events (title, description, venue, nature, start_at, end_at, start_date, end_date, status, default_format, structure, placement_points, participation_points, owner_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$d['title'], $d['description'], $d['venue'], $d['nature'], $d['start_at'], $d['end_at'], $d['start_date'], $d['end_date'], $d['status'],
             $d['default_format'], $d['structure'] ?? 'multi', $d['placement_points'], $d['participation_points'], $d['owner_id']]
        );
    }

    public function update(int $id, array $d): void
    {
        $this->db->execute(
            'UPDATE events SET title = ?, description = ?, venue = ?, nature = ?, start_at = ?, end_at = ?, start_date = ?, end_date = ?, status = ?,
                    default_format = ?, structure = ?, placement_points = ?, participation_points = ?, owner_id = ? WHERE id = ?',
            [$d['title'], $d['description'], $d['venue'], $d['nature'], $d['start_at'], $d['end_at'], $d['start_date'], $d['end_date'], $d['status'],
             $d['default_format'], $d['structure'] ?? 'multi', $d['placement_points'], $d['participation_points'], $d['owner_id'], $id]
        );
    }

    public function setArchived(int $id, ?int $userId): void
    {
        $this->db->execute(
            'UPDATE events SET archived_at = ' . ($userId === null ? 'NULL' : 'NOW()') . ', archived_by = ? WHERE id = ?',
            [$userId, $id]
        );
    }

    public function isArchived(int $id): bool
    {
        return (bool) $this->db->value('SELECT 1 FROM events WHERE id = ? AND archived_at IS NOT NULL', [$id]);
    }

    /** Row counts shown before an event is deleted. */
    public function contentCounts(int $id): array
    {
        return $this->db->one(
            'SELECT (SELECT COUNT(*) FROM activities WHERE event_id = ?) AS activities,
                    (SELECT COUNT(*) FROM teams WHERE event_id = ?) AS teams,
                    (SELECT COUNT(*) FROM contestants c JOIN activities a ON a.id = c.activity_id WHERE a.event_id = ?) AS contestants,
                    (SELECT COUNT(*) FROM access_codes WHERE event_id = ?) AS codes,
                    (SELECT COUNT(*) FROM scores s JOIN contestants c ON c.id = s.contestant_id JOIN activities a ON a.id = c.activity_id WHERE a.event_id = ?) AS scores',
            [$id, $id, $id, $id, $id]
        ) ?? [];
    }

    public function setDocument(int $id, ?string $key, ?string $name, ?string $text): void
    {
        $this->db->execute('UPDATE events SET program_file = ?, program_file_name = ?, program_text = ? WHERE id = ?', [$key, $name, $text, $id]);
    }

    public function setDefaultFormat(int $id, string $format): void
    {
        $this->db->execute('UPDATE events SET default_format = ? WHERE id = ?', [$format, $id]);
    }

    public function transaction(callable $fn)
    {
        return $this->db->transaction($fn);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM events WHERE id = ?', [$id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->execute('UPDATE events SET status = ? WHERE id = ?', [$status, $id]);
    }
}
