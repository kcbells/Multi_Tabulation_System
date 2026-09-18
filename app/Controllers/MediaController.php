<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Gate;
use App\Core\HttpException;
use App\Repositories\ContestantRepository;
use App\Repositories\TeamRepository;
use App\Services\ActivityLogger as Log;
use App\Services\DocumentIntake;
use App\Services\UploadedFile;
use App\Storage\StorageManager;
use App\Storage\TempFile;
use Throwable;

/**
 * Contestant pictures and team logos. Files live on the file server; the
 * browser reads them through this controller so access stays per event.
 */
final class MediaController extends Controller
{
    private const IMAGE_EXT = ['jpg', 'jpeg', 'png', 'webp'];
    private const MAX_BYTES = 5 * 1024 * 1024;

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

    /* ------------------------------------------------ internals */

    private function store(?array $file, string $prefix): string
    {
        $upload = new UploadedFile($file, self::IMAGE_EXT, self::MAX_BYTES);
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
