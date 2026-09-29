<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/**
 * Special awards of an activity ("Best in Talent"): the top average in one criterion,
 * or a contestant picked by hand (e.g. People's Choice, Miss Photogenic).
 */
final class AwardRepository extends Repository
{
    public function forActivity(int $activityId): array
    {
        return $this->db->all(
            'SELECT a.id, a.name, a.criterion_id, cr.name AS criterion_name, a.contestant_id, a.sort_order
             FROM awards a LEFT JOIN criteria cr ON cr.id = a.criterion_id
             WHERE a.activity_id = ? ORDER BY a.sort_order, a.id',
            [$activityId]
        );
    }

    /** Replaces the award list of an activity. @param array<int, array{name:string, criterion_id:?int, contestant_id:?int}> $awards */
    public function sync(int $activityId, array $awards): void
    {
        $this->db->transaction(function (Database $db) use ($activityId, $awards) {
            $db->execute('DELETE FROM awards WHERE activity_id = ?', [$activityId]);
            foreach (array_values($awards) as $i => $a) {
                $db->execute(
                    'INSERT INTO awards (activity_id, name, criterion_id, contestant_id, sort_order) VALUES (?, ?, ?, ?, ?)',
                    [$activityId, $a['name'], $a['criterion_id'], $a['contestant_id'], $i + 1]
                );
            }
        });
    }
}
