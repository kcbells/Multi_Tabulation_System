<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Repositories\ActivityRepository;
use App\Repositories\AwardRepository;
use App\Repositories\ContestantRepository;
use App\Repositories\CriterionRepository;
use App\Repositories\DeductionRepository;
use App\Repositories\EventRepository;
use App\Repositories\ScoreRepository;
use App\Repositories\TeamRepository;

/**
 * Score computation.
 *
 * Activity (score-based):
 *   - A judge's total for a contestant is the sum of that judge's criterion scores. With a
 *     10-point scale each criterion is scored 0–10 and weighted by its points (8/10 on a
 *     40-point criterion = 32).
 *   - Only judges who submitted count (every judge who scored, when drafts are included).
 *   - "Drop highest & lowest": with 3 or more judge totals, the highest and the lowest are left out.
 *   - Deductions (penalties) are taken off every judge total of the contestant.
 *   - Method "average": the final score is the mean of the judge totals. Method "rank_sum": each
 *     judge's totals are turned into ranks (ties share the average rank) and the lowest mean rank
 *     wins, so one very harsh or generous judge cannot swing the result.
 *   - A later round (finals) can carry over part of the previous round's score:
 *     final = carry% × previous round + (100 − carry)% × this round.
 *   - Ties share a rank (1, 1, 3) unless a tie-break is set: the higher average in one
 *     criterion, the lower rank sum, or (for rank sum) the higher average.
 *
 * Event overall: each activity that counts toward the overall awards
 * placement points to the team of every ranked contestant (tied ranks share
 * points); remaining ranked contestants earn participation points.
 * A solo activity (no entry belongs to a team) is reported with its full
 * individual ranking instead, since there are no teams to give points to.
 */
final class Tabulator
{
    public const METHODS = ['average', 'rank_sum'];
    public const TIE_BREAKS = ['share', 'criterion', 'rank_sum', 'average'];

