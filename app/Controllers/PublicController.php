<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Database;
use App\Core\HttpException;
use App\Repositories\ActivityRepository;
use App\Repositories\EventRepository;
use App\Services\StandingsView;

/**
 * Public results page (no sign-in). Only events a staff member published are visible, and
 * score-based activities show their standings only once final — never judges' scores.
 */
final class PublicController extends Controller
{
    public function events(): never
    {
        $this->ok(['events' => (new EventRepository())->publicList(), 'app' => ['name' => config('app.name'), 'school' => config('app.school')]]);
    }

    public function event(): never
    {
        $event = $this->publishedEvent($this->request->int('id'));
        $overall = StandingsView::overall((int) $event['id']);
        $winners = array_column($overall['activities'], null, 'id');
        $activities = [];
        foreach ((new ActivityRepository())->forEventWithStats((int) $event['id']) as $a) {
            $activities[] = [
                'id' => (int) $a['id'], 'title' => $a['title'], 'format' => $a['format'], 'status' => $a['status'],
                'nature' => $a['nature'], 'venue' => $a['venue'], 'schedule_at' => $a['schedule_at'],
                'contestants' => (int) $a['contestants'],
                'published' => StandingsView::isPublished($a),
                'winners' => $winners[(int) $a['id']]['winners'] ?? [],
            ];
        }
        $this->ok([
            'event' => [
                'id' => (int) $event['id'], 'title' => $event['title'], 'venue' => $event['venue'], 'status' => $event['status'],
                'structure' => $event['structure'], 'description' => $event['description'],
                'start_at' => $event['start_at'], 'end_at' => $event['end_at'], 'start_date' => $event['start_date'], 'end_date' => $event['end_date'],
            ],
            'activities' => $activities,
            'overall' => $overall,
        ]);
    }

    public function activity(): never
    {
        $activity = (new ActivityRepository())->find($this->request->int('id')) ?? throw new HttpException('Activity not found.', 404);
        $event = $this->publishedEvent((int) $activity['event_id']);
        $data = StandingsView::build($activity, true);
        $data['event'] = ['id' => (int) $event['id'], 'title' => $event['title'], 'structure' => $event['structure']];
        $this->ok($data);
    }

    /** Live updates: changes whenever results of the event (or, without an id, the list of events) change. */
    public function version(): never
    {
        $eventId = $this->request->int('event_id');
        if ($eventId > 0) {
            $this->publishedEvent($eventId);
            $v = SyncController::fingerprint($eventId);
        } else {
            $v = substr(md5(json_encode(Database::instance()->all(
                'SELECT id, title, status, is_public, archived_at IS NULL AS live FROM events ORDER BY id'
            ))), 0, 16);
        }
        $this->ok(['v' => $v]);
    }

    private function publishedEvent(int $id): array
    {
        $events = new EventRepository();
        if ($id <= 0 || !$events->isPublic($id)) {
            throw new HttpException('This event is not on the public results page.', 404);
        }
        $event = $events->find($id);
        if ($event['status'] === 'draft') {
            throw new HttpException('This event is not on the public results page.', 404);
        }
        return $event;
    }
}
