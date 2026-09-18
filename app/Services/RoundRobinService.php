<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Repositories\ContestantRepository;
use App\Repositories\MatchRepository;

/**
 * Round robin: every contestant plays every other once.
 * Standings: win 3 · draw 1 · loss 0, then score difference, then points scored.
 */
final class RoundRobinService
{
    public const WIN = 3;
    public const DRAW = 1;

    private MatchRepository $matches;

    public function __construct()
    {
        $this->matches = new MatchRepository();
    }

    /** Circle-method fixtures. Returns the number of matches created. */
    public function generate(array $activity): int
    {
        $aid = (int) $activity['id'];
        $ids = array_map(fn($c) => (int) $c['id'], (new ContestantRepository())->forActivity($aid));
        if (count($ids) < 2) {
            throw new HttpException('Add at least 2 contestants before creating the fixtures.', 422);
        }
        if (count($ids) % 2 === 1) {
            $ids[] = null; // bye
        }
        $n = count($ids);

        return $this->matches->transaction(function () use ($aid, $ids, $n) {
            $this->matches->deleteForActivity($aid);
            $created = 0;
            $rotation = $ids;
            for ($round = 1; $round < $n; $round++) {
                $position = 0;
                for ($i = 0; $i < intdiv($n, 2); $i++) {
                    $a = $rotation[$i];
                    $b = $rotation[$n - 1 - $i];
                    if ($a === null || $b === null) {
                        continue;
                    }
                    // alternate sides so nobody is always listed first
                    [$a, $b] = ($round + $i) % 2 === 0 ? [$a, $b] : [$b, $a];
                    $this->matches->create([
                        'activity_id' => $aid, 'stage' => 'rr', 'round' => $round, 'position' => ++$position,
                        'contestant_a_id' => $a, 'contestant_b_id' => $b,
                    ]);
                    $created++;
                }
                // keep the first entry fixed, rotate the rest clockwise
                $rotation = array_merge([$rotation[0]], [$rotation[$n - 1]], array_slice($rotation, 1, $n - 2));
            }
            return $created;
        });
    }

    public function record(array $match, ?float $scoreA, ?float $scoreB): void
    {
        if ($match['stage'] !== 'rr') {
            throw new HttpException('Not a round robin match.', 422);
        }
        if ($scoreA === null || $scoreB === null) {
            throw new HttpException('Enter both scores.', 422);
        }
        $winner = abs($scoreA - $scoreB) < 0.0001 ? null : (int) ($scoreA > $scoreB ? $match['contestant_a_id'] : $match['contestant_b_id']);
        $this->matches->update((int) $match['id'], ['score_a' => $scoreA, 'score_b' => $scoreB, 'winner_id' => $winner, 'status' => 'done']);
    }

    public function clear(array $match): void
    {
        $this->matches->update((int) $match['id'], ['score_a' => null, 'score_b' => null, 'winner_id' => null, 'status' => 'pending']);
    }

    /** Standings table with shared ranks for identical records. */
    public static function standings(array $contestants, array $matches): array
    {
        $table = [];
        foreach ($contestants as $c) {
            $table[(int) $c['id']] = [
                'id' => (int) $c['id'], 'number' => (int) $c['number'], 'name' => $c['name'],
                'team_id' => $c['team_id'] !== null ? (int) $c['team_id'] : null, 'team_name' => $c['team_name'],
                'color' => $c['color'] ?? null, 'photo' => $c['photo'] ?? null,
                'played' => 0, 'won' => 0, 'drawn' => 0, 'lost' => 0, 'for' => 0.0, 'against' => 0.0, 'diff' => 0.0, 'points' => 0, 'rank' => null,
            ];
        }
        $anyDone = false;
        foreach ($matches as $m) {
            if ($m['stage'] !== 'rr' || $m['status'] !== 'done') {
                continue;
            }
            $a = (int) $m['contestant_a_id'];
            $b = (int) $m['contestant_b_id'];
            if (!isset($table[$a], $table[$b])) {
                continue;
            }
            $anyDone = true;
            $sa = (float) $m['score_a'];
            $sb = (float) $m['score_b'];
            foreach ([[$a, $sa, $sb], [$b, $sb, $sa]] as [$id, $own, $opp]) {
                $table[$id]['played']++;
                $table[$id]['for'] += $own;
                $table[$id]['against'] += $opp;
                if (abs($own - $opp) < 0.0001) {
                    $table[$id]['drawn']++;
                    $table[$id]['points'] += self::DRAW;
                } elseif ($own > $opp) {
                    $table[$id]['won']++;
                    $table[$id]['points'] += self::WIN;
                } else {
                    $table[$id]['lost']++;
                }
            }
        }
        $rows = array_values($table);
        foreach ($rows as &$r) {
            $r['diff'] = round($r['for'] - $r['against'], 2);
        }
        unset($r);
        usort($rows, fn($x, $y) => ($y['points'] <=> $x['points']) ?: ($y['diff'] <=> $x['diff']) ?: ($y['for'] <=> $x['for']) ?: strcmp($x['name'], $y['name']));
        if ($anyDone) {
            $prev = null;
            foreach ($rows as $i => &$r) {
                $key = $r['points'] . '|' . $r['diff'] . '|' . $r['for'];
                $r['rank'] = $key === $prev ? $rows[$i - 1]['rank'] : $i + 1;
                $prev = $key;
            }
            unset($r);
        }
        return $rows;
    }
}