    private ActivityRepository $activities;
    private CriterionRepository $criteria;
    private ContestantRepository $contestants;
    private ScoreRepository $scores;
    /** guards against a round that (wrongly) points back to itself through its previous rounds */
    private array $computing = [];

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
        $this->computing[$activityId] = true;
        try {
            return $this->compute($activity, $includeDrafts);
        } finally {
            unset($this->computing[$activityId]);
        }
    }

    private function compute(array $activity, bool $includeDrafts): array
    {
        $activityId = (int) $activity['id'];
        $criteria = $this->criteria->forActivity($activityId);
        $contestants = $this->contestants->forActivity($activityId);
        $expected = count($criteria) * count($contestants);
        $scale = (float) ($activity['score_scale'] ?? 0) > 0 ? (float) $activity['score_scale'] : null;
        $method = in_array($activity['scoring_method'] ?? 'average', self::METHODS, true) ? $activity['scoring_method'] : 'average';
        $dropExtremes = (bool) ($activity['drop_extremes'] ?? false);
        $tieBreak = in_array($activity['tie_break'] ?? 'share', self::TIE_BREAKS, true) ? $activity['tie_break'] : 'share';
        $tieCriterion = (int) ($activity['tie_criterion_id'] ?? 0);
        $criterionNames = array_column($criteria, 'name', 'id');
        if ($tieBreak === 'criterion' && !isset($criterionNames[$tieCriterion])) {
            $tieBreak = 'share';
        }
        if (($tieBreak === 'rank_sum' && $method === 'rank_sum') || ($tieBreak === 'average' && $method === 'average')) {
            $tieBreak = 'share';
        }

        $judges = [];
        foreach ($this->scores->panel($activityId) as $j) {
            $judges[] = [
                'id' => (int) $j['id'], 'name' => $j['name'], 'is_active' => (bool) $j['is_active'],
                'scored' => (int) $j['scored'], 'expected' => $expected,
                'submitted' => $j['submitted_at'] !== null, 'submitted_at' => $j['submitted_at'],
            ];
        }
        $countedIds = array_column(array_filter($judges, fn($j) => $j['submitted'] || ($includeDrafts && $j['scored'] > 0)), 'id');

        // weighted criterion values: [contestant][judge][criterion]
        $matrix = [];
        $weights = [];
        foreach ($criteria as $cr) {
            $weights[(int) $cr['id']] = $scale ? (float) $cr['max_score'] / $scale : 1.0;
        }
        foreach ($this->scores->forActivity($activityId) as $s) {
            $crid = (int) $s['criterion_id'];
            $matrix[(int) $s['contestant_id']][(int) $s['judge_id']][$crid] = (float) $s['score'] * ($weights[$crid] ?? 1.0);
        }

        $deductions = [];
        foreach ((new DeductionRepository())->forActivity($activityId) as $d) {
            $deductions[(int) $d['contestant_id']][] = ['id' => (int) $d['id'], 'points' => (float) $d['points'], 'reason' => $d['reason'], 'created_by' => $d['created_by'], 'created_at' => $d['created_at']];
        }

        // previous round (carry-over)
        $carry = 0.0;
        $previous = [];
        $source = (int) ($activity['source_activity_id'] ?? 0);
        if ($source > 0 && $method === 'average' && (float) $activity['carry_weight'] > 0 && empty($this->computing[$source])) {
            $carry = min(100.0, max(0.0, (float) $activity['carry_weight'])) / 100;
            $sourceActivity = $this->activities->find($source);
            if ($sourceActivity && ($sourceActivity['format'] ?? 'score') === 'score') {
                foreach ($this->activity($source, $includeDrafts)['rows'] as $r) {
                    $previous[$r['id']] = $r['average'];
                }
            } else {
                $carry = 0.0;
            }
        }

        $maxTotal = (float) array_sum(array_map(fn($c) => (float) $c['max_score'], $criteria));
        $rows = [];
        $totalsByJudge = []; // judge => contestant => total after deductions (for rank sum)
        $rawByJudge = [];    // judge => contestant => total before deductions (for judge statistics)
        foreach ($contestants as $c) {
            $cid = (int) $c['id'];
            $deduction = round(array_sum(array_column($deductions[$cid] ?? [], 'points')), 2);
            $judgeTotals = [];
            $criterionValues = [];
            foreach ($countedIds as $jid) {
                if (empty($matrix[$cid][$jid])) {
                    continue;
                }
                $total = round(array_sum($matrix[$cid][$jid]), 2);
                $judgeTotals[$jid] = $total;
                $rawByJudge[$jid][$cid] = $total;
                $totalsByJudge[$jid][$cid] = $total - $deduction;
                foreach ($criteria as $cr) {
                    $criterionValues[(int) $cr['id']][] = $matrix[$cid][$jid][(int) $cr['id']] ?? 0.0;
                }
            }
            $criteriaAvg = [];
            foreach ($criteria as $cr) {
                $values = $criterionValues[(int) $cr['id']] ?? [];
                $criteriaAvg[(int) $cr['id']] = $values ? round(array_sum($values) / count($values), 2) : null;
            }
            $rows[] = [
                'id' => $cid,
                'number' => (int) $c['number'],
                'name' => $c['name'],
                'details' => $c['details'],
                'team_id' => $c['team_id'] !== null ? (int) $c['team_id'] : null,
                'team_name' => $c['team_name'],
                'color' => $c['color'] ?? null,
                'photo' => $c['photo'] ?? null,
                'source_contestant_id' => isset($c['source_contestant_id']) ? (int) $c['source_contestant_id'] ?: null : null,
                'judge_totals' => $judgeTotals,
                'criteria_avg' => (object) $criteriaAvg,
                'deduction' => $deduction,
                'deductions' => $deductions[$cid] ?? [],
                'dropped' => [],
                'raw_average' => null,
                'round_score' => null,
                'previous' => null,
                'average' => null,
                'percentage' => null,
                'rank_avg' => null,
                'rank_sum' => null,
                'tie_note' => null,
                'rank' => null,
            ];
        }

        // per-judge ranks (for rank sum and as a tie-break): ties share the average rank (1, 2.5, 2.5, 4)
        $judgeRanks = [];
        foreach ($totalsByJudge as $jid => $totals) {
            arsort($totals);
            $values = array_values($totals);
            $ids = array_keys($totals);
            $n = count($values);
            for ($i = 0; $i < $n;) {
                $k = $i;
                while ($k + 1 < $n && abs($values[$k + 1] - $values[$i]) < 0.0005) {
                    $k++;
                }
                for ($m = $i; $m <= $k; $m++) {
                    $judgeRanks[$jid][$ids[$m]] = ($i + $k) / 2 + 1;
                }
                $i = $k + 1;
            }
        }

        foreach ($rows as &$row) {
            $totals = $row['judge_totals'];
            if (!$totals) {
                $row['judge_totals'] = (object) [];
                continue;
            }
            $ranks = [];
            foreach ($totals as $jid => $_) {
                $ranks[$jid] = $judgeRanks[$jid][$row['id']];
            }
            // leave out the highest and lowest judge (by total, or by rank for rank sum)
            $kept = array_keys($totals);
            if ($dropExtremes && count($totals) >= 3) {
                $basis = $method === 'rank_sum' ? array_map(fn($r) => -$r, $ranks) : $totals;
                asort($basis);
                $low = array_key_first($basis);
                $high = array_key_last($basis);
                $row['dropped'] = [$high, $low];
                $kept = array_values(array_diff($kept, [$low, $high]));
            }
            $keptTotals = array_intersect_key($totals, array_flip($kept));
            $keptRanks = array_intersect_key($ranks, array_flip($kept));
            $row['raw_average'] = round(array_sum($keptTotals) / count($keptTotals), 3);
            $row['round_score'] = round($row['raw_average'] - $row['deduction'], 3);
            $row['rank_sum'] = round(array_sum($keptRanks), 2);
            $row['rank_avg'] = round(array_sum($keptRanks) / count($keptRanks), 4);
            if ($carry > 0) {
                $prev = $previous[$row['source_contestant_id'] ?? 0] ?? null;
                $row['previous'] = $prev;
                $row['average'] = $prev === null ? $row['round_score'] : round($carry * $prev + (1 - $carry) * $row['round_score'], 3);
            } else {
                $row['average'] = $row['round_score'];
            }
            $row['percentage'] = $maxTotal > 0 ? round($row['average'] / $maxTotal * 100, 2) : null;
            $row['judge_totals'] = (object) $totals;
        }
        unset($row);

        $this->rank($rows, $method, $tieBreak, $tieCriterion, $criterionNames);

        $result = [
            'activity' => [
                'id' => (int) $activity['id'], 'event_id' => (int) $activity['event_id'],
                'event_title' => $activity['event_title'] ?? '', 'title' => $activity['title'],
                'status' => $activity['status'], 'venue' => $activity['venue'], 'schedule_at' => $activity['schedule_at'],
                'certified_at' => $activity['certified_at'] ?? null, 'certified_by' => $activity['certified_by'] ?? null,
                'certified_hash' => $activity['certified_hash'] ?? null,
            ],
            'method' => [
                'scoring' => $method,
                'drop_extremes' => $dropExtremes,
                'score_scale' => $scale,
                'tie_break' => $tieBreak,
                'tie_criterion' => $tieBreak === 'criterion' ? $criterionNames[$tieCriterion] : null,
                'carry_weight' => $carry * 100,
                'source_activity_id' => $source ?: null,
            ],
            'criteria' => array_map(fn($c) => ['id' => (int) $c['id'], 'name' => $c['name'], 'max_score' => (float) $c['max_score']], $criteria),
            'judges' => $this->judgeStats($judges, $countedIds, $rawByJudge),
            'counted_judges' => array_values($countedIds),
            'rows' => $rows,
            'awards' => $this->awards($activityId, $rows),
            'max_total' => $maxTotal,
            'include_drafts' => $includeDrafts,
            'generated_at' => date('Y-m-d H:i:s'),
        ];
        return $result;
    }

    /**
     * Mean and spread of the totals each judge gave, with warnings:
     *   flat     — nearly the same total for everyone (stdev under 0.5 with 3+ contestants)
     *   harsh / generous — the judge's mean is 15+ points below / above the rest of the panel
     */
    private function judgeStats(array $judges, array $countedIds, array $rawByJudge): array
    {
        $means = [];
        foreach ($rawByJudge as $jid => $totals) {
            $means[$jid] = array_sum($totals) / count($totals);
        }
        foreach ($judges as &$j) {
            $totals = array_values($rawByJudge[$j['id']] ?? []);
            $j['counted'] = in_array($j['id'], $countedIds, true);
            $j['mean'] = null;
            $j['spread'] = null;
            $j['flags'] = [];
            if (!$totals) {
                continue;
            }
            $n = count($totals);
            $mean = array_sum($totals) / $n;
            $j['mean'] = round($mean, 2);
            $j['spread'] = round(sqrt(array_sum(array_map(fn($t) => ($t - $mean) ** 2, $totals)) / $n), 2);
            if ($n >= 3 && $j['spread'] < 0.5) {
                $j['flags'][] = 'flat';
            }
            $others = array_diff_key($means, [$j['id'] => true]);
            if ($others) {
                $gap = $mean - array_sum($others) / count($others);
                if ($gap <= -15) {
                    $j['flags'][] = 'harsh';
                } elseif ($gap >= 15) {
                    $j['flags'][] = 'generous';
                }
            }
        }
        unset($j);
        return $judges;
    }

    /** Awards with their winners: top average in a criterion (ties share it), or the contestant picked by hand. */
    private function awards(int $activityId, array $rows): array
    {
        $byId = [];
        foreach ($rows as $r) {
            $byId[$r['id']] = $r;
        }
        $pick = fn(array $r, $value) => [
            'id' => $r['id'], 'number' => $r['number'], 'name' => $r['name'], 'team_name' => $r['team_name'],
            'color' => $r['color'], 'photo' => $r['photo'], 'value' => $value,
        ];
        $out = [];
        foreach ((new AwardRepository())->forActivity($activityId) as $a) {
            $winners = [];
            if ($a['criterion_id'] !== null) {
                $best = null;
                foreach ($rows as $r) {
                    $v = ((array) $r['criteria_avg'])[(int) $a['criterion_id']] ?? null;
                    if ($v === null) {
                        continue;
                    }
                    if ($best === null || $v > $best + 0.0005) {
                        $best = $v;
                        $winners = [$pick($r, $v)];
                    } elseif (abs($v - $best) <= 0.0005) {
                        $winners[] = $pick($r, $v);
                    }
                }
            } elseif ($a['contestant_id'] !== null && isset($byId[(int) $a['contestant_id']])) {
                $winners[] = $pick($byId[(int) $a['contestant_id']], null);
            }
            $out[] = [
                'id' => (int) $a['id'], 'name' => $a['name'],
                'criterion_id' => $a['criterion_id'] !== null ? (int) $a['criterion_id'] : null, 'criterion_name' => $a['criterion_name'],
                'contestant_id' => $a['contestant_id'] !== null ? (int) $a['contestant_id'] : null,
                'winners' => $winners,
            ];
        }
        return $out;
    }

    /** Sorts and ranks: the main score first, then the tie-break; rows still equal share a rank. */
    private function rank(array &$rows, string $method, string $tieBreak, int $tieCriterion, array $criterionNames): void
    {
        $primary = fn(array $r) => $method === 'rank_sum' ? -$r['rank_avg'] : $r['average']; // higher is better
        $secondary = fn(array $r) => match ($tieBreak) {
            'criterion' => ((array) $r['criteria_avg'])[$tieCriterion] ?? -INF,
            'rank_sum' => -$r['rank_avg'],
            'average' => $r['average'],
            default => 0,
        };
        usort($rows, function (array $a, array $b) use ($primary, $secondary): int {
            if ($a['average'] === null || $b['average'] === null) {
                return ($a['average'] === null) <=> ($b['average'] === null) ?: $a['number'] <=> $b['number'];
            }
            $p = $primary($b) <=> $primary($a);
            if ($p !== 0 && abs($primary($a) - $primary($b)) > 0.0005) {
                return $p;
            }
            return ($secondary($b) <=> $secondary($a)) ?: ($a['number'] <=> $b['number']);
        });

        $label = match ($tieBreak) {
            'criterion' => 'the higher ' . ($criterionNames[$tieCriterion] ?? 'criterion') . ' score',
            'rank_sum' => 'the lower rank sum',
            'average' => 'the higher average',
            default => '',
        };
        $previous = null;
        $rank = 0;
        foreach ($rows as $i => &$row) {
            if ($row['average'] === null) {
                continue;
            }
            $samePrimary = $previous !== null && abs($primary($row) - $primary($previous)) <= 0.0005;
            $sameSecondary = $samePrimary && abs($secondary($row) - $secondary($previous)) <= 0.0005;
            if (!$samePrimary || ($tieBreak !== 'share' && !$sameSecondary)) {
                $rank = $i + 1;
            }
            if ($samePrimary && $tieBreak !== 'share') {
                // mark both rows of a tie that the tie-break decided (or could not decide)
                $note = $sameSecondary ? 'Still tied after ' . $label : 'Tie broken by ' . $label;
                $row['tie_note'] = $note;
                $rows[$i - 1]['tie_note'] ??= $note;
            }
            $row['rank'] = $rank;
            $previous = $row;
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
        // without overall standings nobody collects points: every activity is its own ranking
        $hasOverall = (int) ($event['has_overall'] ?? 1) === 1;

        $standings = [];
        foreach ($hasOverall ? (new TeamRepository())->forEvent($eventId) : [] as $t) {
            $standings[(int) $t['id']] = [
                'team_id' => (int) $t['id'], 'name' => $t['name'], 'color' => $t['color'], 'photo' => $t['photo'], 'total' => 0.0,
                'activities' => [], 'medals' => [0, 0, 0], 'rank' => null,
            ];
        }

        // countdown: 1st place earns as many points as there are groups, one less for each place
        // below, and every placing earns at least 1 (8 tribes: 8, 7, 6 … 1)
        if (($event['points_mode'] ?? 'list') === 'countdown' && $standings) {
            $points = range((float) count($standings), 1.0);
            $participation = 1.0;
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
            'has_overall' => $hasOverall,
            'points_mode' => $event['points_mode'] ?? 'list',
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
