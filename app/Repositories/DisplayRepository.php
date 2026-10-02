<?php
declare(strict_types=1);

namespace App\Repositories;

/**
 * What a big screen shows right now: the event's main screen (one row per event)
 * or the screen of one activity (one row per activity, for activities held in other venues).
 */
final class DisplayRepository extends Repository
{
    /** @return array{state: array, version: int} */
    public function get(int $eventId, int $activityId = 0): array
    {
        $row = $activityId > 0
            ? $this->db->one('SELECT state, version FROM activity_display_state WHERE activity_id = ?', [$activityId])
            : $this->db->one('SELECT state, version FROM display_state WHERE event_id = ?', [$eventId]);
        $state = $row ? json_decode((string) $row['state'], true) : null;
        return ['state' => is_array($state) ? $state : [], 'version' => $row ? (int) $row['version'] : 0];
    }

    /** Saves the state and bumps the version the screens watch. Returns the new version. */
    public function save(int $eventId, array $state, int $activityId = 0): int
    {
        [$table, $key, $id] = $activityId > 0 ? ['activity_display_state', 'activity_id', $activityId] : ['display_state', 'event_id', $eventId];
        $this->db->execute(
            "INSERT INTO $table ($key, state, version) VALUES (?, ?, 1)
             ON DUPLICATE KEY UPDATE state = VALUES(state), version = version + 1",
            [$id, json_encode($state, JSON_UNESCAPED_UNICODE)]
        );
        return (int) $this->db->value("SELECT version FROM $table WHERE $key = ?", [$id]);
    }
}
