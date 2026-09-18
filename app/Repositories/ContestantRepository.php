<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Services\EntryLook;

final class ContestantRepository extends Repository
{
    /** Contestants with team name and their look (colour + picture, team fallback). */
    public function forActivity(int $activityId): array
    {
        $rows = $this->db->all(
            'SELECT c.id, c.number, c.name, c.details, c.team_id, c.color, c.photo_file,
                    t.name AS team_name, t.color AS team_color, t.logo_file AS team_logo
             FROM contestants c LEFT JOIN teams t ON t.id = c.team_id
             WHERE c.activity_id = ? ORDER BY c.number, c.name',
            [$activityId]
        );
        foreach ($rows as &$row) {
            $look = EntryLook::of($row);
            $row['own_color'] = $row['color'];
            $row['has_photo'] = !empty($row['photo_file']);
            $row['color'] = $look['color'];
            $row['photo'] = $look['photo'];
            unset($row['photo_file'], $row['team_logo']);
        }
        return $rows;
    }

    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT c.*, a.event_id FROM contestants c JOIN activities a ON a.id = c.activity_id WHERE c.id = ?',
            [$id]
        );
    }

    public function exists(int $activityId): bool
    {
        return (bool) $this->db->value('SELECT 1 FROM contestants WHERE activity_id = ?', [$activityId]);
    }

    public function idsForActivity(int $activityId): array
    {
        return array_map('intval', array_column(
            $this->db->all('SELECT id FROM contestants WHERE activity_id = ?', [$activityId]),
            'id'
        ));
    }

    public function nextNumber(int $activityId): int
    {
        return (int) $this->db->value('SELECT COALESCE(MAX(number), 0) + 1 FROM contestants WHERE activity_id = ?', [$activityId]);
    }

    public function create(array $d): int
    {
        return $this->db->insert(
            'INSERT INTO contestants (activity_id, number, name, details, team_id, color) VALUES (?, ?, ?, ?, ?, ?)',
            [$d['activity_id'], $d['number'], $d['name'], $d['details'], $d['team_id'], $d['color'] ?? null]
        );
    }

    public function update(int $id, int $activityId, array $d): void
    {
        $this->db->execute(
            'UPDATE contestants SET number = ?, name = ?, details = ?, team_id = ?, color = ? WHERE id = ? AND activity_id = ?',
            [$d['number'], $d['name'], $d['details'], $d['team_id'], $d['color'] ?? null, $id, $activityId]
        );
    }

    public function setPhoto(int $id, ?string $key): void
    {
        $this->db->execute('UPDATE contestants SET photo_file = ? WHERE id = ?', [$key, $id]);
    }

    public function delete(int $id, int $activityId): void
    {
        $this->db->execute('DELETE FROM contestants WHERE id = ? AND activity_id = ?', [$id, $activityId]);
    }

    /** Stored picture keys (for file-server cleanup when deleting). */
    public function photoKeysForActivity(int $activityId): array
    {
        return array_column($this->db->all('SELECT photo_file FROM contestants WHERE activity_id = ? AND photo_file IS NOT NULL', [$activityId]), 'photo_file');
    }

    public function photoKeysForEvent(int $eventId): array
    {
        return array_column($this->db->all(
            'SELECT c.photo_file FROM contestants c JOIN activities a ON a.id = c.activity_id WHERE a.event_id = ? AND c.photo_file IS NOT NULL',
            [$eventId]
        ), 'photo_file');
    }
}
