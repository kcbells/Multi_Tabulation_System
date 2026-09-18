<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Database;
use App\Core\HttpException;
use App\Repositories\ContestantRepository;
use App\Repositories\MatchRepository;

/**
 * Single-elimination bracket.
 *  - Contestants play in order (1 vs 2, 3 vs 4, 5 vs 6 …), by their number or a random draw,
 *    inside a bracket of the next power of two.
 *  - With an uneven number, the last contestant gets a bye; unused spots are empty ("void") matches.
 *    A contestant only gets a bye when the other side of the bracket has nobody to play: never
 *    a win without a result, and nobody gets more than one bye.
 *  - Recording a result moves the winner into the next match; semifinal losers
 *    move into the optional 3rd-place match.
 *  - Before any result, two round-1 contestants can swap places (drag in the bracket).
 */
final class BracketService
{
    private MatchRepository $matches;

    public function __construct()
    {
        $this->matches = new MatchRepository();
    }

    public function generate(array $activity, string $seeding = 'number'): int
    {
        $aid = (int) $activity['id'];
        $contestants = (new ContestantRepository())->forActivity($aid);
        $n = count($contestants);
        if ($n < 2) {
            throw new HttpException('Add at least 2 contestants before creating the bracket.', 422);
        }
        if ($seeding === 'random') {
            shuffle($contestants);
        }
        $size = 1;
        while ($size < $n) {
            $size *= 2;
        }
        $rounds = (int) log($size, 2);
        $slots = self::layout(array_map(fn($c) => (int) $c['id'], $contestants), $size);

        return $this->matches->transaction(function () use ($aid, $activity, $size, $rounds, $slots) {
            $this->matches->deleteForActivity($aid);

            // Create from the final backwards so every match knows where its winner goes.
            $ids = [];
            for ($r = $rounds; $r >= 1; $r--) {
                $count = intdiv($size, 2 ** $r);
                for ($p = 1; $p <= $count; $p++) {
                    $ids[$r][$p] = $this->matches->create([
                        'activity_id' => $aid, 'stage' => 'main', 'round' => $r, 'position' => $p,
                        'next_match_id' => $r < $rounds ? $ids[$r + 1][(int) ceil($p / 2)] : null,
                        'next_slot' => $r < $rounds ? ($p % 2 === 1 ? 'a' : 'b') : null,
                    ]);
                }
            }
            if (!empty($activity['third_place']) && $rounds >= 2) {
                $this->matches->create(['activity_id' => $aid, 'stage' => 'third', 'round' => $rounds, 'position' => 1]);
            }
            foreach ($slots as $i => $pair) {
                $this->matches->update($ids[1][$i + 1], ['contestant_a_id' => $pair[0], 'contestant_b_id' => $pair[1]]);
            }
            $this->reseat($aid);
            return $size;
        });
    }

    /**
     * Round-1 pairs for contestants in order.
     * Real matches come first (1 v 2, 3 v 4 …), then the odd one out; empty spots sit beside a
     * filled spot so nobody passes two rounds without playing.
     * @param int[] $ids
     * @return array<int, array{0:?int,1:?int}>
     */
    public static function layout(array $ids, int $size): array
    {
        $units = [];
        for ($i = 0; $i + 1 < count($ids); $i += 2) {
            $units[] = [$ids[$i], $ids[$i + 1]];
        }
        $bye = count($ids) % 2 === 1 ? [end($ids), null] : null;
        $spots = intdiv($size, 2);                       // round-1 matches
        $pairs = intdiv($spots, 2);                      // round-2 matches (0 when size is 2)
        if ($pairs === 0) {
            return $units ?: [$bye];
        }
        $voids = $spots - count($units) - ($bye ? 1 : 0);
        $full = $pairs - $voids;                         // round-2 matches fed by two filled spots
        $filled = $units;
        if ($bye) {
            // the bye goes into a full pair, so its winner still has to play next round
            array_splice($filled, $voids > 0 ? max(0, 2 * $full - 1) : count($filled), 0, [$bye]);
        }
        $out = [];
        $k = 0;
        for ($p = 0; $p < $pairs; $p++) {
            $out[] = $filled[$k++] ?? [null, null];
            $out[] = $p < $full ? ($filled[$k++] ?? [null, null]) : [null, null];
        }
        return $out;
    }

