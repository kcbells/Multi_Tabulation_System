<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Services\EntryLook;

final class TeamRepository extends Repository
{
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM teams WHERE id = ?', [$id]);
    }

    public function forEvent(int $eventId): array
    {
        $rows = $this->db->all(
            'SELECT t.id, t.name, t.color, t.logo_file, (SELECT COUNT(*) FROM contestants c WHERE c.team_id = t.id) AS contestants
             FROM teams t WHERE t.event_id = ? ORDER BY t.name',
            [$eventId]
        );
        foreach ($rows as &$row) {
            $row['color'] = EntryLook::validColor($row['color']);
            $row['has_logo'] = !empty($row['logo_file']);
            $row['photo'] = $row['has_logo'] ? ['r' => 'media.team', 'id' => (int) $row['id'], 'v' => substr(md5((string) $row['logo_file']), 0, 8)] : null;
            unset($row['logo_file']);
        }
        return $rows;
    }

    public function belongsToEvent(int $teamId, int $eventId): bool
    {
        return (bool) $this->db->value('SELECT 1 FROM teams WHERE id = ? AND event_id = ?', [$teamId, $eventId]);
    }

    /** Team of the event whose name matches (ignoring case, spaces and punctuation). */
    public function findByName(int $eventId, string $name): ?array
    {
        $key = strtolower(preg_replace('/[^a-z0-9]+/i', '', $name));
        if ($key === '') {
            return null;
        }
        foreach ($this->db->all('SELECT id, name FROM teams WHERE event_id = ?', [$eventId]) as $team) {
            if (strtolower(preg_replace('/[^a-z0-9]+/i', '', $team['name'])) === $key) {
                return $team;
            }
        }
        return null;
    }

    public function nameExists(int $eventId, string $name, int $exceptId = 0): bool
    {
        return (bool) $this->db->value('SELECT 1 FROM teams WHERE event_id = ? AND name = ? AND id <> ?', [$eventId, $name, $exceptId]);
    }

    public function create(int $eventId, string $name, ?string $color = null): int
    {
        return $this->db->insert('INSERT INTO teams (event_id, name, color) VALUES (?, ?, ?)', [$eventId, $name, $color]);
    }

    public function update(int $id, string $name, ?string $color): void
    {
        $this->db->execute('UPDATE teams SET name = ?, color = ? WHERE id = ?', [$name, $color, $id]);
    }

    public function setLogo(int $id, ?string $key): void
    {
        $this->db->execute('UPDATE teams SET logo_file = ? WHERE id = ?', [$key, $id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM teams WHERE id = ?', [$id]);
    }

    public function logoKeysForEvent(int $eventId): array
    {
        return array_column($this->db->all('SELECT logo_file FROM teams WHERE event_id = ? AND logo_file IS NOT NULL', [$eventId]), 'logo_file');
    }

    /** Teams of the event that have no contestant entry in the activity yet. */
    public function withoutEntry(int $eventId, int $activityId): array
    {
        return $this->db->all(
            'SELECT t.id, t.name FROM teams t
             WHERE t.event_id = ? AND NOT EXISTS (SELECT 1 FROM contestants c WHERE c.activity_id = ? AND c.team_id = t.id)
             ORDER BY t.name',
            [$eventId, $activityId]
        );
    }
}
