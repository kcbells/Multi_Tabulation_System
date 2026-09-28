<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Gate;
use App\Core\HttpException;
use App\Repositories\ContestantRepository;
use App\Repositories\EventRepository;
use App\Repositories\TeamRepository;
use App\Services\ActivityLogger as Log;
use App\Services\DocumentIntake;
use App\Services\UploadedFile;
use App\Storage\StorageManager;
use App\Storage\TempFile;
use Throwable;

/**
 * Contestant pictures and team logos. Files live on the file server; the
 * browser reads them through this controller so access stays per event
 * (or open to anyone once the event is on the public results page).
 */
final class MediaController extends Controller
{
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp'];
    private const MAX_BYTES = 5 * 1024 * 1024;
    private const BACKGROUND_MAX_BYTES = 8 * 1024 * 1024;

    /* ------------------------------------------------ viewing (any role of the event) */

    public function contestant(): never
    {
        $c = (new ContestantRepository())->find($this->request->int('id')) ?? throw new HttpException('Not found.', 404);
        $this->authorizeView((int) $c['event_id']);
        if (!$c['photo_file']) {
            throw new HttpException('No picture.', 404);
        }
        (new DocumentIntake())->stream($c['photo_file'], null);
    }

    public function team(): never
    {
        $t = (new TeamRepository())->find($this->request->int('id')) ?? throw new HttpException('Not found.', 404);
        $this->authorizeView((int) $t['event_id']);
        if (!$t['logo_file']) {
            throw new HttpException('No logo.', 404);
        }
        (new DocumentIntake())->stream($t['logo_file'], null);
    }

    private function authorizeView(int $eventId): void
    {
        if ((new EventRepository())->isPublic($eventId)) {
            return; // shown on the public results page anyway
        }
        $user = $this->user();
        if (in_array($user['role'], ['judge', 'facilitator'], true)) {
            if ((int) $user['event_id'] !== $eventId) {
                throw new HttpException('Access denied.', 403);
            }
            return;
        }
        Gate::authorizeEvent($eventId);
    }

    /* ------------------------------------------------ contestant pictures (staff & facilitators) */

    public function uploadContestantPhoto(): never
    {
        $repo = new ContestantRepository();
        $c = $repo->find($this->request->int('contestant_id')) ?? throw new HttpException('Contestant not found.', 404);
        Gate::authorizeActivity((int) $c['activity_id']);
        $key = $this->store($this->request->file('file'), sprintf('pictures/event-%d/contestant-%d', $c['event_id'], $c['id']));
        $repo->setPhoto((int) $c['id'], $key);
        $this->deleteQuietly($c['photo_file']);
        Log::record('contestant.updated', 'Uploaded a picture for ' . Log::q($c['name']), (int) $c['event_id'], (int) $c['activity_id']);
        $this->ok([], 'Picture saved.');
    }

    public function removeContestantPhoto(): never
    {
        $repo = new ContestantRepository();
        $c = $repo->find($this->request->int('contestant_id')) ?? throw new HttpException('Contestant not found.', 404);
        Gate::authorizeActivity((int) $c['activity_id']);
        $repo->setPhoto((int) $c['id'], null);
        $this->deleteQuietly($c['photo_file']);
        $this->ok([], 'Picture removed.');
    }

    /* ------------------------------------------------ team logos (staff) */

    public function uploadTeamLogo(): never
    {
        $repo = new TeamRepository();
        $t = $repo->find($this->request->int('team_id')) ?? throw new HttpException('Team not found.', 404);
        Gate::authorizeEvent((int) $t['event_id'], true);
        $key = $this->store($this->request->file('file'), sprintf('pictures/event-%d/team-%d', $t['event_id'], $t['id']));
        $repo->setLogo((int) $t['id'], $key);
        $this->deleteQuietly($t['logo_file']);
        Log::record('team.updated', 'Uploaded a logo for team ' . Log::q($t['name']), (int) $t['event_id']);
        $this->ok([], 'Logo saved.');
    }

    public function removeTeamLogo(): never
    {
        $repo = new TeamRepository();
        $t = $repo->find($this->request->int('team_id')) ?? throw new HttpException('Team not found.', 404);
        Gate::authorizeEvent((int) $t['event_id'], true);
        $repo->setLogo((int) $t['id'], null);
        $this->deleteQuietly($t['logo_file']);
        $this->ok([], 'Logo removed.');
    }

    /* ------------------------------------------------ big screen: On stage backgrounds */

    /** A contestant's own stage background (each contestant has their own; there is no shared one). */
    public function stage(): never
    {
        $c = (new ContestantRepository())->find($this->request->int('id')) ?? throw new HttpException('Not found.', 404);
        $this->authorizeView((int) $c['event_id']);
        if (empty($c['stage_bg'])) {
            throw new HttpException('No background.', 404);
        }
        (new DocumentIntake())->stream($c['stage_bg'], null);
    }

    public function uploadStageBackground(): never
    {
        $contestant = $this->stageContestant();
        $key = $this->store($this->request->file('file'), sprintf('pictures/event-%d/stage-contestant-%d', $contestant['event_id'], $contestant['id']), self::BACKGROUND_MAX_BYTES);
        (new ContestantRepository())->setStageBackground((int) $contestant['id'], $key);
        $this->deleteQuietly($contestant['stage_bg']);
        Log::record('display.background', 'Set the stage background of ' . Log::q($contestant['name']), (int) $contestant['event_id'], (int) $contestant['activity_id']);
        $this->ok([], 'Background saved.');
    }

    public function removeStageBackground(): never
    {
        $contestant = $this->stageContestant();
        (new ContestantRepository())->setStageBackground((int) $contestant['id'], null);
        $this->deleteQuietly($contestant['stage_bg']);
        Log::record('display.background', 'Removed the stage background of ' . Log::q($contestant['name']), (int) $contestant['event_id'], (int) $contestant['activity_id']);
        $this->ok([], 'Background removed.');
    }

    /** Staff and facilitators: contestants of an event they manage. */
    private function stageContestant(): array
    {
        $contestant = (new ContestantRepository())->find($this->request->int('contestant_id')) ?? throw new HttpException('Contestant not found.', 404);
        Gate::authorizeEvent((int) $contestant['event_id']);
        return $contestant;
    }

    /* ------------------------------------------------ internals */

    private function store(?array $file, string $prefix, int $maxBytes = self::MAX_BYTES): string
    {
        $upload = new UploadedFile($file, self::IMAGE_EXT, $maxBytes);
        $scratch = TempFile::create($upload->extension);
        try {
            if (!move_uploaded_file($upload->tmpPath, $scratch)) {
                $this->fail('The picture could not be processed.');
            }
            $key = $prefix . '-' . bin2hex(random_bytes(6)) . '.' . $upload->extension;
            StorageManager::disk()->put($key, $scratch);
            return $key;
        } catch (HttpException $e) {
            throw $e;
        } catch (Throwable $e) {
            $this->fail('The picture could not be saved to the file server: ' . $e->getMessage());
        } finally {
            @unlink($scratch);
        }
    }

    private function deleteQuietly(?string $key): void
    {
        if ($key) {
            (new DocumentIntake())->deleteQuietly($key);
        }
    }
}