    /** Swaps two round-1 contestants (before any result is recorded). */
    public function swap(array $activity, int $first, int $second): void
    {
        $aid = (int) $activity['id'];
        if ($first === $second) {
            return;
        }
        if ($this->matches->hasResults($aid)) {
            throw new HttpException('Results were already recorded. Clear them (or recreate the bracket) before moving teams.', 409);
        }
        $round1 = array_values(array_filter($this->matches->forActivity($aid), fn($m) => $m['stage'] === 'main' && (int) $m['round'] === 1));
        $where = [];
        foreach ($round1 as $m) {
            foreach (['a', 'b'] as $slot) {
                $cid = (int) $m['contestant_' . $slot . '_id'];
                if ($cid === $first || $cid === $second) {
                    $where[$cid] = [(int) $m['id'], $slot];
                }
            }
        }
        if (count($where) !== 2) {
            throw new HttpException('Both teams must be in the first round.', 422);
        }
        $this->matches->transaction(function () use ($aid, $where, $first, $second) {
            [$idA, $slotA] = $where[$first];
            [$idB, $slotB] = $where[$second];
            $this->matches->update($idA, ['contestant_' . $slotA . '_id' => $second]);
            $this->matches->update($idB, ['contestant_' . $slotB . '_id' => $first]);
            $this->reseat($aid);
        });
    }

    /** Records a result. $winnerSlot 'a' | 'b' | '' (decided by the scores). */
    public function record(array $match, ?float $scoreA, ?float $scoreB, string $winnerSlot): array
    {
        if ($match['stage'] === 'rr') {
            throw new HttpException('Not a bracket match.', 422);
        }
        if ($match['is_bye']) {
            throw new HttpException('This is a bye — the contestant advances automatically.', 422);
        }
        if (!$match['contestant_a_id'] || !$match['contestant_b_id']) {
            throw new HttpException('Both contestants must be known before recording this match.', 422);
        }
        if (!in_array($winnerSlot, ['a', 'b'], true)) {
            if ($scoreA === null || $scoreB === null) {
                throw new HttpException('Enter both scores or choose the winner.', 422);
            }
            if (abs($scoreA - $scoreB) < 0.0001) {
                throw new HttpException('The scores are tied — choose who advances (e.g. after overtime or a tiebreak).', 422);
            }
            $winnerSlot = $scoreA > $scoreB ? 'a' : 'b';
        }
        $winner = (int) ($winnerSlot === 'a' ? $match['contestant_a_id'] : $match['contestant_b_id']);
        $loser = (int) ($winnerSlot === 'a' ? $match['contestant_b_id'] : $match['contestant_a_id']);

        return $this->matches->transaction(function () use ($match, $scoreA, $scoreB, $winner, $loser) {
            if ($match['status'] === 'done' && (int) $match['winner_id'] !== $winner) {
                $this->ensureLaterMatchesOpen($match);
                $this->retract($match);
            }
            $this->matches->update((int) $match['id'], [
                'score_a' => $scoreA, 'score_b' => $scoreB, 'winner_id' => $winner, 'status' => 'done',
            ]);
            $this->advance($this->matches->find((int) $match['id']), $winner, $loser);
            $this->resolve((int) $match['activity_id']);
            return $this->matches->find((int) $match['id']);
        });
    }

    public function clear(array $match): void
    {
        if ($match['is_bye']) {
            throw new HttpException('A bye cannot be cleared. Recreate the bracket to change the seeding.', 422);
        }
        if ($match['status'] !== 'done') {
            return;
        }
        $this->matches->transaction(function () use ($match) {
            $this->ensureLaterMatchesOpen($match);
            $this->retract($match);
            $this->matches->update((int) $match['id'], ['score_a' => null, 'score_b' => null, 'winner_id' => null, 'status' => 'pending']);
        });
    }

