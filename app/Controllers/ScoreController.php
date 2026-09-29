<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Gate;
use App\Core\HttpException;
use App\Core\Request;
use App\Repositories\ActivityRepository;
use App\Repositories\ContestantRepository;
use App\Repositories\CriterionRepository;
use App\Repositories\EventRepository;
use App\Repositories\ScoreRepository;
use App\Services\ActivityLogger as Log;

final class ScoreController extends Controller
{
    private ScoreRepository $scores;
    private ActivityRepository $activities;

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->scores = new ScoreRepository();
        $this->activities = new ActivityRepository();
    }

    /* ------------------------------------------------ judge */

    public function home(): never
    {
        $judge = $this->user();
        $this->ok([
            'judge' => ['name' => $judge['name']],
            'event' => (new EventRepository())->find((int) $judge['event_id']),
            'activities' => $this->activities->forJudge((int) $judge['id']),
        ]);
    }

    private function judgeActivity(): array
    {
        $activity = $this->activities->findForJudge($this->request->int('activity_id'), Auth::id())
            ?? throw new HttpException('This activity is not assigned to you.', 404);
        if (($activity['format'] ?? 'score') !== 'score') {
            throw new HttpException('This activity is decided by matches or results, not judges\' scores.', 404);
        }
        return $activity;
    }

    public function sheet(): never
    {
        $activity = $this->judgeActivity();
        $id = (int) $activity['id'];
        $this->ok([
            'activity' => [
                'id' => $id, 'title' => $activity['title'], 'description' => $activity['description'],
                'venue' => $activity['venue'], 'schedule_at' => $activity['schedule_at'], 'status' => $activity['status'],
                'has_file' => !empty($activity['criteria_file']), 'criteria_file_name' => $activity['criteria_file_name'],
                'score_scale' => self::scale($activity),
            ],
            'criteria' => (new CriterionRepository())->forActivity($id),
            'contestants' => (new ContestantRepository())->forActivity($id),
            'scores' => $this->scores->forJudge(Auth::id(), $id),
            'notes' => (object) $this->scores->notes(Auth::id(), $id),
            'submitted_at' => $this->scores->submittedAt(Auth::id(), $id),
            'submitted_contestants' => $this->scores->submittedContestants(Auth::id(), $id),
        ]);
    }

    /** 10 = every criterion is scored 0–10 and weighted by its points; null = scored out of its points. */
    public static function scale(array $activity): ?float
    {
        $scale = (float) ($activity['score_scale'] ?? 0);
        return $scale > 0 ? $scale : null;
    }

    /** A judge's private note on a contestant (kept while scoring is still editable for them). */
    public function note(): never
    {
        $activity = $this->judgeActivity();
        $cid = $this->request->int('contestant_id');
        if (!in_array($cid, (new ContestantRepository())->idsForActivity((int) $activity['id']), true)) {
            $this->fail('That contestant is not in this activity.', 404);
        }
        $this->scores->saveNote(Auth::id(), $cid, $this->request->string('note', 2000));
        $this->ok(['saved_at' => date('H:i:s')]);
    }

    /** Autosave: accepts a batch of {contestant_id, criterion_id, score|null}. */
    public function save(): never
    {
        $activity = $this->judgeActivity();
        $id = (int) $activity['id'];
        $this->ensureEditable($activity);

        $maxScores = (new CriterionRepository())->maxScores($id);
        if ($scale = self::scale($activity)) {
            $maxScores = array_map(fn() => $scale, $maxScores);
        }
        $contestantIds = array_flip((new ContestantRepository())->idsForActivity($id));
        $done = array_flip($this->scores->submittedContestants(Auth::id(), $id));
        $rows = [];
        foreach ($this->request->array('scores') as $item) {
            $cid = (int) ($item['contestant_id'] ?? 0);
            $crid = (int) ($item['criterion_id'] ?? 0);
            if (!isset($contestantIds[$cid]) || !isset($maxScores[$crid])) {
                $this->fail('A score refers to a contestant or criterion outside this activity.');
            }
            if (isset($done[$cid])) {
                $this->fail('You already submitted the scores for this contestant. Ask the facilitator to unlock them if you need changes.', 423);
            }
            $raw = $item['score'] ?? null;
            if ($raw === null || $raw === '') {
                $rows[] = ['contestant_id' => $cid, 'criterion_id' => $crid, 'score' => null];
                continue;
            }
            if (!is_numeric($raw)) {
                $this->fail('Scores must be numbers.');
            }
            $score = round((float) $raw, 2);
            if ($score < 0 || $score > $maxScores[$crid]) {
                $this->fail('Each score must be between 0 and the criterion maximum (' . $maxScores[$crid] . ').');
            }
            $rows[] = ['contestant_id' => $cid, 'criterion_id' => $crid, 'score' => $score];
        }
        if ($rows) {
            $this->scores->saveMany(Auth::id(), $rows, $id);
        }
        $this->ok(['saved' => count($rows), 'saved_at' => date('H:i:s')]);
    }

    /** Submits one contestant's scores; after the last contestant the whole sheet counts as submitted. */
    public function submitContestant(): never
    {
        $activity = $this->judgeActivity();
        $id = (int) $activity['id'];
        $this->ensureEditable($activity);
        $contestants = (new ContestantRepository())->forActivity($id);
        $cid = $this->request->int('contestant_id');
        $contestant = array_values(array_filter($contestants, fn($c) => (int) $c['id'] === $cid))[0] ?? null;
        if (!$contestant) {
            $this->fail('That contestant is not in this activity.', 404);
        }
        $criteria = count((new CriterionRepository())->maxScores($id));
        $scored = $this->scores->countForContestant(Auth::id(), $cid);
        if ($criteria === 0 || $scored < $criteria) {
            $this->fail('Score every criterion for ' . $contestant['name'] . ' before submitting (' . $scored . ' of ' . $criteria . ' done).');
        }
        $this->scores->submitContestant(Auth::id(), $cid);
        Log::record('score.contestant', 'Submitted the scores for ' . Log::q($contestant['name']) . ' in ' . Log::q($activity['title']), (int) $activity['event_id'], $id);

        $submitted = $this->scores->submittedContestants(Auth::id(), $id);
        $all = count($submitted) >= count($contestants);
        if ($all) {
            $this->scores->submit(Auth::id(), $id);
            Log::record('score.submitted', 'Submitted final scores for ' . Log::q($activity['title']), (int) $activity['event_id'], $id);
        }
        $this->ok([
            'submitted_contestants' => $submitted,
            'all_submitted' => $all,
        ], $all ? 'All contestants are submitted. Thank you!' : 'Scores for ' . $contestant['name'] . ' submitted.');
    }

    public function submit(): never
    {
        $activity = $this->judgeActivity();
        $id = (int) $activity['id'];
        $this->ensureEditable($activity);

        $expected = count((new CriterionRepository())->maxScores($id)) * count((new ContestantRepository())->idsForActivity($id));
        $scored = $this->scores->countForJudge(Auth::id(), $id);
        if ($expected === 0 || $scored < $expected) {
            $this->fail('Please score every criterion for every contestant before submitting (' . $scored . ' of ' . $expected . ' done).');
        }
        $this->scores->submit(Auth::id(), $id);
        Log::record('score.submitted', 'Submitted final scores for ' . Log::q($activity['title']), (int) $activity['event_id'], $id);
        $this->ok([], 'Your scores were submitted. Thank you!');
    }

    private function ensureEditable(array $activity): void
    {
        if ($activity['status'] !== 'open') {
            $this->fail($activity['status'] === 'closed' ? 'This activity is final — scores can no longer be changed.' : 'This activity is not live yet.', 423);
        }
        if ($this->scores->submittedAt(Auth::id(), (int) $activity['id'])) {
            $this->fail('You already submitted your scores. Ask the facilitator to unlock them if you need changes.', 423);
        }
    }

    /* ------------------------------------------------ staff & facilitators */

    public function unlock(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('activity_id'));
        $judgeId = $this->request->int('judge_id');
        if (!in_array($judgeId, $this->activities->assignedJudgeIds((int) $activity['id']), true)) {
            $this->fail('That judge is not on this activity panel.', 404);
        }
        if (!empty($activity['certified_at'])) {
            $this->fail('These results are certified. Remove the certification before unlocking a judge.', 423);
        }
        $this->scores->unlock($judgeId, (int) $activity['id'], Auth::user()['name'] ?? null);
        $judge = (new \App\Repositories\AccessCodeRepository())->find($judgeId);
        Log::record('score.unlocked', 'Unlocked the submission of ' . Log::q($judge['name'] ?? 'a judge') . ' for ' . Log::q($activity['title']), (int) $activity['event_id'], (int) $activity['id'], ['judge_id' => $judgeId]);
        $this->ok([], 'Submission unlocked — the judge can edit their scores again. Every change is recorded in the score history.');
    }

    /** Score changes and unlocks of an activity (audit view). */
    public function history(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('activity_id'));
        $this->ok([
            'changes' => $this->scores->history((int) $activity['id']),
            'unlocks' => $this->scores->unlocks((int) $activity['id']),
        ]);
    }

    /** Criterion-level scores of one judge (audit view). */
    public function judgeSheet(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('activity_id'));
        $judgeId = $this->request->int('judge_id');
        if (!in_array($judgeId, $this->activities->assignedJudgeIds((int) $activity['id']), true)) {
            $this->fail('That judge is not on this activity panel.', 404);
        }
        $this->ok([
            'criteria' => (new CriterionRepository())->forActivity((int) $activity['id']),
            'contestants' => (new ContestantRepository())->forActivity((int) $activity['id']),
            'scores' => $this->scores->forJudge($judgeId, (int) $activity['id']),
            'submitted_at' => $this->scores->submittedAt($judgeId, (int) $activity['id']),
            'score_scale' => self::scale($activity),
        ]);
    }
}
