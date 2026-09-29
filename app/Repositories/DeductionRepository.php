<?php
declare(strict_types=1);

namespace App\Repositories;

/** Penalties taken off a contestant's final score (score-based activities). */
final class DeductionRepository extends Repository
{
    public function forActivity(int $activityId): array
    {
        return $this->db->all(
            'SELECT d.id, d.contestant_id, c.number AS contestant_number, c.name AS contestant_name, d.points, d.reason, d.created_by, d.created_at
             FROM deductions d JOIN contestants c ON c.id = d.contestant_id
             WHERE d.activity_id = ? ORDER BY d.created_at, d.id',
            [$activityId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM deductions WHERE id = ?', [$id]);
    }

    public function create(int $activityId, int $contestantId, float $points, string $reason, ?string $by): int
    {
        return $this->db->insert(
            'INSERT INTO deductions (activity_id, contestant_id, points, reason, created_by) VALUES (?, ?, ?, ?, ?)',
            [$activityId, $contestantId, $points, $reason, $by]
        );
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM deductions WHERE id = ?', [$id]);
    }
}