    /**
     * Final placements from the matches played so far.
     * @return array<int,int> contestant id => rank
     */
    public static function placements(array $matches): array
    {
        $main = array_values(array_filter($matches, fn($m) => $m['stage'] === 'main'));
        if (!$main) {
            return [];
        }
        $rounds = max(array_map(fn($m) => (int) $m['round'], $main));
        $third = array_values(array_filter($matches, fn($m) => $m['stage'] === 'third'))[0] ?? null;
        $ranks = [];
        foreach ($main as $m) {
            if ($m['status'] !== 'done' || $m['is_bye'] || !$m['winner_id']) {
                continue;
            }
            $loser = (int) ((int) $m['winner_id'] === (int) $m['contestant_a_id'] ? $m['contestant_b_id'] : $m['contestant_a_id']);
            $r = (int) $m['round'];
            if ($r === $rounds) {
                $ranks[(int) $m['winner_id']] = 1;
                $ranks[$loser] = 2;
            } elseif ($r === $rounds - 1) {
                $ranks[$loser] = 3; // refined by the 3rd-place match below
            } else {
                $ranks[$loser] = 2 ** ($rounds - $r) + 1;
            }
        }
        if ($third && $third['status'] === 'done' && $third['winner_id']) {
            $ranks[(int) $third['winner_id']] = 3;
            if (!$third['is_bye']) {
                $loser = (int) ((int) $third['winner_id'] === (int) $third['contestant_a_id'] ? $third['contestant_b_id'] : $third['contestant_a_id']);
                $ranks[$loser] = 4;
            }
        }
        return $ranks;
    }

    /** 1-based seed numbers for each bracket slot, e.g. size 8 → [1,8,4,5,2,7,3,6]. */
    public static function seedOrder(int $size): array
    {
        $order = [1, 2];
        while (count($order) < $size) {
            $next = [];
            $sum = count($order) * 2 + 1;
            foreach ($order as $seed) {
                $next[] = $seed;
                $next[] = $sum - $seed;
            }
            $order = $next;
        }
        return $order;
    }

    /* ------------------------------------------------------------ internals */

    /** Clears everything after round 1 and works out the byes again from the round-1 seating. */
    private function reseat(int $aid): void
    {
        foreach ($this->matches->forActivity($aid) as $m) {
            if ($m['stage'] === 'rr') {
                continue;
            }
            $fields = ['score_a' => null, 'score_b' => null, 'winner_id' => null, 'status' => 'pending', 'is_bye' => 0];
            if ($m['stage'] !== 'main' || (int) $m['round'] > 1) {
                $fields += ['contestant_a_id' => null, 'contestant_b_id' => null];
            }
            $this->matches->update((int) $m['id'], $fields);
        }
        foreach ($this->matches->forActivity($aid) as $m) {
            if ($m['stage'] !== 'main' || (int) $m['round'] !== 1) {
                continue;
            }
            $a = $m['contestant_a_id'] ? (int) $m['contestant_a_id'] : null;
            $b = $m['contestant_b_id'] ? (int) $m['contestant_b_id'] : null;
            if ($a === null && $b === null) {
                $this->matches->update((int) $m['id'], ['is_bye' => 1, 'status' => 'done']); // empty spot
            } elseif ($a === null || $b === null) {
                $winner = $a ?? $b;
                $this->matches->update((int) $m['id'], ['winner_id' => $winner, 'is_bye' => 1, 'status' => 'done']);
                $this->advance($this->matches->find((int) $m['id']), $winner, null);
            }
        }
        $this->resolve($aid);
    }

