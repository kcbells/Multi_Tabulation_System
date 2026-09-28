<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Gate;
use App\Repositories\ActivityRepository;
use App\Repositories\ContestantRepository;
use App\Repositories\DisplayRepository;
use App\Repositories\EventRepository;
use App\Services\DisplayScene;
use App\Services\StandingsView;

/**
 * Big screen (TV / projector / LED wall) and its control panel.
 * The event's facilitators run it with their access code; staff can run the
 * screen of any event they manage.
 */
final class DisplayController extends Controller
{
    /** Everything the control panel needs: activities with their line-up and the current screen state. */
    public function control(): never
    {
        $event = $this->event();
        $contestants = new ContestantRepository();
        $activities = array_map(fn($a) => [
            'id' => (int) $a['id'], 'title' => $a['title'], 'format' => $a['format'], 'status' => $a['status'],
            'contestants' => array_map([StandingsView::class, 'entry'], $contestants->forActivity((int) $a['id'])),
        ], (new ActivityRepository())->forEvent((int) $event['id']));
        $display = (new DisplayRepository())->get((int) $event['id']);
        $this->ok([
            'event' => ['id' => (int) $event['id'], 'title' => $event['title'], 'venue' => $event['venue'], 'status' => $event['status'], 'is_public' => (bool) ($event['is_public'] ?? false)],
            'activities' => $activities,
            'state' => DisplayScene::normalize($display['state']),
            'scenes' => DisplayScene::SCENES,
        ]);
    }

    /** What the screen draws now. */
    public function screen(): never
    {
        $event = $this->event();
        $display = (new DisplayRepository())->get((int) $event['id']);
        $this->ok(DisplayScene::resolve($event, $display['state']) + ['v' => $this->versionOf((int) $event['id'], $display['version'])]);
    }

    /** Polled by the screen every second or two: changes when the operator switches or results change. */
    public function version(): never
    {
        $eventId = (int) $this->event()['id'];
        $this->ok(['v' => $this->versionOf($eventId, (new DisplayRepository())->get($eventId)['version'])]);
    }

    public function set(): never
    {
        $event = $this->event();
        $eventId = (int) $event['id'];
        $repo = new DisplayRepository();
        $next = DisplayScene::apply($eventId, $repo->get($eventId)['state'], $this->request->array('state'));
        $version = $repo->save($eventId, $next);
        $this->ok(['state' => $next, 'v' => $this->versionOf($eventId, $version)]);
    }

    private function versionOf(int $eventId, int $displayVersion): string
    {
        return $displayVersion . '-' . SyncController::fingerprint($eventId);
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
