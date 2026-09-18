<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class CriterionRepository extends Repository
{
    public function forActivity(int $activityId): array
    {
        return $this->db->all(
            'SELECT id, name, description, max_score FROM criteria WHERE activity_id = ? ORDER BY sort_order, id',
            [$activityId]
        );
    }

    public function exists(int $activityId): bool
    {
        return (bool) $this->db->value('SELECT 1 FROM criteria WHERE activity_id = ?', [$activityId]);
    }

    /** @return array<int, float> criterion id => max score */
    public function maxScores(int $activityId): array
    {
        $map = [];
        foreach ($this->db->all('SELECT id, max_score FROM criteria WHERE activity_id = ?', [$activityId]) as $row) {
            $map[(int) $row['id']] = (float) $row['max_score'];
        }
        return $map;
    }

    /**
     * Saves the full ordered list: updates rows that carry an existing id,
     * inserts new rows and deletes rows that are no longer present.
     */
    public function sync(int $activityId, array $criteria): void
    {
        $existing = $this->maxScores($activityId);
        $this->db->transaction(function (Database $db) use ($activityId, $criteria, $existing) {
            $keep = [];
            foreach (array_values($criteria) as $order => $c) {
                if ($c['id'] > 0 && isset($existing[$c['id']])) {
                    $db->execute(
                        'UPDATE criteria SET name = ?, description = ?, max_score = ?, sort_order = ? WHERE id = ? AND activity_id = ?',
                        [$c['name'], $c['description'] ?: null, $c['max_score'], $order, $c['id'], $activityId]
                    );
                    $keep[] = $c['id'];
                } else {
                    $keep[] = $db->insert(
                        'INSERT INTO criteria (activity_id, name, description, max_score, sort_order) VALUES (?, ?, ?, ?, ?)',
                        [$activityId, $c['name'], $c['description'] ?: null, $c['max_score'], $order]
                    );
                }
            }
            $db->execute(
                'DELETE FROM criteria WHERE activity_id = ? AND id NOT IN (' . Database::placeholders($keep) . ')',
                array_merge([$activityId], $keep)
            );
        });
    }
}