    /**
     * Later-round byes: a match fed by an empty spot passes its one contestant on (or becomes
     * empty itself); a 3rd-place match with only one possible semifinal loser goes to that loser.
     */
    private function resolve(int $aid): void
    {
        for ($guard = 0; $guard < 12; $guard++) {
            $all = $this->matches->forActivity($aid);
            $byId = [];
            $feeders = [];
            foreach ($all as $m) {
                $byId[(int) $m['id']] = $m;
                if ($m['next_match_id']) {
                    $feeders[(int) $m['next_match_id']][] = $m;
                }
            }
            $isVoid = fn($m) => $m['is_bye'] && $m['status'] === 'done' && !$m['winner_id'];
            $changed = false;
            foreach ($all as $m) {
                if ($m['stage'] !== 'main' || (int) $m['round'] === 1 || $m['status'] === 'done') {
                    continue;
                }
                $feed = $feeders[(int) $m['id']] ?? [];
                $voids = count(array_filter($feed, $isVoid));
                if ($voids === 0) {
                    continue;
                }
                if ($voids === count($feed)) {
                    $this->matches->update((int) $m['id'], ['is_bye' => 1, 'status' => 'done']);
                    $changed = true;
                    continue;
                }
                $only = $m['contestant_a_id'] ?: $m['contestant_b_id'];
                if ($only) {
                    $this->matches->update((int) $m['id'], ['winner_id' => (int) $only, 'is_bye' => 1, 'status' => 'done']);
                    $this->advance($this->matches->find((int) $m['id']), (int) $only, null);
                } else {
                    $this->matches->update((int) $m['id'], ['is_bye' => 1]); // waiting for the other side
                }
                $changed = true;
            }
            // 3rd place: a semifinal that was a bye has no loser
            foreach ($all as $m) {
                if ($m['stage'] !== 'third' || $m['status'] === 'done') {
                    continue;
                }
                $final = array_values(array_filter($all, fn($x) => $x['stage'] === 'main' && !$x['next_match_id']))[0] ?? null;
                $semis = $final ? ($feeders[(int) $final['id']] ?? []) : [];
                $byeSemis = array_filter($semis, fn($s) => $s['is_bye']);
                if (!$byeSemis) {
                    continue;
                }
                $only = $m['contestant_a_id'] ?: $m['contestant_b_id'];
                if ($only) {
                    $this->matches->update((int) $m['id'], ['winner_id' => (int) $only, 'is_bye' => 1, 'status' => 'done']);
                    $changed = true;
                } elseif (!$m['is_bye']) {
                    $this->matches->update((int) $m['id'], ['is_bye' => 1]);
                    $changed = true;
                }
            }
            if (!$changed) {
                return;
            }
        }
    }

    private function advance(array $match, int $winner, ?int $loser): void
    {
        if ($match['next_match_id']) {
            $this->matches->update((int) $match['next_match_id'], ['contestant_' . $match['next_slot'] . '_id' => $winner]);
        }
        if ($loser !== null && $this->isSemifinal($match)) {
            $third = $this->thirdPlaceMatch((int) $match['activity_id']);
            if ($third) {
                $this->matches->update((int) $third['id'], [((int) $match['position'] === 1 ? 'contestant_a_id' : 'contestant_b_id') => $loser]);
            }
        }
    }

    /** Removes this match's winner/loser from the matches they were moved into (and undoes byes that followed). */
    private function retract(array $match): void
    {
        if ($match['next_match_id']) {
            $next = $this->matches->find((int) $match['next_match_id']);
            $this->matches->update((int) $match['next_match_id'], ['contestant_' . $match['next_slot'] . '_id' => null]);
            if ($next && $next['is_bye'] && $next['winner_id']) {
                $this->retract($next);
                $this->matches->update((int) $next['id'], ['winner_id' => null, 'status' => 'pending']);
            }
        }
        if ($this->isSemifinal($match)) {
            $third = $this->thirdPlaceMatch((int) $match['activity_id']);
            if ($third) {
                $this->matches->update((int) $third['id'], [((int) $match['position'] === 1 ? 'contestant_a_id' : 'contestant_b_id') => null]);
                if ($third['is_bye'] && $third['winner_id']) {
                    $this->matches->update((int) $third['id'], ['winner_id' => null, 'status' => 'pending']);
                }
            }
        }
    }

    private function ensureLaterMatchesOpen(array $match): void
    {
        $blocked = false;
        if ($match['next_match_id']) {
            $next = $this->matches->find((int) $match['next_match_id']);
            // a bye that followed automatically does not count, but whatever came after it does
            if ($next && $next['is_bye'] && $next['winner_id']) {
                $this->ensureLaterMatchesOpen($next);
            } else {
                $blocked = $next && $next['status'] === 'done';
            }
        }
        if (!$blocked && $this->isSemifinal($match)) {
            $third = $this->thirdPlaceMatch((int) $match['activity_id']);
            $blocked = $third && $third['status'] === 'done' && !$third['is_bye'];
        }
        if ($blocked) {
            throw new HttpException('A later match already has a result. Clear that match first, then change this one.', 409);
        }
    }

    private function isSemifinal(array $match): bool
    {
        if ($match['stage'] !== 'main' || !$match['next_match_id']) {
            return false;
        }
        $next = $this->matches->find((int) $match['next_match_id']);
        return $next && !$next['next_match_id'] && $next['stage'] === 'main';
    }

    private function thirdPlaceMatch(int $activityId): ?array
    {
        return Database::instance()->one("SELECT * FROM matches WHERE activity_id = ? AND stage = 'third' LIMIT 1", [$activityId]);
    }
}
