<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Gate;
use App\Core\HttpException;
use App\Repositories\ActivityResultRepository;
use App\Repositories\ContestantRepository;
use App\Repositories\MatchRepository;
use App\Services\ActivityLogger as Log;
use App\Services\BracketService;
use App\Services\EntryLook;
use App\Services\PlacementService;
use App\Services\RankingService;
use App\Services\RoundRobinService;

/**
 * Bracket, round robin and ranking activities.
 * Staff and the event's facilitators record results; configuration (creating the
 * bracket / fixtures) is also open to facilitators since they run the games.
 */
final class CompetitionController extends Controller
{
    public function show(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('id'));
        $this->ok($this->payload($activity));
    }

    private function payload(array $activity): array
    {
        $aid = (int) $activity['id'];
        $contestants = (new ContestantRepository())->forActivity($aid);
        $matches = in_array($activity['format'], ['bracket', 'round_robin'], true) ? (new MatchRepository())->forActivity($aid) : [];
        $data = [
            'activity' => [
                'id' => $aid, 'event_id' => (int) $activity['event_id'], 'title' => $activity['title'], 'status' => $activity['status'],
                'format' => $activity['format'], 'score_label' => $activity['score_label'], 'rank_direction' => $activity['rank_direction'],
                'third_place' => (bool) $activity['third_place'],
            ],
            'contestants' => $contestants,
            'matches' => array_map([self::class, 'matchRow'], $matches),
            'placements' => (new PlacementService())->forActivity($activity),
        ];
        if ($activity['format'] === 'round_robin') {
            $data['standings'] = RoundRobinService::standings($contestants, $matches);
        }
        if ($activity['format'] === 'ranking') {
            $data['standings'] = RankingService::standings($contestants, (new ActivityResultRepository())->forActivity($aid), (string) $activity['rank_direction']);
        }
        return $data;
    }

    /** One match as the bracket / fixtures views read it (also used by the public page and the big screen). */
    public static function matchRow(array $m): array
    {
        return [
            'id' => (int) $m['id'], 'stage' => $m['stage'], 'round' => (int) $m['round'], 'position' => (int) $m['position'],
            'status' => $m['status'], 'is_bye' => (bool) $m['is_bye'],
            'a' => $m['contestant_a_id'] ? ['id' => (int) $m['contestant_a_id'], 'name' => $m['a_name'], 'number' => (int) $m['a_number'], 'team' => $m['a_team']] + EntryLook::of($m, 'a_') : null,
            'b' => $m['contestant_b_id'] ? ['id' => (int) $m['contestant_b_id'], 'name' => $m['b_name'], 'number' => (int) $m['b_number'], 'team' => $m['b_team']] + EntryLook::of($m, 'b_') : null,
            'score_a' => $m['score_a'] === null ? null : (float) $m['score_a'],
            'score_b' => $m['score_b'] === null ? null : (float) $m['score_b'],
            'winner_id' => $m['winner_id'] ? (int) $m['winner_id'] : null,
            'next_match_id' => $m['next_match_id'] ? (int) $m['next_match_id'] : null,
        ];
    }

    /** Creates (or recreates) the bracket or round robin fixtures. */
    public function generate(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('id'));
        $this->ensureNotClosed($activity);
        $matches = new MatchRepository();
        if ($matches->hasResults((int) $activity['id']) && !$this->request->bool('force')) {
            $this->fail('Results were already recorded. Recreating will erase them — confirm to continue.', 409);
        }
        if ($activity['format'] === 'bracket') {
            $size = (new BracketService())->generate($activity, $this->request->string('seeding', 10) === 'random' ? 'random' : 'number');
            Log::record('activity.bracket', 'Created the ' . $size . '-slot bracket of ' . Log::q($activity['title']), (int) $activity['event_id'], (int) $activity['id']);
            $message = 'Bracket created.';
        } elseif ($activity['format'] === 'round_robin') {
            $count = (new RoundRobinService())->generate($activity);
            Log::record('activity.bracket', 'Created ' . $count . ' round robin fixtures for ' . Log::q($activity['title']), (int) $activity['event_id'], (int) $activity['id']);
            $message = $count . ' fixtures created.';
        } else {
            $this->fail('This activity does not use matches.');
        }
        $this->ok($this->payload((new \App\Repositories\ActivityRepository())->find((int) $activity['id'])), $message);
    }

    public function recordMatch(): never
    {
        [$match, $activity] = $this->authorizedMatch();
        $this->ensureNotClosed($activity);
        $scoreA = $this->request->float('score_a');
        $scoreB = $this->request->float('score_b');
        foreach ([$scoreA, $scoreB] as $s) {
            if ($s !== null && ($s < 0 || $s > 1000000)) {
                $this->fail('Scores must be between 0 and 1,000,000.');
            }
        }
        if ($match['stage'] === 'rr') {
            (new RoundRobinService())->record($match, $scoreA, $scoreB);
        } else {
            (new BracketService())->record($match, $scoreA, $scoreB, $this->request->string('winner', 1));
        }
        $fresh = (new MatchRepository())->forActivity((int) $activity['id']);
        $row = array_values(array_filter($fresh, fn($m) => (int) $m['id'] === (int) $match['id']))[0];
        $score = $row['score_a'] !== null ? ' ' . (float) $row['score_a'] . '–' . (float) $row['score_b'] . ' ' : ' vs ';
        $winner = $row['winner_id'] ? ((int) $row['winner_id'] === (int) $row['contestant_a_id'] ? $row['a_name'] : $row['b_name']) : null;
        Log::record('activity.match', 'Recorded ' . $row['a_name'] . $score . $row['b_name'] . ($winner ? ' — ' . $winner . ' wins' : ' — draw') . ' in ' . Log::q($activity['title']), (int) $activity['event_id'], (int) $activity['id']);
        $this->ok($this->payload($activity), 'Result saved.');
    }

    public function clearMatch(): never
    {
        [$match, $activity] = $this->authorizedMatch();
        $this->ensureNotClosed($activity);
        if ($match['stage'] === 'rr') {
            (new RoundRobinService())->clear($match);
        } else {
            (new BracketService())->clear($match);
        }
        Log::record('activity.match', 'Cleared a match result in ' . Log::q($activity['title']), (int) $activity['event_id'], (int) $activity['id']);
        $this->ok($this->payload($activity), 'Result cleared.');
    }

    /** Ranking format: save result values for many contestants at once. */
    public function saveResults(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('id'));
        if ($activity['format'] !== 'ranking') {
            $this->fail('This activity does not use ranking results.');
        }
        $this->ensureNotClosed($activity);
        $valid = array_flip((new ContestantRepository())->idsForActivity((int) $activity['id']));
        $rows = [];
        foreach ($this->request->array('results') as $item) {
            $cid = (int) ($item['contestant_id'] ?? 0);
            if (!isset($valid[$cid])) {
                $this->fail('A result refers to a contestant outside this activity.');
            }
            $raw = $item['value'] ?? null;
            if ($raw !== null && $raw !== '' && !is_numeric($raw)) {
                $this->fail('Results must be numbers.');
            }
            $rows[] = [
                'contestant_id' => $cid,
                'value' => ($raw === null || $raw === '') ? null : round((float) $raw, 3),
                'remarks' => mb_substr(trim((string) ($item['remarks'] ?? '')), 0, 255),
            ];
        }
        (new ActivityResultRepository())->saveMany((int) $activity['id'], $rows);
        Log::record('activity.results', 'Saved ranking results for ' . Log::q($activity['title']), (int) $activity['event_id'], (int) $activity['id']);
        $this->ok($this->payload($activity), 'Results saved.');
    }

    /** Bracket: swap two first-round contestants (drag and drop), only before any result. */
    public function swap(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('id'));
        $this->ensureNotClosed($activity);
        if ($activity['format'] !== 'bracket') {
            $this->fail('Only brackets can be rearranged.');
        }
        (new BracketService())->swap($activity, $this->request->int('first'), $this->request->int('second'));
        Log::record('activity.bracket', 'Rearranged the bracket of ' . Log::q($activity['title']), (int) $activity['event_id'], (int) $activity['id']);
        $this->ok($this->payload($activity), 'Teams swapped.');
    }

    private function authorizedMatch(): array
    {
        $match = (new MatchRepository())->find($this->request->int('match_id')) ?? throw new HttpException('Match not found.', 404);
        $activity = Gate::authorizeActivity((int) $match['activity_id']);
        return [$match, $activity];
    }

    private function ensureNotClosed(array $activity): void
    {
        if ($activity['status'] === 'closed') {
            $this->fail('This activity is closed. Reopen it to change results.', 423);
        }
    }
}
