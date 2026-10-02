<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class AccessCodeRepository extends Repository
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I look-alikes

    public static function normalize(string $code): string
    {
        return strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    public static function format(string $code): string
    {
        return strlen($code) === 8 ? substr($code, 0, 4) . '-' . substr($code, 4) : $code;
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM access_codes WHERE id = ?', [$id]);
    }

    public function findByCode(string $code): ?array
    {
        return $this->db->one(
            'SELECT ac.*, e.title AS event_title, e.archived_at IS NOT NULL AS event_archived FROM access_codes ac JOIN events e ON e.id = ac.event_id WHERE ac.code = ?',
            [self::normalize($code)]
        );
    }

    public function forEvent(int $eventId): array
    {
        $rows = $this->db->all(
            'SELECT ac.id, ac.code, ac.role, ac.name, ac.is_active, ac.last_used_at, ac.created_at,
                    CONCAT_WS(",", GROUP_CONCAT(DISTINCT ja.activity_id), GROUP_CONCAT(DISTINCT fa.activity_id)) AS activity_ids
             FROM access_codes ac
             LEFT JOIN judge_activities ja ON ja.judge_id = ac.id
             LEFT JOIN facilitator_activities fa ON fa.code_id = ac.id
             WHERE ac.event_id = ? GROUP BY ac.id ORDER BY ac.role DESC, ac.name',
            [$eventId]
        );
        foreach ($rows as &$row) {
            $row['display_code'] = self::format($row['code']);
            $row['activity_ids'] = $row['activity_ids'] ? array_map('intval', explode(',', $row['activity_ids'])) : [];
        }
        return $rows;
    }

    public function judgesForEvent(int $eventId): array
    {
        return $this->db->all(
            "SELECT id, name, is_active FROM access_codes WHERE event_id = ? AND role = 'judge' ORDER BY name",
            [$eventId]
        );
    }

    public function create(int $eventId, string $role, string $name): int
    {
        return $this->db->insert(
            'INSERT INTO access_codes (event_id, code, role, name) VALUES (?, ?, ?, ?)',
            [$eventId, $this->uniqueCode(), $role, $name]
        );
    }

    /** Names of an event's codes for one role (used to continue "Judge 1, Judge 2…" numbering). */
    public function namesForRole(int $eventId, string $role): array
    {
        return array_column($this->db->all('SELECT name FROM access_codes WHERE event_id = ? AND role = ?', [$eventId, $role]), 'name');
    }

    public function rename(int $id, string $name): void
    {
        $this->db->execute('UPDATE access_codes SET name = ? WHERE id = ?', [$name, $id]);
    }

    public function regenerate(int $id): string
    {
        $code = $this->uniqueCode();
        $this->db->execute('UPDATE access_codes SET code = ? WHERE id = ?', [$code, $id]);
        return $code;
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->execute('UPDATE access_codes SET is_active = ? WHERE id = ?', [$active ? 1 : 0, $id]);
    }

    public function touch(int $id): void
    {
        $this->db->execute('UPDATE access_codes SET last_used_at = NOW() WHERE id = ?', [$id]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM access_codes WHERE id = ?', [$id]);
    }

    /** Replaces a judge's activity assignments with activities from the same event. */
    public function syncActivities(int $judgeId, int $eventId, array $activityIds): void
    {
        $this->syncLinks('judge_activities', 'judge_id', $judgeId, $eventId, $activityIds);
    }

    /** The activities a facilitator handles (their big screens). None = the whole event. */
    public function syncFacilitatorActivities(int $codeId, int $eventId, array $activityIds): void
    {
        $this->syncLinks('facilitator_activities', 'code_id', $codeId, $eventId, $activityIds);
    }

    /** @return int[] activity ids of a facilitator; empty = the whole event */
    public function facilitatorActivityIds(int $codeId): array
    {
        return array_map('intval', array_column($this->db->all('SELECT activity_id FROM facilitator_activities WHERE code_id = ?', [$codeId]), 'activity_id'));
    }

    private function syncLinks(string $table, string $column, int $codeId, int $eventId, array $activityIds): void
    {
        $activityIds = array_values(array_unique(array_map('intval', $activityIds)));
        $this->db->transaction(function (Database $db) use ($table, $column, $codeId, $eventId, $activityIds) {
            $valid = $activityIds ? array_map('intval', array_column($db->all(
                'SELECT id FROM activities WHERE event_id = ? AND id IN (' . Database::placeholders($activityIds) . ')',
                array_merge([$eventId], $activityIds)
            ), 'id')) : [];
            if ($valid) {
                $db->execute(
                    "DELETE FROM $table WHERE $column = ? AND activity_id NOT IN (" . Database::placeholders($valid) . ')',
                    array_merge([$codeId], $valid)
                );
            } else {
                $db->execute("DELETE FROM $table WHERE $column = ?", [$codeId]);
            }
            foreach ($valid as $aid) {
                $db->execute("INSERT IGNORE INTO $table ($column, activity_id) VALUES (?, ?)", [$codeId, $aid]);
            }
        });
    }

    private function uniqueCode(): string
    {
        do {
            $code = '';
            for ($i = 0; $i < 8; $i++) {
                $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
        } while ($this->db->value('SELECT 1 FROM access_codes WHERE code = ?', [$code]));
        return $code;
    }
}
