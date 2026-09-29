<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\ActivityResultRepository;
use App\Repositories\ContestantRepository;
use App\Repositories\MatchRepository;

/**
 * One view of "who placed where" for every activity format, so the overall
 * standings (and the activity cards) don't care how an activity was decided.
 *
 *   score        averages of judge totals (Tabulator)
 *   bracket      champion 1, runner-up 2, 3rd-place match / semifinal losers, earlier exits
 *   round_robin  standings table
 *   ranking      result values (higher or lower is better)
 */
final class PlacementService
{
    /**
     * @return array{format:string, rows: array<int, array{id:int,name:string,team_id:?int,team_name:?string,rank:?int,display:string}>, complete:bool}
     */
    public function forActivity(array $activity, bool $includeDrafts = false): array
    {
        $aid = (int) $activity['id'];
        $format = $activity['format'] ?? 'score';

        if ($format === 'score') {
            $result = (new Tabulator())->activity($aid, $includeDrafts);
            $rankSum = $result['method']['scoring'] === 'rank_sum';
            $rows = array_map(fn($r) => [
                'id' => $r['id'], 'number' => $r['number'] ?? null, 'name' => $r['name'], 'team_id' => $r['team_id'], 'team_name' => $r['team_name'], 'color' => $r['color'] ?? null, 'photo' => $r['photo'] ?? null,
                'rank' => $r['rank'],
                'display' => $r['average'] === null ? '' : ($rankSum ? 'rank sum ' . rtrim(rtrim(number_format((float) $r['rank_sum'], 2, '.', ''), '0'), '.') : number_format((float) $r['average'], 2) . ' avg'),
            ], $result['rows']);
            return ['format' => $format, 'rows' => $rows, 'complete' => $activity['status'] === 'closed'];
        }

        $contestants = (new ContestantRepository())->forActivity($aid);

        if ($format === 'ranking') {
            $rows = RankingService::standings($contestants, (new ActivityResultRepository())->forActivity($aid), (string) ($activity['rank_direction'] ?? 'desc'));
            $label = $activity['score_label'] ?: '';
            return ['format' => $format, 'complete' => $activity['status'] === 'closed', 'rows' => array_map(fn($r) => [
                'id' => $r['id'], 'number' => $r['number'] ?? null, 'name' => $r['name'], 'team_id' => $r['team_id'], 'team_name' => $r['team_name'], 'color' => $r['color'] ?? null, 'photo' => $r['photo'] ?? null,
                'rank' => $r['rank'], 'display' => $r['value'] === null ? '' : rtrim(rtrim(number_format($r['value'], 3, '.', ''), '0'), '.') . ($label ? " $label" : ''),
            ], $rows)];
        }

        $matches = (new MatchRepository())->forActivity($aid);

        if ($format === 'round_robin') {
            $rows = RoundRobinService::standings($contestants, $matches);
            return ['format' => $format, 'complete' => $activity['status'] === 'closed', 'rows' => array_map(fn($r) => [
                'id' => $r['id'], 'number' => $r['number'] ?? null, 'name' => $r['name'], 'team_id' => $r['team_id'], 'team_name' => $r['team_name'], 'color' => $r['color'] ?? null, 'photo' => $r['photo'] ?? null,
                'rank' => $r['rank'], 'display' => $r['played'] ? "{$r['points']} pts ({$r['won']}-{$r['drawn']}-{$r['lost']})" : '',
            ], $rows)];
        }

        // bracket
        $ranks = BracketService::placements($matches);
        $labels = [1 => 'Champion', 2 => 'Runner-up', 3 => '3rd place', 4 => '4th place', 5 => 'Quarterfinalist', 9 => 'Round of 16', 17 => 'Round of 32'];
        $rows = [];
        foreach ($contestants as $c) {
            $rank = $ranks[(int) $c['id']] ?? null;
            $rows[] = [
                'id' => (int) $c['id'], 'number' => (int) $c['number'], 'name' => $c['name'],
                'team_id' => $c['team_id'] !== null ? (int) $c['team_id'] : null, 'team_name' => $c['team_name'],
                'color' => $c['color'] ?? null, 'photo' => $c['photo'] ?? null,
                'rank' => $rank, 'display' => $rank === null ? '' : ($labels[$rank] ?? 'Top ' . (2 * $rank - 2)),
            ];
        }
        usort($rows, fn($a, $b) => (($a['rank'] ?? PHP_INT_MAX) <=> ($b['rank'] ?? PHP_INT_MAX)) ?: strcmp($a['name'], $b['name']));
        $final = array_values(array_filter($matches, fn($m) => $m['stage'] === 'main' && !$m['next_match_id']))[0] ?? null;
        return ['format' => $format, 'rows' => $rows, 'complete' => $final && $final['status'] === 'done'];
    }
}
