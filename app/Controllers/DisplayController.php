<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Gate;
use App\Repositories\AccessCodeRepository;
use App\Repositories\ActivityRepository;
use App\Repositories\ContestantRepository;
use App\Repositories\DisplayRepository;
use App\Repositories\EventRepository;
use App\Services\DisplayScene;
use App\Services\StandingsView;

/**
 * Big screens. Every event has a main screen (overall standings, awarding) and every activity
 * its own screen, so activities held in different venues each show their own show.
 * Requests name the screen with ?screen=<activity id>; no screen = the event's main screen.
 * A facilitator assigned to activities runs only those activities' screens.
 */
final class DisplayController extends Controller
{
    /** Everything the control panel needs: the screens this user may run, activities with their line-up and the current state. */
    public function control(): never
    {
        $event = $this->event();
        $allowed = $this->allowedActivities((int) $event['id']);
        $requested = $this->request->int('screen');
        // a facilitator of some activities who opens the main screen lands on their first activity
        $screenId = $allowed !== null && !in_array($requested, $allowed, true) ? ($allowed[0] ?? 0) : $requested;
        $screen = $this->screenActivity($event, $screenId);

        $contestants = new ContestantRepository();
        $all = (new ActivityRepository())->forEvent((int) $event['id']);
        $activities = array_map(fn($a) => [
            'id' => (int) $a['id'], 'title' => $a['title'], 'format' => $a['format'], 'status' => $a['status'], 'venue' => $a['venue'] ?? null,
            'contestants' => array_map([StandingsView::class, 'entry'], $contestants->forActivity((int) $a['id'])),
        ], $screen ? array_values(array_filter($all, fn($a) => (int) $a['id'] === (int) $screen['id'])) : $all);

        $screens = $allowed === null ? [['id' => 0, 'title' => 'Main screen']] : [];
        foreach ($all as $a) {
            if ($allowed === null || in_array((int) $a['id'], $allowed, true)) {
                $screens[] = ['id' => (int) $a['id'], 'title' => $a['title'], 'venue' => $a['venue'] ?? null];
            }
        }

        $display = (new DisplayRepository())->get((int) $event['id'], $screen ? (int) $screen['id'] : 0);
        $this->ok([
            'event' => ['id' => (int) $event['id'], 'title' => $event['title'], 'venue' => $event['venue'], 'status' => $event['status'], 'is_public' => (bool) ($event['is_public'] ?? false), 'has_overall' => (int) ($event['has_overall'] ?? 1) === 1],
            'screen' => $screen ? ['id' => (int) $screen['id'], 'title' => $screen['title'], 'venue' => $screen['venue'] ?? null] : null,
            'screens' => $screens,
            'activities' => $activities,
            'state' => $this->stateFor($display['state'], $screen),
            'scenes' => DisplayScene::SCENES,
        ]);
    }

    /** What the screen draws now. */
    public function screen(): never
    {
        $event = $this->event();
        $screen = $this->requestedScreen($event);
        $display = (new DisplayRepository())->get((int) $event['id'], $screen ? (int) $screen['id'] : 0);
        $out = DisplayScene::resolve($event, $this->stateFor($display['state'], $screen));
        $this->ok($out + [
            'screen' => $screen ? ['id' => (int) $screen['id'], 'title' => $screen['title'], 'venue' => $screen['venue'] ?? null] : null,
            'v' => $this->versionOf((int) $event['id'], $display['version']),
        ]);
    }

    /** Polled by the screen every second or two: changes when the operator switches or results change. */
    public function version(): never
    {
        $event = $this->event();
        $screen = $this->requestedScreen($event);
        $version = (new DisplayRepository())->get((int) $event['id'], $screen ? (int) $screen['id'] : 0)['version'];
        $this->ok(['v' => $this->versionOf((int) $event['id'], $version)]);
    }

    public function set(): never
    {
        $event = $this->event();
        $eventId = (int) $event['id'];
        $screen = $this->requestedScreen($event);
        $screenId = $screen ? (int) $screen['id'] : 0;
        $repo = new DisplayRepository();
        $patch = $this->request->array('state');
        if ($screen) {
            $patch['activity_id'] = $screenId; // an activity's screen shows only that activity
        }
        $next = DisplayScene::apply($eventId, $this->stateFor($repo->get($eventId, $screenId)['state'], $screen), $patch);
        $version = $repo->save($eventId, $next, $screenId);
        $this->ok(['state' => $next, 'v' => $this->versionOf($eventId, $version)]);
    }

    private function versionOf(int $eventId, int $displayVersion): string
    {
        return $displayVersion . '-' . SyncController::fingerprint($eventId);
    }

    /** An activity's screen always has its activity chosen, even before the first change. */
    private function stateFor(array $state, ?array $screen): array
    {
        $state = DisplayScene::normalize($state);
        if ($screen) {
            $state['activity_id'] = (int) $screen['id'];
        }
        return $state;
    }

    /** The screen named by ?screen=, checked against what this user may run. */
    private function requestedScreen(array $event): ?array
    {
        $screenId = $this->request->int('screen');
        $allowed = $this->allowedActivities((int) $event['id']);
        if ($allowed !== null && !in_array($screenId, $allowed, true)) {
            $this->fail($screenId > 0 ? 'You run the big screens of your own activities only.' : 'The main screen is run by the event staff. Open the screen of your activity.', 403);
        }
        return $this->screenActivity($event, $screenId);
    }

    private function screenActivity(array $event, int $screenId): ?array
    {
        if ($screenId <= 0) {
            return null;
        }
        $activity = (new ActivityRepository())->find($screenId);
        if (!$activity || (int) $activity['event_id'] !== (int) $event['id']) {
            $this->fail('That activity is not part of this event.', 404);
        }
        return $activity;
    }

    /** @return int[]|null the activities a facilitator handles; null = every screen of the event */
    private function allowedActivities(int $eventId): ?array
    {
        $user = $this->user();
        if ($user['role'] !== 'facilitator') {
            return null;
        }
        $ids = (new AccessCodeRepository())->facilitatorActivityIds((int) $user['id']);
        return $ids ?: null;
    }

    /** Facilitators: their own event. Staff: an event they manage. */
    private function event(): array
    {
        $user = $this->user();
        $eventId = $user['role'] === 'facilitator' ? (int) $user['event_id'] : $this->request->int('event_id');
        Gate::authorizeEvent($eventId);
        return (new EventRepository())->find($eventId) ?? $this->fail('Event not found.', 404);
    }
}
