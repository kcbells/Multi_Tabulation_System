<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Repositories\ActivityRepository;
use App\Repositories\ContestantRepository;
use App\Repositories\CriterionRepository;
use App\Repositories\EventRepository;
use App\Repositories\ScoreRepository;
use App\Repositories\TeamRepository;

/**
 * Score computation.
 *
 * Activity: a judge's total for a contestant is the sum of that judge's
 * criterion scores. The contestant's final score is the mean of the judge
 * totals from judges who submitted (or every judge who scored, when drafts
 * are included). Ties share a rank (1, 1, 3).
 *
 * Event overall: each activity that counts toward the overall awards
 * placement points to the team of every ranked contestant (tied ranks share
 * points); remaining ranked contestants earn participation points.
 * A solo activity (no entry belongs to a team) is reported with its full
 * individual ranking instead, since there are no teams to give points to.
 */
final class Tabulator
{
    private ActivityRepository $activities;
    private CriterionRepository $criteria;
    private ContestantRepository $contestants;
    private ScoreRepository $scores;

    public function __construct()
    {
        $this->activities = new ActivityRepository();
        $this->criteria = new CriterionRepository();
        $this->contestants = new ContestantRepository();
        $this->scores = new ScoreRepository();
    }

    public function activity(int $activityId, bool $includeDrafts = false): array
    {
        $activity = $this->activities->findWithEvent($activityId) ?? throw new HttpException('Activity not found.', 404);
        $criteria = $this->criteria->forActivity($activityId);
        $contestants = $this->contestants->forActivity($activityId);
        $expected = count($criteria) * count($contestants);

        $judges = [];
        foreach ($this->scores->panel($activityId) as $j) {
            $judges[] = [
                'id' => (int) $j['id'], 'name' => $j['name'], 'is_active' => (bool) $j['is_active'],
                'scored' => (int) $j['scored'], 'expected' => $expected,
                'submitted' => $j['submitted_at'] !== null, 'submitted_at' => $j['submitted_at'],
            ];
        }
        $countedIds = array_column(array_filter($judges, fn($j) => $j['submitted'] || ($includeDrafts && $j['scored'] > 0)), 'id');

        $matrix = [];
        foreach ($this->scores->forActivity($activityId) as $s) {
            $matrix[(int) $s['contestant_id']][(int) $s['judge_id']][(int) $s['criterion_id']] = (float) $s['score'];
        }

        $maxTotal = (float) array_sum(array_map(fn($c) => (float) $c['max_score'], $criteria));
        $rows = [];
        foreach ($contestants as $c) {
            $cid = (int) $c['id'];
            $judgeTotals = [];
            $criterionValues = [];
            foreach ($countedIds as $jid) {
                if (empty($matrix[$cid][$jid])) {
                    continue;
                }
                $judgeTotals[$jid] = round(array_sum($matrix[$cid][$jid]), 2);
                foreach ($criteria as $cr) {
                    $criterionValues[(int) $cr['id']][] = $matrix[$cid][$jid][(int) $cr['id']] ?? 0.0;
                }
            }
            $criteriaAvg = [];
            foreach ($criteria as $cr) {
                $values = $criterionValues[(int) $cr['id']] ?? [];
                $criteriaAvg[(int) $cr['id']] = $values ? round(array_sum($values) / count($values), 2) : null;
            }
            $average = $judgeTotals ? round(array_sum($judgeTotals) / count($judgeTotals), 3) : null;
            $rows[] = [
                'id' => $cid,
                'number' => (int) $c['number'],
                'name' => $c['name'],
                'details' => $c['details'],
                'team_id' => $c['team_id'] !== null ? (int) $c['team_id'] : null,
                'team_name' => $c['team_name'],
                'color' => $c['color'] ?? null,
                'photo' => $c['photo'] ?? null,
                'judge_totals' => (object) $judgeTotals,
                'criteria_avg' => (object) $criteriaAvg,
                'average' => $average,
                'percentage' => ($average !== null && $maxTotal > 0) ? round($average / $maxTotal * 100, 2) : null,
                'rank' => null,
            ];
        }
        $this->rank($rows);

        return [
            'activity' => [
                'id' => (int) $activity['id'], 'event_id' => (int) $activity['event_id'],
                'event_title' => $activity['event_title'], 'title' => $activity['title'],
                'status' => $activity['status'], 'venue' => $activity['venue'], 'schedule_at' => $activity['schedule_at'],
            ],
            'criteria' => array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'max_score' => (float) $c['max_score']], $criteria),
            'judges' => $judges,
            'counted_judges' => array_values($countedIds),
            'rows' => $rows,
            'max_total' => $maxTotal,
            'include_drafts' => $includeDrafts,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }

    private function rank(array &$rows): void
    {
        usort($rows, function (array $a, array $b): int {
            if ($a['average'] === null || $b['average'] === null) {
                return ($a['average'] === null) <=> ($b['average'] === null) ?: $a['number'] <=> $b['number'];
            }
            return ($b['average'] <=> $a['average']) ?: ($a['number'] <=> $b['number']);
        });
        $previous = null;
        $rank = 0;
        foreach ($rows as $i => &$row) {
            if ($row['average'] === null) {
                continue;
            }
            if ($previous === null || abs($row['average'] - $previous) > 0.0005) {
                $rank = $i + 1;
                $previous = $row['average'];
            }
            $row['rank'] = $rank;
        }
        unset($row);
    }

    private static function nameKey(string $name): string
    {
        return strtolower(preg_replace('/[^a-z0-9]+/i', '', $name));
    }

    /**
     * @param bool $hideUnfinishedScores public page and big screen: score-based activities count (and show
     *                                   winners) only once they are final, so judges' live scores never leak
     */
    public function overall(int $eventId, bool $finalOnly = false, bool $includeDrafts = false, bool $hideUnfinishedScores = false): array
    {
        $event = (new EventRepository())->find($eventId) ?? throw new HttpException('Event not found.', 404);
        $points = array_values(array_map('floatval', json_decode((string) $event['placement_points'], true) ?: []));
        $participation = (float) $event['participation_points'];

        $standings = [];
        foreach ((new TeamRepository())->forEvent($eventId) as $t) {
            $standings[(int) $t['id']] = [
                'team_id' => (int) $t['id'], 'name' => $t['name'], 'color' => $t['color'], 'photo' => $t['photo'], 'total' => 0.0,
                'activities' => [], 'medals' => [0, 0, 0], 'rank' => null,
            ];
        }

        // entries that were not linked to a team still count when their name is the team's name
        $teamByName = [];
        foreach ($standings as $tid => $t) {
            $teamByName[self::nameKey($t['name'])] = $tid;
        }

        $summary = [];
        $placements = new PlacementService();
        foreach ($this->activities->forEvent($eventId) as $a) {
            $aid = (int) $a['id'];
            if ($hideUnfinishedScores && $a['format'] === 'score' && $a['status'] !== 'closed') {
                $summary[] = [
                    'id' => $aid, 'title' => $a['title'], 'status' => $a['status'], 'format' => $a['format'],
                    'included' => false, 'counts_to_overall' => (bool) $a['counts_to_overall'],
                    'scored' => false, 'winners' => [], 'unlinked' => 0, 'hidden' => true, 'solo' => false, 'ranking' => [],
                ];
                continue;
            }
            $included = (bool) $a['counts_to_overall'] && (!$finalOnly || $a['status'] === 'closed');
            $result = $placements->forActivity($a, $includeDrafts);
            $winners = [];
            $scored = false;
            $unlinked = 0;
            $teamOf = fn(array $row) => $row['team_id'] ?? $teamByName[self::nameKey((string) $row['name'])] ?? null;
            $solo = $result['rows'] && !array_filter($result['rows'], fn($row) => isset($standings[$teamOf($row)]));
            foreach ($result['rows'] as $row) {
                if ($row['rank'] === null) {
                    continue;
                }
                $scored = true;
                if ($row['rank'] <= 3) {
                    $winners[] = ['rank' => $row['rank'], 'name' => $row['name'], 'team' => $row['team_name'], 'color' => $row['color'] ?? null, 'photo' => $row['photo'] ?? null, 'display' => $row['display']];
                }
                $tid = $teamOf($row);
                if ($tid === null || !isset($standings[$tid])) {
                    $unlinked++;
                    continue;
                }
                if (!$included) {
                    continue;
                }
                $earned = $points[$row['rank'] - 1] ?? $participation;
                $standings[$tid]['activities'][$aid] = ($standings[$tid]['activities'][$aid] ?? 0) + $earned;
                $standings[$tid]['total'] += $earned;
                if ($row['rank'] <= 3) {
                    $standings[$tid]['medals'][$row['rank'] - 1]++;
                }
            }
            $summary[] = [
                'id' => $aid, 'title' => $a['title'], 'status' => $a['status'], 'format' => $a['format'],
                'included' => $included, 'counts_to_overall' => (bool) $a['counts_to_overall'],
                'scored' => $scored, 'winners' => $winners,
                'unlinked' => $solo ? 0 : $unlinked, // solo entries are not missing a team
                'solo' => $solo,
                'ranking' => $solo ? array_map(fn($row) => [
                    'rank' => $row['rank'], 'number' => $row['number'] ?? null, 'name' => $row['name'],
                    'display' => $row['display'], 'color' => $row['color'] ?? null, 'photo' => $row['photo'] ?? null,
                ], $result['rows']) : [],
            ];
        }

        $list = array_values($standings);
        usort($list, fn($a, $b) => ($b['total'] <=> $a['total'])
            ?: ($b['medals'][0] <=> $a['medals'][0])
            ?: ($b['medals'][1] <=> $a['medals'][1])
            ?: ($b['medals'][2] <=> $a['medals'][2])
            ?: strcmp($a['name'], $b['name']));
        $previousKey = null;
        foreach ($list as $i => &$row) {
            $row['total'] = round($row['total'], 2);
            $key = $row['total'] . '|' . implode(',', $row['medals']);
            $row['rank'] = $key === $previousKey ? $list[$i - 1]['rank'] : $i + 1;
            $previousKey = $key;
            $row['activities'] = (object) $row['activities'];
        }
        unset($row);

        return [
            'event' => [
                'id' => (int) $event['id'], 'title' => $event['title'], 'venue' => $event['venue'],
                'start_date' => $event['start_date'], 'end_date' => $event['end_date'], 'start_at' => $event['start_at'], 'end_at' => $event['end_at'],
                'status' => $event['status'],
            ],
            'placement_points' => $points,
            'participation_points' => $participation,
            'activities' => $summary,
            'standings' => $list,
            'final_only' => $finalOnly,
            'include_drafts' => $includeDrafts,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
    }
}
