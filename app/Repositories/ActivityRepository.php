<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ActivityRepository extends Repository
{
    public function find(int $id): ?array
    {
        return $this->db->one('SELECT * FROM activities WHERE id = ?', [$id]);
    }

    public function findWithEvent(int $id): ?array
    {
        return $this->db->one(
            'SELECT a.*, e.title AS event_title FROM activities a JOIN events e ON e.id = a.event_id WHERE a.id = ?',
            [$id]
        );
    }

    public function findForJudge(int $id, int $judgeId): ?array
    {
        return $this->db->one(
            'SELECT a.* FROM activities a JOIN judge_activities ja ON ja.activity_id = a.id WHERE a.id = ? AND ja.judge_id = ?',
            [$id, $judgeId]
        );
    }

    public function forEvent(int $eventId): array
    {
        return $this->db->all(
            'SELECT id, title, status, format, score_label, rank_direction, third_place, counts_to_overall FROM activities WHERE event_id = ? ORDER BY sort_order, id',
            [$eventId]
        );
    }

    public function forEventWithStats(int $eventId): array
    {
        return $this->db->all(
            'SELECT a.id, a.title, a.description, a.venue, a.nature, a.schedule_at, a.status, a.format, a.score_label, a.rank_direction,
                    a.third_place, a.counts_to_overall, a.sort_order, a.criteria_file_name,
                    (SELECT COUNT(*) FROM matches m WHERE m.activity_id = a.id) AS matches,
                    (SELECT COUNT(*) FROM matches m WHERE m.activity_id = a.id AND m.status = \'done\') AS matches_done,
                    (SELECT COUNT(*) FROM activity_results r WHERE r.activity_id = a.id AND r.value IS NOT NULL) AS results,
                    (SELECT COUNT(*) FROM criteria c WHERE c.activity_id = a.id) AS criteria,
                    (SELECT COALESCE(SUM(max_score), 0) FROM criteria c WHERE c.activity_id = a.id) AS criteria_total,
                    (SELECT COUNT(*) FROM contestants c WHERE c.activity_id = a.id) AS contestants,
                    (SELECT COUNT(*) FROM judge_activities ja WHERE ja.activity_id = a.id) AS judges,
                    (SELECT COUNT(*) FROM judge_submissions js WHERE js.activity_id = a.id) AS submitted
             FROM activities a WHERE a.event_id = ?
             ORDER BY a.sort_order, a.schedule_at IS NULL, a.schedule_at, a.id',
            [$eventId]
        );
    }

    /** Activities assigned to a judge, with the judge's progress. */
    public function forJudge(int $judgeId): array
    {
        return $this->db->all(
            'SELECT a.id, a.title, a.description, a.venue, a.schedule_at, a.status,
                    (SELECT COUNT(*) FROM criteria c WHERE c.activity_id = a.id) AS criteria,
                    (SELECT COUNT(*) FROM contestants c WHERE c.activity_id = a.id) AS contestants,
                    (SELECT COUNT(*) FROM scores s JOIN contestants ct ON ct.id = s.contestant_id
                       WHERE s.judge_id = ja.judge_id AND ct.activity_id = a.id) AS scored,
                    js.submitted_at
             FROM judge_activities ja
             JOIN activities a ON a.id = ja.activity_id
             LEFT JOIN judge_submissions js ON js.judge_id = ja.judge_id AND js.activity_id = a.id
             WHERE ja.judge_id = ? AND a.format = \'score\'
             ORDER BY FIELD(a.status, \'open\', \'pending\', \'closed\'), a.sort_order, a.id',
            [$judgeId]
        );
    }

    public function create(array $d): int
    {
        return $this->db->insert(
            'INSERT INTO activities (event_id, title, description, venue, nature, schedule_at, format, score_label, rank_direction, third_place, counts_to_overall, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$d['event_id'], $d['title'], $d['description'], $d['venue'], $d['nature'] ?? null, $d['schedule_at'], $d['format'] ?? 'score', $d['score_label'] ?? null,
             $d['rank_direction'] ?? 'desc', $d['third_place'] ?? 1, $d['counts_to_overall'], $d['sort_order']]
        );
    }

    public function update(int $id, array $d): void
    {
        $this->db->execute(
            'UPDATE activities SET title = ?, description = ?, venue = ?, nature = ?, schedule_at = ?, format = ?, score_label = ?, rank_direction = ?,
                    third_place = ?, counts_to_overall = ?, sort_order = ? WHERE id = ?',
            [$d['title'], $d['description'], $d['venue'], $d['nature'], $d['schedule_at'], $d['format'], $d['score_label'], $d['rank_direction'],
             $d['third_place'], $d['counts_to_overall'], $d['sort_order'], $id]
        );
    }

    public function nextSortOrder(int $eventId): int
    {
        return (int) $this->db->value('SELECT COALESCE(MAX(sort_order), 0) + 1 FROM activities WHERE event_id = ?', [$eventId]);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM activities WHERE id = ?', [$id]);
    }

    public function setStatus(int $id, string $status): void
    {
        $this->db->execute('UPDATE activities SET status = ? WHERE id = ?', [$status, $id]);
    }

    public function setCriteriaFile(int $id, ?string $path, ?string $originalName, ?string $text): void
    {
        $this->db->execute(
            'UPDATE activities SET criteria_file = ?, criteria_file_name = ?, criteria_text = ? WHERE id = ?',
            [$path, $originalName, $text, $id]
        );
    }

    public function setCriteriaText(int $id, string $text): void
    {
        $this->db->execute('UPDATE activities SET criteria_text = ? WHERE id = ?', [$text, $id]);
    }

    /** Stored criteria file keys of every activity in an event (for cleanup). */
    public function criteriaFilesForEvent(int $eventId): array
    {
        return array_column($this->db->all(
            'SELECT criteria_file FROM activities WHERE event_id = ? AND criteria_file IS NOT NULL',
            [$eventId]
        ), 'criteria_file');
    }

    public function assignedJudgeIds(int $id): array
    {
        return array_map('intval', array_column(
            $this->db->all('SELECT judge_id FROM judge_activities WHERE activity_id = ?', [$id]),
            'judge_id'
        ));
    }

    /** Replaces the judge panel of an activity with the given judge codes (must belong to the event). */
    public function syncJudges(int $id, int $eventId, array $judgeIds): void
    {
        $judgeIds = array_values(array_unique(array_map('intval', $judgeIds)));
        $this->db->transaction(function (Database $db) use ($id, $eventId, $judgeIds) {
            $valid = $judgeIds ? array_map('intval', array_column($db->all(
                "SELECT id FROM access_codes WHERE event_id = ? AND role = 'judge' AND id IN (" . Database::placeholders($judgeIds) . ')',
                array_merge([$eventId], $judgeIds)
            ), 'id')) : [];
            if ($valid) {
                $db->execute(
                    'DELETE FROM judge_activities WHERE activity_id = ? AND judge_id NOT IN (' . Database::placeholders($valid) . ')',
                    array_merge([$id], $valid)
                );
            } else {
                $db->execute('DELETE FROM judge_activities WHERE activity_id = ?', [$id]);
            }
            foreach ($valid as $jid) {
                $db->execute('INSERT IGNORE INTO judge_activities (judge_id, activity_id) VALUES (?, ?)', [$jid, $id]);
            }
        });
    }
}
