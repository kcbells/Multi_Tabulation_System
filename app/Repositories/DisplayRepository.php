<?php
declare(strict_types=1);

namespace App\Repositories;

/** What an event's big screen shows right now (one row per event). */
final class DisplayRepository extends Repository
{
    /** @return array{state: array, version: int} */
    public function get(int $eventId): array
    {
        $row = $this->db->one('SELECT state, version FROM display_state WHERE event_id = ?', [$eventId]);
        $state = $row ? json_decode((string) $row['state'], true) : null;
        return ['state' => is_array($state) ? $state : [], 'version' => $row ? (int) $row['version'] : 0];
    }

    /** Saves the state and bumps the version the screens watch. Returns the new version. */
    public function save(int $eventId, array $state): int
    {
        $this->db->execute(
            'INSERT INTO display_state (event_id, state, version) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE state = VALUES(state), version = version + 1',
            [$eventId, json_encode($state, JSON_UNESCAPED_UNICODE)]
        );
        return (int) $this->db->value('SELECT version FROM display_state WHERE event_id = ?', [$eventId]);
    }
}
