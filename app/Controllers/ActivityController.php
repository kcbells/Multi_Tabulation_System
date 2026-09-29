<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Gate;
use App\Core\Request;
use App\Core\Auth;
use App\Repositories\AccessCodeRepository;
use App\Repositories\ActivityRepository;
use App\Repositories\AwardRepository;
use App\Repositories\ContestantRepository;
use App\Repositories\CriterionRepository;
use App\Repositories\DeductionRepository;
use App\Repositories\EventRepository;
use App\Repositories\ScoreRepository;
use App\Repositories\TeamRepository;
use App\Services\ActivityLogger as Log;
use App\Services\Certification;
use App\Services\CriteriaScanService;
use App\Services\Tabulator;

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
        $siblings = $this->activities->forEvent($eventId);
        $source = (int) ($activity['source_activity_id'] ?? 0);
        $activity['certificate'] = Certification::short($activity['certified_hash'] ?? null);

        $this->ok([
            'activity' => $activity,
            'event' => ['id' => $eventId, 'title' => $event['title'] ?? '', 'structure' => $event['structure'] ?? 'multi', 'archived' => !empty($event['archived_at']),
                        'has_overall' => (int) ($event['has_overall'] ?? 1) === 1],
            // every activity of the event: the in-event menu and the "previous round" choice
            'activities' => array_map(fn($a) => ['id' => (int) $a['id'], 'title' => $a['title'], 'status' => $a['status'], 'format' => $a['format'], 'certified' => !empty($a['certified_at']), 'source_activity_id' => $a['source_activity_id'] ? (int) $a['source_activity_id'] : null], $siblings),
            'rounds' => [
                'source' => $source ? (array_values(array_filter($siblings, fn($a) => (int) $a['id'] === $source))[0] ?? null) : null,
                'later' => $this->activities->laterRounds($id),
                'advanced' => $source ? count((new ContestantRepository())->sourceIds($id)) : 0,
            ],
            'criteria' => (new CriterionRepository())->forActivity($id),
            'contestants' => (new ContestantRepository())->forActivity($id),
            'teams' => (new TeamRepository())->forEvent($eventId),
            'judges' => (new AccessCodeRepository())->judgesForEvent($eventId),
            'assigned_judges' => $this->activities->assignedJudgeIds($id),
            'has_scores' => (new ScoreRepository())->activityHasScores($id),
            'deductions' => ($activity['format'] ?? 'score') === 'score' ? (new DeductionRepository())->forActivity($id) : [],
            'awards' => ($activity['format'] ?? 'score') === 'score' ? (new AwardRepository())->forActivity($id) : [],
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
        $current = null;
        if ($id > 0) {
            $current = $this->activities->find($id);
            Certification::ensureNotCertified($current);
            if ($current['format'] !== $format && self::hasOutcomeData($current)) {
                $this->fail('This activity already has scores or results. Clear them before changing how it is decided.', 409);
            }
        }
        $data = $this->scoringSettings($id, $eventId, $format, $current) + [
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
        // only the last round decides placements: an earlier round no longer counts toward the overall
        if ($data['source_activity_id'] && ($source = $this->activities->find($data['source_activity_id'])) && (int) $source['counts_to_overall']) {
            $this->activities->setCountsToOverall((int) $source['id'], false);
            Log::record('activity.updated', Log::q($source['title']) . ' is now an earlier round of ' . Log::q($data['title']) . ' and no longer counts toward the overall', $eventId, (int) $source['id']);
        }
        $this->ok(['id' => $id], 'Activity saved.');
    }

    /**
     * Scoring options of a score-based activity (method, drop highest/lowest, 10-point scale,
     * tie-break) and its place in a series of rounds. Fields that are not sent keep their value.
     */
    private function scoringSettings(int $id, int $eventId, string $format, ?array $current): array
    {
        $keep = fn(string $field, $default) => $current[$field] ?? $default;
        $r = $this->request;
        $settings = [
            'scoring_method' => $r->has('scoring_method') ? $r->string('scoring_method', 20) : $keep('scoring_method', 'average'),
            'drop_extremes' => $r->has('drop_extremes') ? ($r->bool('drop_extremes') ? 1 : 0) : (int) $keep('drop_extremes', 0),
            'score_scale' => $r->has('score_scale') ? ((float) $r->get('score_scale') > 0 ? 10 : null) : $keep('score_scale', null),
            'tie_break' => $r->has('tie_break') ? $r->string('tie_break', 20) : $keep('tie_break', 'share'),
            'tie_criterion_id' => $r->has('tie_criterion_id') ? ($r->int('tie_criterion_id') ?: null) : $keep('tie_criterion_id', null),
            'source_activity_id' => $r->has('source_activity_id') ? ($r->int('source_activity_id') ?: null) : $keep('source_activity_id', null),
            'advance_count' => $r->has('advance_count') ? ($r->int('advance_count') ?: null) : $keep('advance_count', null),
            'carry_weight' => $r->has('carry_weight') ? (float) $r->float('carry_weight', 0) : (float) $keep('carry_weight', 0),
        ];
        if ($format !== 'score') {
            return ['scoring_method' => 'average', 'drop_extremes' => 0, 'score_scale' => null, 'tie_break' => 'share',
                    'tie_criterion_id' => null, 'source_activity_id' => null, 'advance_count' => null, 'carry_weight' => 0];
        }
        if (!in_array($settings['scoring_method'], Tabulator::METHODS, true)) {
            $this->fail('Choose how the judges\' scores are combined.');
        }
        if (!in_array($settings['tie_break'], Tabulator::TIE_BREAKS, true)) {
            $this->fail('Choose how ties are broken.');
        }
        if ($settings['tie_break'] === 'criterion') {
            $ids = $id > 0 ? array_keys((new CriterionRepository())->maxScores($id)) : [];
            if (!in_array((int) $settings['tie_criterion_id'], $ids, true)) {
                $this->fail('Choose the criterion that breaks ties (add the criteria first).');
            }
        } else {
            $settings['tie_criterion_id'] = null;
        }
        if ($current && abs((float) ($current['score_scale'] ?? 0) - (float) ($settings['score_scale'] ?? 0)) > 0.001
            && (new ScoreRepository())->activityHasScores($id)) {
            $this->fail('Judges have already scored this activity. Clear the scores before changing the scoring scale.', 409);
        }

        // rounds: the previous round must be another score-based activity of this event, without loops
        $source = (int) $settings['source_activity_id'];
        if ($source > 0) {
            $seen = [$id => true];
            for ($step = $source; $step > 0;) {
                $row = $this->activities->find($step);
                if (!$row || (int) $row['event_id'] !== $eventId || ($row['format'] ?? 'score') !== 'score') {
                    $this->fail('The previous round must be a score-based activity of this event.');
                }
                if (isset($seen[$step])) {
                    $this->fail('An activity cannot be a round of itself.');
                }
                $seen[$step] = true;
                $step = (int) ($row['source_activity_id'] ?? 0);
            }
            if ($settings['carry_weight'] < 0 || $settings['carry_weight'] > 100) {
                $this->fail('The carried-over part of the previous score must be between 0 and 100%.');
            }
            if ($settings['carry_weight'] > 0 && $settings['scoring_method'] === 'rank_sum') {
                $this->fail('Carrying over the previous round only works when judges\' scores are averaged, not with rank sum.');
            }
            if ($settings['advance_count'] !== null && ($settings['advance_count'] < 1 || $settings['advance_count'] > 500)) {
                $this->fail('Choose how many contestants advance (1 or more).');
            }
        } else {
            $settings['advance_count'] = null;
            $settings['carry_weight'] = 0;
        }
        return $settings;
    }

    public function delete(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('id'), true);
        if ((((new EventRepository())->find((int) $activity['event_id']))['structure'] ?? 'multi') === 'single') {
            $this->fail('This is the competition of a single-competition event. Delete the event instead, or edit the event and choose “Multiple activities”.');
        }
        Log::record('activity.deleted', 'Deleted activity ' . Log::q($activity['title']), (int) $activity['event_id']);
        $contestants = new ContestantRepository();
        $photos = $contestants->photoKeysForActivity((int) $activity['id']);
        $this->activities->delete((int) $activity['id']);
        foreach (array_unique($photos) as $key) {
            if (!$contestants->fileInUse($key)) { // pictures shared with another round stay
                (new CriteriaScanService())->deleteQuietly($key);
            }
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
        if ($status !== $activity['status']) {
            Certification::ensureNotCertified($activity);
        }
        if ($status === 'open') {
            if (!(new ContestantRepository())->exists($id)) {
                $this->fail('Add contestants before opening this activity.');
            }
            $format = $activity['format'] ?? 'score';
            if ($format === 'score') {
                $criteria = new CriterionRepository();
                if (!$criteria->exists($id)) {
                    $this->fail('Add the criteria before opening scoring.');
                }
                if (!CriterionRepository::isValidTotal($total = round($criteria->total($id), 2))) {
                    $this->fail('The criteria add up to ' . $total . ' points. Fix them to total exactly 100 before opening scoring.');
                }
                if (!$this->activities->assignedJudgeIds($id)) {
                    $this->fail('Assign at least one judge before opening scoring.');
                }
            } elseif (in_array($format, ['bracket', 'round_robin'], true) && !(new \App\Repositories\MatchRepository())->exists($id)) {
                $this->fail($format === 'bracket' ? 'Create the bracket before opening this activity.' : 'Create the fixtures before opening this activity.');
            }
        }
        $this->activities->setStatus($id, $status);
        $labels = ['pending' => 'set back to not started', 'open' => 'is now live', 'closed' => 'is final — results are locked'];
        if ($status !== $activity['status']) {
            Log::record('activity.status', Log::q($activity['title']) . ' ' . $labels[$status], (int) $activity['event_id'], $id);
            $this->followEventStatus((int) $activity['event_id']);
        }
        $this->ok([], ($activity['title'] ?? 'The activity') . ' ' . $labels[$status] . '.');
    }

    /**
     * The event status follows its activities: the first activity that goes live makes an
     * upcoming (or draft) event ongoing, and once every activity is final the event is completed.
     * A cancelled event is never changed.
     */
    private function followEventStatus(int $eventId): void
    {
        $events = new EventRepository();
        $event = $events->find($eventId);
        $statuses = array_column($this->activities->forEvent($eventId), 'status');
        $next = $event['status'];
        if (in_array($event['status'], ['draft', 'upcoming'], true) && array_intersect($statuses, ['open', 'closed'])) {
            $next = 'ongoing';
        }
        if (in_array($next, ['ongoing', 'upcoming'], true) && $statuses && !array_diff($statuses, ['closed'])) {
            $next = 'completed';
        }
        if ($event['status'] === 'completed' && in_array('open', $statuses, true)) {
            $next = 'ongoing'; // an activity was reopened
        }
        if ($next !== $event['status']) {
            $events->setStatus($eventId, $next);
            Log::record('event.status', 'Event status changed to ' . $next . ' (follows its activities)', $eventId);
        }
    }

    /* ------------------------------------------------ certified results */

    /** Program head / admin signs off the final results: they are locked and fingerprinted. */
    public function certify(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('id'), true);
        $id = (int) $activity['id'];
        if ($activity['status'] !== 'closed') {
            $this->fail('Finalize the activity first. Only final results can be certified.');
        }
        if (($activity['format'] ?? 'score') === 'score') {
            $panel = (new ScoreRepository())->panel($id);
            $missing = array_column(array_filter($panel, fn($j) => $j['submitted_at'] === null && (int) $j['scored'] > 0), 'name');
            if ($missing) {
                $this->fail('These judges started scoring but did not submit: ' . implode(', ', $missing) . '. Unlock and let them submit, or clear their scores, before certifying.');
            }
            if (!array_filter($panel, fn($j) => $j['submitted_at'] !== null)) {
                $this->fail('No judge has submitted scores yet, so there is nothing to certify.');
            }
        }
        $hash = Certification::hash($activity);
        $this->activities->setCertified($id, Auth::user()['name'] ?? null, $hash);
        Log::record('activity.certified', 'Certified the results of ' . Log::q($activity['title']) . ' (' . Certification::short($hash) . ')', (int) $activity['event_id'], $id, ['hash' => $hash]);
        $this->ok(['certificate' => Certification::short($hash)], 'Results certified. Certificate code ' . Certification::short($hash) . '.');
    }

    public function uncertify(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('id'), true);
        $reason = $this->request->string('reason', 255, true, 'Reason');
        if (empty($activity['certified_at'])) {
            $this->fail('These results are not certified.');
        }
        $this->activities->setCertified((int) $activity['id'], null, null);
        Log::record('activity.uncertified', 'Removed the certification of ' . Log::q($activity['title']) . ' — reason: ' . $reason, (int) $activity['event_id'], (int) $activity['id'], ['hash' => $activity['certified_hash'], 'reason' => $reason]);
        $this->ok([], 'Certification removed. The results can be changed again.');
    }

    /* ------------------------------------------------ rounds */

    /** Copies the top N of the previous round (ties at the cut all advance) into this round. */
    public function advance(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('id'));
        Certification::ensureNotCertified($activity);
        $id = (int) $activity['id'];
        $sourceId = (int) ($activity['source_activity_id'] ?? 0);
        $source = $sourceId ? $this->activities->find($sourceId) : null;
        if (!$source) {
            $this->fail('This activity is not a later round. Edit it and choose its previous round first.');
        }
        if ($source['status'] !== 'closed') {
            $this->fail('Finalize ' . $source['title'] . ' first, so the finalists come from official scores.');
        }
        $count = $this->request->int('count') ?: (int) $activity['advance_count'];
        if ($count < 1) {
            $this->fail('Choose how many contestants advance.');
        }
        $ranked = array_values(array_filter((new Tabulator())->activity($sourceId)['rows'], fn($r) => $r['rank'] !== null));
        if (!$ranked) {
            $this->fail($source['title'] . ' has no submitted scores yet.');
        }
        $cut = $ranked[min($count, count($ranked)) - 1]['rank'];
        $finalists = array_filter($ranked, fn($r) => $r['rank'] <= $cut);

        $contestants = new ContestantRepository();
        $already = array_flip($contestants->sourceIds($id));
        $added = 0;
        foreach ($finalists as $f) {
            if (isset($already[$f['id']])) {
                continue;
            }
            $row = $this->db()->one('SELECT * FROM contestants WHERE id = ?', [$f['id']]);
            $contestants->copyTo($id, $row, (int) $row['number']);
            $added++;
        }
        $tied = count($finalists) - min($count, count($ranked));
        Log::record('contestant.created', 'Advanced the top ' . $count . ' of ' . Log::q($source['title']) . ' to ' . Log::q($activity['title']) . " ($added added)", (int) $activity['event_id'], $id);
        $this->ok(['added' => $added, 'finalists' => count($finalists)],
            ($added ? "$added finalist" . ($added === 1 ? '' : 's') . ' added.' : 'Every finalist is already in this round.')
            . ($tied > 0 ? " $tied more advance because of a tie at the cut." : ''));
    }

    /* ------------------------------------------------ deductions & special awards */

    /** Facilitators and staff: take points off a contestant's final score (with a reason). */
    public function addDeduction(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('activity_id'));
        Certification::ensureNotCertified($activity);
        if (($activity['format'] ?? 'score') !== 'score') {
            $this->fail('Deductions apply to score-based activities.');
        }
        $cid = $this->request->int('contestant_id');
        $contestant = (new ContestantRepository())->find($cid);
        if (!$contestant || (int) $contestant['activity_id'] !== (int) $activity['id']) {
            $this->fail('Choose a contestant of this activity.');
        }
        $points = round((float) $this->request->float('points', 0), 2);
        if ($points <= 0 || $points > 100) {
            $this->fail('A deduction must be between 0.01 and 100 points.');
        }
        $reason = $this->request->string('reason', 255, true, 'Reason');
        (new DeductionRepository())->create((int) $activity['id'], $cid, $points, $reason, Auth::user()['name'] ?? null);
        Log::record('score.deduction', 'Deducted ' . $points . ' points from ' . Log::q($contestant['name']) . ' in ' . Log::q($activity['title']) . ' — ' . $reason, (int) $activity['event_id'], (int) $activity['id']);
        $this->ok([], $points . ' points deducted from ' . $contestant['name'] . '.');
    }

    public function deleteDeduction(): never
    {
        $repo = new DeductionRepository();
        $deduction = $repo->find($this->request->int('id')) ?? $this->fail('Deduction not found.', 404);
        $activity = Gate::authorizeActivity((int) $deduction['activity_id']);
        Certification::ensureNotCertified($activity);
        $repo->delete((int) $deduction['id']);
        Log::record('score.deduction', 'Removed a deduction of ' . (float) $deduction['points'] . ' points (' . $deduction['reason'] . ') in ' . Log::q($activity['title']), (int) $activity['event_id'], (int) $activity['id']);
        $this->ok([], 'Deduction removed.');
    }

    /** Staff: the special awards of an activity (best in a criterion, or picked by hand). */
    public function saveAwards(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('activity_id'), true);
        Certification::ensureNotCertified($activity);
        $id = (int) $activity['id'];
        $criteria = array_keys((new CriterionRepository())->maxScores($id));
        $contestants = (new ContestantRepository())->idsForActivity($id);
        $clean = [];
        foreach ($this->request->array('awards') as $i => $a) {
            $name = trim((string) ($a['name'] ?? ''));
            if ($name === '') {
                $this->fail('Award #' . ($i + 1) . ' needs a name.');
            }
            $criterion = (int) ($a['criterion_id'] ?? 0) ?: null;
            $contestant = (int) ($a['contestant_id'] ?? 0) ?: null;
            if ($criterion !== null && !in_array($criterion, $criteria, true)) {
                $this->fail('“' . $name . '” refers to a criterion outside this activity.');
            }
            if ($contestant !== null && !in_array($contestant, $contestants, true)) {
                $this->fail('“' . $name . '” refers to a contestant outside this activity.');
            }
            $clean[] = ['name' => mb_substr($name, 0, 150), 'criterion_id' => $criterion, 'contestant_id' => $criterion ? null : $contestant];
        }
        (new AwardRepository())->sync($id, $clean);
        Log::record('activity.awards', 'Saved ' . count($clean) . ' special award' . (count($clean) === 1 ? '' : 's') . ' for ' . Log::q($activity['title']), (int) $activity['event_id'], $id);
        $this->ok([], 'Special awards saved.');
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
        Certification::ensureNotCertified($activity);
        (new ScoreRepository())->resetActivity((int) $activity['id']);
        $this->activities->setStatus((int) $activity['id'], 'pending');
        Log::record('score.reset', 'Cleared all scores of ' . Log::q($activity['title']), (int) $activity['event_id'], (int) $activity['id']);
        $this->ok([], 'All scores for this activity were cleared.');
    }
}
