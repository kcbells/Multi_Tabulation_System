<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Gate;
use App\Repositories\ContestantRepository;
use App\Repositories\EventRepository;
use App\Repositories\TeamRepository;
use App\Services\ActivityLogger as Log;
use App\Services\Certification;
use App\Services\EntryLook;

/** Contestants per activity — manageable by staff and the event's facilitators. */
final class ContestantController extends Controller
{
    public function save(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('activity_id'));
        Certification::ensureNotCertified($activity);
        $activityId = (int) $activity['id'];
        $repo = new ContestantRepository();

        $event = (new EventRepository())->find((int) $activity['event_id']);
        $overall = (int) ($event['has_overall'] ?? 1) === 1;
        $teamId = $overall ? $this->request->int('team_id') : 0;
        if ($teamId > 0 && !(new TeamRepository())->belongsToEvent($teamId, (int) $activity['event_id'])) {
            $this->fail('Choose a group from this event.');
        }
        $name = $this->request->string('name', 200, true, 'Name');
        if ($overall && $teamId === 0) {
            // an entry named after a group is that group's entry, so it earns overall points
            $teamId = (int) ((new TeamRepository())->findByName((int) $activity['event_id'], $name)['id'] ?? 0);
        }
        if ($overall && $teamId === 0) {
            $this->fail('This event has overall standings: choose the group (department, tribe…) this entry plays for, so its placings earn points.');
        }
        // a team entry lists its players, one per line
        $members = implode("\n", array_slice(array_values(array_filter(array_map(
            fn($m) => mb_substr(trim((string) $m), 0, 120),
            preg_split('/\r?\n/', $this->request->string('members', 5000))
        ), fn($m) => $m !== '')), 0, 60));
        $data = [
            'activity_id' => $activityId,
            'number' => $this->request->int('number') ?: $repo->nextNumber($activityId),
            'name' => $name,
            'details' => $this->request->string('details', 255) ?: null,
            'members' => $members !== '' ? $members : null,
            'team_id' => $teamId ?: null,
            'color' => $this->colorInput(),
        ];

        $id = $this->request->int('id');
        if ($id > 0) {
            $repo->update($id, $activityId, $data);
            Log::record('contestant.updated', 'Updated contestant ' . Log::q($data['name']) . ' in ' . Log::q($activity['title']), (int) $activity['event_id'], $activityId);
        } else {
            $id = $repo->create($data);
            Log::record('contestant.created', 'Added contestant ' . Log::q($data['name']) . ' to ' . Log::q($activity['title']), (int) $activity['event_id'], $activityId);
        }
        $this->ok(['id' => $id], $data['name'] . ' saved.');
    }

    private function colorInput(): ?string
    {
        $raw = $this->request->string('color', 7);
        if ($raw !== '' && EntryLook::validColor($raw) === null) {
            $this->fail('Choose a valid colour.');
        }
        return EntryLook::validColor($raw);
    }

    public function delete(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('activity_id'));
        Certification::ensureNotCertified($activity);
        $repo = new ContestantRepository();
        $contestant = $repo->find($this->request->int('id'));
        $repo->delete($this->request->int('id'), (int) $activity['id']);
        if ($contestant && (int) $contestant['activity_id'] === (int) $activity['id']) {
            foreach (array_filter([$contestant['photo_file'], $contestant['stage_bg'] ?? null]) as $key) {
                if (!$repo->fileInUse($key)) { // a finalist's picture is shared with the earlier round
                    (new \App\Services\DocumentIntake())->deleteQuietly($key);
                }
            }
        }
        Log::record('contestant.deleted', 'Removed a contestant from ' . Log::q($activity['title']), (int) $activity['event_id'], (int) $activity['id']);
        $this->ok([], 'Contestant removed.');
    }

    /** Adds one entry per team that is not yet entered in the activity. */
    public function addTeams(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('activity_id'));
        Certification::ensureNotCertified($activity);
        $activityId = (int) $activity['id'];
        $repo = new ContestantRepository();
        $teams = (new TeamRepository())->withoutEntry((int) $activity['event_id'], $activityId);
        $number = $repo->nextNumber($activityId);
        foreach ($teams as $team) {
            $repo->create([
                'activity_id' => $activityId, 'number' => $number++, 'name' => $team['name'],
                'details' => null, 'team_id' => (int) $team['id'], 'color' => null,
            ]);
        }
        if ($teams) {
            Log::record('contestant.created', 'Added ' . count($teams) . ' group entries to ' . Log::q($activity['title']), (int) $activity['event_id'], $activityId);
        }
        $this->ok([], $teams ? count($teams) . ' group entries added.' : 'Every group is already entered.');
    }
}
