<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Gate;
use App\Core\HttpException;
use App\Documents\CriteriaParser;
use App\Documents\DocumentReader;
use App\Repositories\ActivityRepository;
use App\Repositories\CriterionRepository;
use App\Repositories\ScoreRepository;
use App\Services\ActivityLogger as Log;
use App\Services\CriteriaScanService;
use App\Services\UploadedFile;
use App\Storage\StorageManager;
use Throwable;

final class CriteriaController extends Controller
{
    /** Upload a photo / PDF / DOCX, store it on the file server and return the recognized criteria. */
    public function scan(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('activity_id'), true);
        $upload = new UploadedFile(
            $this->request->file('file'),
            DocumentReader::ALLOWED_EXT,
            (int) config('storage.max_bytes')
        );
        try {
            $result = (new CriteriaScanService())->scan($activity, $upload);
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->fail('The document could not be processed: ' . $e->getMessage());
        }
        Log::record('criteria.uploaded', 'Uploaded criteria ' . Log::q($upload->originalName) . ' for ' . Log::q($activity['title']) . ' — ' . count($result['criteria']) . ' criteria detected', (int) $activity['event_id'], (int) $activity['id']);
        $this->ok($result);
    }

    /** Re-read criteria from text the user corrected. */
    public function reparse(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('activity_id'), true);
        $text = mb_substr((string) $this->request->get('text', ''), 0, 100000);
        (new ActivityRepository())->setCriteriaText((int) $activity['id'], $text);
        $parsed = CriteriaParser::parse($text);
        $this->ok([
            'criteria' => $parsed['criteria'],
            'total' => $parsed['total'],
            'warnings' => $parsed['criteria'] ? [] : ['No criteria with scores or percentages were recognized in this text.'],
        ]);
    }

    public function save(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('activity_id'), true);
        \App\Services\Certification::ensureNotCertified($activity);
        $activityId = (int) $activity['id'];
        $list = $this->request->array('criteria');
        if (!$list) {
            $this->fail('Add at least one criterion.');
        }

        $clean = [];
        foreach (array_values($list) as $i => $c) {
            $name = trim((string) ($c['name'] ?? ''));
            $max = $c['max_score'] ?? '';
            if ($name === '') {
                $this->fail('Criterion #' . ($i + 1) . ' needs a name.');
            }
            if (!is_numeric($max) || (float) $max <= 0 || (float) $max > 1000) {
                $this->fail("“{$name}” needs a maximum score between 0.01 and 1000.");
            }
            $clean[] = [
                'id' => (int) ($c['id'] ?? 0),
                'name' => mb_substr($name, 0, 200),
                'description' => mb_substr(trim((string) ($c['description'] ?? '')), 0, 1000),
                'max_score' => round((float) $max, 2),
            ];
        }

        $total = round(array_sum(array_column($clean, 'max_score')), 2);
        if (!CriterionRepository::isValidTotal($total)) {
            $diff = round(CriterionRepository::REQUIRED_TOTAL - $total, 2);
            $this->fail('The criteria must add up to exactly 100 points. They add up to ' . $total . ' (' . ($diff > 0 ? $diff . ' points missing' : abs($diff) . ' points too many') . ').');
        }

        $criteria = new CriterionRepository();
        if ((new ScoreRepository())->activityHasScores($activityId)) {
            $existing = $criteria->maxScores($activityId);
            $ids = array_filter(array_column($clean, 'id'));
            $structural = count($ids) !== count($clean) || count($ids) !== count($existing) || array_diff($ids, array_keys($existing));
            foreach ($clean as $c) {
                if (isset($existing[$c['id']]) && abs($existing[$c['id']] - $c['max_score']) > 0.001) {
                    $structural = true;
                }
            }
            if ($structural) {
                $this->fail('Judges have already scored this activity. You can rename criteria, but adding, removing or changing maximum scores requires resetting the scores first.', 409);
            }
        }

        $criteria->sync($activityId, $clean);
        Log::record('criteria.saved', 'Saved ' . count($clean) . ' criteria (total ' . $total . ') for ' . Log::q($activity['title']), (int) $activity['event_id'], $activityId);
        $this->ok([], 'Criteria saved (' . count($clean) . ' items, total ' . $total . ').');
    }

    /** Streams the original criteria document from the file server (judges: assigned activities only). */
    public function file(): never
    {
        $activity = Gate::authorizeActivityRead($this->request->int('activity_id'));
        $key = (string) ($activity['criteria_file'] ?? '');
        if ($key === '') {
            throw new HttpException('No criteria file uploaded.', 404);
        }
        (new \App\Services\DocumentIntake())->stream($key, $activity['criteria_file_name']);
    }

    public function engines(): never
    {
        Gate::authorizeActivity($this->request->int('activity_id'), true);
        $this->ok([
            'local_ocr' => DocumentReader::fromConfig()->hasLocalOcr(),
            'storage' => StorageManager::disk()->describe(),
        ]);
    }
}
