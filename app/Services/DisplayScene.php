<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;
use App\Repositories\ActivityRepository;
use App\Repositories\ContestantRepository;

/**
 * The big screen of an event. The operator's control panel stores a small state
 * (which scene, which activity / contestant, how far the winners reveal has gone);
 * resolve() turns it into exactly what the screen draws — nothing more, so a screen
 * never receives a winner before the operator reveals it.
 *
 *   idle       event title card (between segments)
 *   spotlight  the contestant on stage: big picture, number, name, team colour
 *   standings  leaderboard of an activity (result lines only when "show scores" is on)
 *   bracket    bracket or round robin fixtures of an activity
 *   overall    overall team standings
 *   reveal     winners, announced one place at a time (3rd → 2nd → champion)
 *   message    a title and a line of text (break, next segment…)
 *   blank      black screen
 */
final class DisplayScene
{
    public const SCENES = ['idle', 'spotlight', 'standings', 'bracket', 'overall', 'reveal', 'message', 'blank'];
    public const REVEAL_PLACES = 3;

    /** Light effects drawn over any scene (assets/js/modules/stagefx.js). */
    public const EFFECTS = ['none', 'glitter', 'stars', 'confetti', 'spotlights', 'orbs', 'fireworks'];

    /** effect: the running light effect · burst: counter, each increase fires the confetti cannons once */
    public const DEFAULTS = [
        'scene' => 'idle', 'activity_id' => 0, 'contestant_id' => 0,
        'show_scores' => false, 'reveal_step' => 0, 'title' => '', 'text' => '',
        'effect' => 'none', 'burst' => 0,
    ];

    public static function normalize(array $state): array
    {
        $s = array_intersect_key($state, self::DEFAULTS) + self::DEFAULTS;
        $s['scene'] = in_array($s['scene'], self::SCENES, true) ? $s['scene'] : 'idle';
        $s['activity_id'] = (int) $s['activity_id'];
        $s['contestant_id'] = (int) $s['contestant_id'];
        $s['show_scores'] = (bool) $s['show_scores'];
        $s['reveal_step'] = max(0, min(self::REVEAL_PLACES, (int) $s['reveal_step']));
        $s['title'] = mb_substr(trim((string) $s['title']), 0, 120);
        $s['text'] = mb_substr(trim((string) $s['text']), 0, 400);
        $s['effect'] = in_array($s['effect'], self::EFFECTS, true) ? $s['effect'] : 'none';
        $s['burst'] = max(0, (int) $s['burst']);
        return $s;
    }

    /** Checks a change from the control panel and merges it into the current state. */
    public static function apply(int $eventId, array $current, array $patch): array
    {
        $current = self::normalize($current);
        if (isset($patch['activity_id']) && (int) $patch['activity_id'] !== $current['activity_id']) {
            // another activity: its own contestant on stage and a fresh reveal, unless given
            $patch += ['contestant_id' => 0, 'reveal_step' => 0];
        }
        $next = self::normalize(array_merge($current, array_intersect_key($patch, self::DEFAULTS)));
        if (isset($patch['scene']) && !in_array($patch['scene'], self::SCENES, true)) {
            throw new HttpException('Unknown screen.', 422);
        }
        if (isset($patch['effect']) && !in_array($patch['effect'], self::EFFECTS, true)) {
            throw new HttpException('Unknown effect.', 422);
        }
        if ($next['activity_id'] > 0) {
            $activity = (new ActivityRepository())->find($next['activity_id']);
            if (!$activity || (int) $activity['event_id'] !== $eventId) {
                throw new HttpException('That activity is not part of this event.', 422);
            }
        }
        if ($next['contestant_id'] > 0) {
            $c = (new ContestantRepository())->find($next['contestant_id']);
            if (!$c || (int) $c['activity_id'] !== $next['activity_id']) {
                throw new HttpException('That contestant is not in the chosen activity.', 422);
            }
        }
        $needsActivity = in_array($next['scene'], ['spotlight', 'standings', 'bracket', 'reveal'], true);
        if ($needsActivity && $next['activity_id'] <= 0) {
            throw new HttpException('Choose an activity first.', 422);
        }
        return $next;
    }

    /** What the screen draws for this state. */
    public static function resolve(array $event, array $state): array
    {
        $state = self::normalize($state);
        $out = [
            'scene' => $state['scene'],
            'state' => $state,
            'event' => [
                'id' => (int) $event['id'], 'title' => $event['title'], 'venue' => $event['venue'],
                'start_at' => $event['start_at'], 'end_at' => $event['end_at'],
            ],
        ];
        $activity = $state['activity_id'] > 0 ? (new ActivityRepository())->find($state['activity_id']) : null;
        if ($activity && (int) $activity['event_id'] !== (int) $event['id']) {
            $activity = null;
        }
        if (in_array($state['scene'], ['spotlight', 'standings', 'bracket', 'reveal'], true) && !$activity) {
            $out['scene'] = 'idle'; // the activity was deleted meanwhile
            return $out;
        }
        if ($activity) {
            $out['activity'] = [
                'id' => (int) $activity['id'], 'title' => $activity['title'], 'format' => $activity['format'],
                'status' => $activity['status'], 'nature' => $activity['nature'], 'score_label' => $activity['score_label'],
                'certified' => !empty($activity['certified_at']),
            ];
        }

        switch ($state['scene']) {
            case 'spotlight':
                $lineup = array_map([StandingsView::class, 'entry'], (new ContestantRepository())->forActivity((int) $activity['id']));
                $index = 0;
                foreach ($lineup as $i => $c) {
                    if ($c['id'] === $state['contestant_id']) {
                        $index = $i;
                    }
                }
                $out['contestant'] = $lineup[$index] ?? null;
                $out['position'] = $lineup ? $index + 1 : 0;
                $out['total'] = count($lineup);
                break;

            case 'standings':
            case 'bracket':
                $view = StandingsView::build($activity, false);
                $out += ['rows' => self::scores($view['rows'], $state['show_scores']), 'matches' => $view['matches'], 'table' => $view['table'], 'complete' => $view['complete']];
                break;

            case 'overall':
                $out['overall'] = StandingsView::overall((int) $event['id']);
                break;

            case 'reveal':
                $view = StandingsView::build($activity, false);
                $places = [];
                foreach (self::scores($view['rows'], $state['show_scores']) as $r) {
                    if ($r['rank'] !== null && $r['rank'] <= self::REVEAL_PLACES) {
                        $places[$r['rank']][] = $r;
                    }
                }
                krsort($places); // 3rd place is announced first
                $total = count($places);
                // only the places already announced are sent to the screen
                $shown = array_slice($places, 0, min($state['reveal_step'], $total), true);
                $out['places'] = array_map(fn($rank, $entries) => ['rank' => $rank, 'entries' => $entries], array_keys($shown), $shown);
                $out['total_places'] = $total;
                $out['step'] = min($state['reveal_step'], $total);
                break;

            case 'message':
                $out['title'] = $state['title'];
                $out['text'] = $state['text'];
                break;
        }
        return $out;
    }

    /** Clears the result lines when the operator keeps scores off the screen. */
    private static function scores(array $rows, bool $show): array
    {
        return $show ? $rows : array_map(fn($r) => ['display' => ''] + $r, $rows);
    }
}
