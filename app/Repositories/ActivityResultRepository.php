<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Ranking format: one result value per contestant. */
final class ActivityResultRepository extends Repository
{
    /** @return array<int, array{value:?float, remarks:?string}> contestant id => result */
    public function forActivity(int $activityId): array
    {
        $map = [];
        foreach ($this->db->all('SELECT contestant_id, value, remarks FROM activity_results WHERE activity_id = ?', [$activityId]) as $r) {
            $map[(int) $r['contestant_id']] = [
                'value' => $r['value'] === null ? null : (float) $r['value'],
                'remarks' => $r['remarks'],
            ];
        }
        return $map;
    }

    /** @param array<int, array{contestant_id:int, value:?float, remarks:?string}> $rows */
    public function saveMany(int $activityId, array $rows): void
    {
        $this->db->transaction(function (Database $db) use ($activityId, $rows) {
            foreach ($rows as $r) {
                if ($r['value'] === null && ($r['remarks'] ?? '') === '') {
                    $db->execute('DELETE FROM activity_results WHERE activity_id = ? AND contestant_id = ?', [$activityId, $r['contestant_id']]);
                    continue;
                }
                $db->execute(
                    'INSERT INTO activity_results (activity_id, contestant_id, value, remarks) VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE value = VALUES(value), remarks = VALUES(remarks)',
                    [$activityId, $r['contestant_id'], $r['value'], $r['remarks'] ?: null]
                );
            }
        });
    }

    public function hasResults(int $activityId): bool
    {
        return (bool) $this->db->value('SELECT 1 FROM activity_results WHERE activity_id = ? AND value IS NOT NULL LIMIT 1', [$activityId]);
    }
}
