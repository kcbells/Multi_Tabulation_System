<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Gate;
use App\Repositories\TeamRepository;
use App\Services\ActivityLogger as Log;
use App\Services\EntryLook;

/** Teams (colleges, departments, groups) that earn points toward the overall standing. */
final class TeamController extends Controller
{
    public function save(): never
    {
        $teams = new TeamRepository();
        $eventId = $this->request->int('event_id');
        Gate::authorizeEvent($eventId, true);
        $id = $this->request->int('id');
        $name = $this->request->string('name', 150, true, 'Group name');
        $rawColor = $this->request->string('color', 7);
        $color = EntryLook::validColor($rawColor);
        if ($rawColor !== '' && $color === null) {
            $this->fail('Choose a valid colour.');
        }

        if ($teams->nameExists($eventId, $name, $id)) {
            $this->fail('A group with that name already exists in this event.');
        }
        if ($id > 0) {
            if (!$teams->belongsToEvent($id, $eventId)) {
                $this->fail('Group not found.', 404);
            }
            $teams->update($id, $name, $color);
            Log::record('team.updated', 'Updated team ' . Log::q($name), $eventId);
        } else {
            $id = $teams->create($eventId, $name, $color);
            Log::record('team.created', 'Added team ' . Log::q($name), $eventId);
        }
        $this->ok(['id' => $id], 'Group saved.');
    }

    public function delete(): never
    {
        $teams = new TeamRepository();
        $team = $teams->find($this->request->int('id')) ?? $this->fail('Group not found.', 404);
        Gate::authorizeEvent((int) $team['event_id'], true);
        $teams->delete((int) $team['id']);
        if ($team['logo_file']) {
            (new \App\Services\DocumentIntake())->deleteQuietly($team['logo_file']);
        }
        Log::record('team.deleted', 'Removed team ' . Log::q($team['name']), (int) $team['event_id']);
        $this->ok([], 'Team removed. Its contestants are kept without a team.');
    }
}
