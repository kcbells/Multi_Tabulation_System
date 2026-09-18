<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Core\Database;

final class ScoreRepository extends Repository
{
    /** All score rows of an activity. */
    public function forActivity(int $activityId): array
    {
        return $this->db->all(
            'SELECT s.judge_id, s.contestant_id, s.criterion_id, s.score
             FROM scores s JOIN contestants c ON c.id = s.contestant_id
             WHERE c.activity_id = ?',
            [$activityId]
        );
    }

    public function forJudge(int $judgeId, int $activityId): array
    {
        return $this->db->all(
            'SELECT s.contestant_id, s.criterion_id, s.score, s.updated_at
             FROM scores s JOIN contestants c ON c.id = s.contestant_id
             WHERE s.judge_id = ? AND c.activity_id = ?',
            [$judgeId, $activityId]
        );
    }

    public function activityHasScores(int $activityId): bool
    {
        return (bool) $this->db->value(
            'SELECT 1 FROM scores s JOIN contestants c ON c.id = s.contestant_id WHERE c.activity_id = ? LIMIT 1',
            [$activityId]
        );
    }

    public function countForJudge(int $judgeId, int $activityId): int
    {
        return (int) $this->db->value(
            'SELECT COUNT(*) FROM scores s JOIN contestants c ON c.id = s.contestant_id WHERE s.judge_id = ? AND c.activity_id = ?',
            [$judgeId, $activityId]
        );
    }

    /** @param array<int, array{contestant_id:int, criterion_id:int, score:?float}> $rows null score clears the cell */
    public function saveMany(int $judgeId, array $rows): void
    {
        $this->db->transaction(function (Database $db) use ($judgeId, $rows) {
            foreach ($rows as $r) {
                if ($r['score'] === null) {
                    $db->execute(
                        'DELETE FROM scores WHERE judge_id = ? AND contestant_id = ? AND criterion_id = ?',
                        [$judgeId, $r['contestant_id'], $r['criterion_id']]
                    );
                } else {
                    $db->execute(
                        'INSERT INTO scores (judge_id, contestant_id, criterion_id, score) VALUES (?, ?, ?, ?)
                         ON DUPLICATE KEY UPDATE score = VALUES(score)',
                        [$judgeId, $r['contestant_id'], $r['criterion_id'], $r['score']]
                    );
                }
            }
        });
    }

    public function resetActivity(int $activityId): void
    {
        $this->ensureContestantTable();
        $this->db->transaction(function (Database $db) use ($activityId) {
            $db->execute('DELETE s FROM scores s JOIN contestants c ON c.id = s.contestant_id WHERE c.activity_id = ?', [$activityId]);
            $db->execute('DELETE FROM judge_submissions WHERE activity_id = ?', [$activityId]);
            $db->execute('DELETE cs FROM contestant_submissions cs JOIN contestants c ON c.id = cs.contestant_id WHERE c.activity_id = ?', [$activityId]);
        });
    }

    /* ------------------------------------------------ per-contestant submissions */

    private static bool $tableReady = false;

    /** Existing installs get the table on first use (no need to run setup again). */
    private function ensureContestantTable(): void
    {
        if (self::$tableReady) {
            return;
        }
        $this->db->execute('CREATE TABLE IF NOT EXISTS contestant_submissions (
            judge_id INT UNSIGNED NOT NULL, contestant_id INT UNSIGNED NOT NULL,
            submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (judge_id, contestant_id),
            CONSTRAINT fk_cs_judge FOREIGN KEY (judge_id) REFERENCES access_codes(id) ON DELETE CASCADE,
            CONSTRAINT fk_cs_contestant FOREIGN KEY (contestant_id) REFERENCES contestants(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        self::$tableReady = true;
    }

    /** @return int[] contestant ids this judge already submitted in the activity */
    public function submittedContestants(int $judgeId, int $activityId): array
    {
        $this->ensureContestantTable();
        return array_map('intval', array_column($this->db->all(
            'SELECT cs.contestant_id FROM contestant_submissions cs JOIN contestants c ON c.id = cs.contestant_id
             WHERE cs.judge_id = ? AND c.activity_id = ?',
            [$judgeId, $activityId]
        ), 'contestant_id'));
    }

    public function countForContestant(int $judgeId, int $contestantId): int
    {
        return (int) $this->db->value('SELECT COUNT(*) FROM scores WHERE judge_id = ? AND contestant_id = ?', [$judgeId, $contestantId]);
    }

    public function submitContestant(int $judgeId, int $contestantId): void
    {
        $this->ensureContestantTable();
        $this->db->execute('INSERT IGNORE INTO contestant_submissions (judge_id, contestant_id) VALUES (?, ?)', [$judgeId, $contestantId]);
    }

    /* ------------------------------------------------ submissions */

    public function submittedAt(int $judgeId, int $activityId): ?string
    {
        $v = $this->db->value('SELECT submitted_at FROM judge_submissions WHERE judge_id = ? AND activity_id = ?', [$judgeId, $activityId]);
        return $v === null ? null : (string) $v;
    }

    public function submit(int $judgeId, int $activityId): void
    {
        $this->db->execute('INSERT IGNORE INTO judge_submissions (judge_id, activity_id) VALUES (?, ?)', [$judgeId, $activityId]);
    }

    public function unlock(int $judgeId, int $activityId): void
    {
        $this->ensureContestantTable();
        $this->db->execute('DELETE FROM judge_submissions WHERE judge_id = ? AND activity_id = ?', [$judgeId, $activityId]);
        $this->db->execute('DELETE cs FROM contestant_submissions cs JOIN contestants c ON c.id = cs.contestant_id WHERE cs.judge_id = ? AND c.activity_id = ?', [$judgeId, $activityId]);
    }

    /** Judges on an activity panel with submission status and number of scored cells. */
    public function panel(int $activityId): array
    {
        return $this->db->all(
            'SELECT ac.id, ac.name, ac.is_active, ac.last_used_at, js.submitted_at,
                    (SELECT COUNT(*) FROM scores s JOIN contestants ct ON ct.id = s.contestant_id
                      WHERE s.judge_id = ac.id AND ct.activity_id = ja.activity_id) AS scored
             FROM judge_activities ja
             JOIN access_codes ac ON ac.id = ja.judge_id
             LEFT JOIN judge_submissions js ON js.judge_id = ac.id AND js.activity_id = ja.activity_id
             WHERE ja.activity_id = ? ORDER BY ac.name',
            [$activityId]
        );
    }
}
