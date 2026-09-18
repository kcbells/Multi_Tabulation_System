<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Gate;
use App\Core\Request;
use App\Repositories\AccessCodeRepository;
use App\Repositories\ActivityRepository;
use App\Repositories\ContestantRepository;
use App\Repositories\CriterionRepository;
use App\Repositories\EventRepository;
use App\Repositories\ScoreRepository;
use App\Repositories\TeamRepository;
use App\Services\ActivityLogger as Log;
use App\Services\CriteriaScanService;

final class ActivityController extends Controller
{
    public const FORMATS = ['score', 'bracket', 'round_robin', 'ranking'];

    public static function hasOutcomeData(array $activity): bool
    {
        $id = (int) $activity['id'];
        return (new ScoreRepository())->activityHasScores($id)
            || (new \App\Repositories\MatchRepository())->hasResults($id)
            || (new \App\Repositories\ActivityResultRepository())->hasResults($id);
    }

    private ActivityRepository $activities;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->activities = new ActivityRepository();
    }

    public function show(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('id'));
        $id = (int) $activity['id'];
        $eventId = (int) $activity['event_id'];
        $activity['has_file'] = !empty($activity['criteria_file']);
        unset($activity['criteria_file']);
        $event = (new EventRepository())->find($eventId);

        $this->ok([
            'activity' => $activity,
            'event' => ['id' => $eventId, 'title' => $event['title'] ?? '', 'structure' => $event['structure'] ?? 'multi', 'archived' => !empty($event['archived_at'])],
            'criteria' => (new CriterionRepository())->forActivity($id),
            'contestants' => (new ContestantRepository())->forActivity($id),
            'teams' => (new TeamRepository())->forEvent($eventId),
            'judges' => (new AccessCodeRepository())->judgesForEvent($eventId),
            'assigned_judges' => $this->activities->assignedJudgeIds($id),
            'has_scores' => (new ScoreRepository())->activityHasScores($id),
            'can_configure' => Gate::canConfigureEvent($eventId) && empty($event['archived_at']),
        ]);
    }

    public function save(): never
    {
        $id = $this->request->int('id');
        if ($id > 0) {
            $eventId = (int) Gate::authorizeActivity($id, true)['event_id'];
        } else {
            $eventId = $this->request->int('event_id');
            Gate::authorizeEvent($eventId, true);
        }

        $schedule = $this->request->string('schedule_at', 20);
        if ($schedule !== '') {
            $dt = \DateTime::createFromFormat('Y-m-d\TH:i', $schedule) ?: \DateTime::createFromFormat('Y-m-d H:i:s', $schedule);
            if (!$dt) {
                $this->fail('The schedule date/time is not valid.');
            }
            $schedule = $dt->format('Y-m-d H:i:s');
        }

        $event = (new EventRepository())->find($eventId);
        $single = ($event['structure'] ?? 'multi') === 'single';
        if ($single && $id === 0 && $this->activities->forEvent($eventId)) {
            $this->fail('This event is a single competition. Edit the event and choose “Multiple activities” to add more.');
        }

        $format = $this->request->string('format', 20) ?: 'score';
        if (!in_array($format, self::FORMATS, true)) {
            $this->fail('Choose how this activity is decided.');
        }
        if ($id > 0) {
            $current = $this->activities->find($id);
            if ($current['format'] !== $format && self::hasOutcomeData($current)) {
                $this->fail('This activity already has scores or results. Clear them before changing how it is decided.', 409);
            }
        }
        $data = [
            'event_id' => $eventId,
            'format' => $format,
            'score_label' => $this->request->string('score_label', 40) ?: null,
            'rank_direction' => $this->request->string('rank_direction', 4) === 'asc' ? 'asc' : 'desc',
            'third_place' => $this->request->has('third_place') ? ($this->request->bool('third_place') ? 1 : 0) : 1,
            // a single competition always carries the event's title
            'title' => $single ? $event['title'] : $this->request->string('title', 200, true, 'Activity title'),
            'description' => $this->request->string('description', 5000) ?: null,
            'venue' => $this->request->string('venue', 200) ?: null,
            'nature' => $this->request->string('nature', 80) ?: null,
            'schedule_at' => $schedule ?: null,
            'counts_to_overall' => $this->request->bool('counts_to_overall') ? 1 : 0,
            'sort_order' => $this->request->int('sort_order') ?: $this->activities->nextSortOrder($eventId),
        ];

        if ($single) {
            (new EventRepository())->setDefaultFormat($eventId, $format);
        }
        if ($id > 0) {
            $this->activities->update($id, $data);
            Log::record('activity.updated', 'Updated activity ' . Log::q($data['title']), $eventId, $id);
        } else {
            $id = $this->activities->create($data);
            Log::record('activity.created', 'Created activity ' . Log::q($data['title']), $eventId, $id);
        }
        $this->ok(['id' => $id], 'Activity saved.');
    }

    public function delete(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('id'), true);
        if ((((new EventRepository())->find((int) $activity['event_id']))['structure'] ?? 'multi') === 'single') {
            $this->fail('This is the competition of a single-competition event. Delete the event instead, or edit the event and choose “Multiple activities”.');
        }
        Log::record('activity.deleted', 'Deleted activity ' . Log::q($activity['title']), (int) $activity['event_id']);
        $photos = (new ContestantRepository())->photoKeysForActivity((int) $activity['id']);
        $this->activities->delete((int) $activity['id']);
        foreach ($photos as $key) {
            (new CriteriaScanService())->deleteQuietly($key);
        }
        if (!empty($activity['criteria_file'])) {
            (new CriteriaScanService())->deleteQuietly((string) $activity['criteria_file']);
        }
        $this->ok([], 'Activity deleted.');
    }

    /** Facilitators may open/close scoring too. */
    public function setStatus(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('id'));
        $id = (int) $activity['id'];
        $status = $this->request->string('status', 10);
        if (!in_array($status, ['pending', 'open', 'closed'], true)) {
            $this->fail('Invalid status.');
        }
        if ((new EventRepository())->isArchived((int) $activity['event_id'])) {
            $this->fail('This event is archived. Restore it before changing activities.', 423);
        }
        if ($status === 'open') {
            if (!(new ContestantRepository())->exists($id)) {
                $this->fail('Add contestants before opening this activity.');
            }
            $format = $activity['format'] ?? 'score';
            if ($format === 'score') {
                if (!(new CriterionRepository())->exists($id)) {
                    $this->fail('Add the criteria before opening scoring.');
                }
                if (!$this->activities->assignedJudgeIds($id)) {
                    $this->fail('Assign at least one judge before opening scoring.');
                }
            } elseif (in_array($format, ['bracket', 'round_robin'], true) && !(new \App\Repositories\MatchRepository())->exists($id)) {
                $this->fail($format === 'bracket' ? 'Create the bracket before opening this activity.' : 'Create the fixtures before opening this activity.');
            }
        }
        $this->activities->setStatus($id, $status);
        $labels = ['pending' => 'set to pending', 'open' => 'opened for scoring', 'closed' => 'closed — scores are now final'];
        if ($status !== $activity['status']) {
            Log::record('activity.status', ucfirst($labels[$status]) . ': ' . Log::q($activity['title']), (int) $activity['event_id'], $id);
        }
        $this->ok([], 'Activity ' . $labels[$status] . '.');
    }

    public function assignJudges(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('id'), true);
        $this->activities->syncJudges((int) $activity['id'], (int) $activity['event_id'], $this->request->array('judge_ids'));
        $count = count($this->activities->assignedJudgeIds((int) $activity['id']));
        Log::record('activity.judges', 'Set the judge panel of ' . Log::q($activity['title']) . " ($count judge" . ($count === 1 ? '' : 's') . ')', (int) $activity['event_id'], (int) $activity['id']);
        $this->ok([], 'Judge panel updated.');
    }

    public function resetScores(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('id'), true);
        (new ScoreRepository())->resetActivity((int) $activity['id']);
        $this->activities->setStatus((int) $activity['id'], 'pending');
        Log::record('score.reset', 'Cleared all scores of ' . Log::q($activity['title']), (int) $activity['event_id'], (int) $activity['id']);
        $this->ok([], 'All scores for this activity were cleared.');
    }
}
