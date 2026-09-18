<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

/** Games of bracket and round robin activities. */
final class MatchRepository extends Repository
{
    public function forActivity(int $activityId): array
    {
        return $this->db->all(
            "SELECT m.*,
                    ca.name AS a_name, ca.number AS a_number, ta.name AS a_team,
                    ca.id AS a_id, ca.color AS a_color, ca.photo_file AS a_photo_file, ca.team_id AS a_team_id, ta.color AS a_team_color, ta.logo_file AS a_team_logo,
                    cb.name AS b_name, cb.number AS b_number, tb.name AS b_team,
                    cb.id AS b_id, cb.color AS b_color, cb.photo_file AS b_photo_file, cb.team_id AS b_team_id, tb.color AS b_team_color, tb.logo_file AS b_team_logo
             FROM matches m
             LEFT JOIN contestants ca ON ca.id = m.contestant_a_id
             LEFT JOIN teams ta ON ta.id = ca.team_id
             LEFT JOIN contestants cb ON cb.id = m.contestant_b_id
             LEFT JOIN teams tb ON tb.id = cb.team_id
             WHERE m.activity_id = ?
             ORDER BY FIELD(m.stage, 'main', 'third', 'rr'), m.round, m.position",
            [$activityId]
        );
    }

    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM matches WHERE id = ?', [$id]);
    }

    public function hasResults(int $activityId): bool
    {
        return (bool) $this->db->value("SELECT 1 FROM matches WHERE activity_id = ? AND status = 'done' AND is_bye = 0 LIMIT 1", [$activityId]);
    }

    public function exists(int $activityId): bool
    {
        return (bool) $this->db->value('SELECT 1 FROM matches WHERE activity_id = ? LIMIT 1', [$activityId]);
    }

    public function deleteForActivity(int $activityId): void
    {
        // next_match_id has no FK, so a plain delete is enough
        $this->db->execute('DELETE FROM matches WHERE activity_id = ?', [$activityId]);
    }

    public function create(array $m): int
    {
        return $this->db->insert(
            'INSERT INTO matches (activity_id, stage, round, position, contestant_a_id, contestant_b_id, score_a, score_b, winner_id, is_bye, status, next_match_id, next_slot)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$m['activity_id'], $m['stage'], $m['round'], $m['position'], $m['contestant_a_id'] ?? null, $m['contestant_b_id'] ?? null,
             $m['score_a'] ?? null, $m['score_b'] ?? null, $m['winner_id'] ?? null, $m['is_bye'] ?? 0, $m['status'] ?? 'pending',
             $m['next_match_id'] ?? null, $m['next_slot'] ?? null]
        );
    }

    public function update(int $id, array $fields): void
    {
        if (!$fields) {
            return;
        }
        $sets = implode(', ', array_map(fn($k) => "`$k` = ?", array_keys($fields)));
        $this->db->execute("UPDATE matches SET $sets WHERE id = ?", array_merge(array_values($fields), [$id]));
    }

    public function transaction(callable $fn)
    {
        return $this->db->transaction($fn);
    }

    public function database(): Database
    {
        return $this->db;
    }
}
