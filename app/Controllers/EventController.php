<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Gate;
use App\Core\Request;
use App\Repositories\AccessCodeRepository;
use App\Repositories\ActivityRepository;
use App\Repositories\EventRepository;
use App\Repositories\TeamRepository;
use App\Repositories\UserRepository;
use App\Services\ActivityLogger as Log;
use App\Core\Session;
use App\Documents\DocumentReader;
use App\Documents\EventDocumentParser;
use App\Services\DocumentIntake;
use App\Services\UploadedFile;
use Throwable;

final class EventController extends Controller
{
    public const STATUSES = ['draft', 'upcoming', 'ongoing', 'completed', 'cancelled'];

    private EventRepository $events;

    /** 'Y-m-d\\TH:i' / 'Y-m-d H:i' → 'Y-m-d H:i:s'; '' → null; invalid → false */
    private static function dateTime(string $value): string|false|null
    {
        if ($value === '') {
            return null;
        }
        $value = str_replace('T', ' ', $value);
        $dt = \DateTime::createFromFormat('Y-m-d H:i', substr($value, 0, 16));
        return $dt ? $dt->format('Y-m-d H:i:00') : false;
    }

    /** Quick status change from the event page (staff and the event's facilitators). */
    public function setStatus(): never
    {
        $id = $this->request->int('id');
        Gate::authorizeEvent($id);
        $status = $this->request->string('status', 20);
        if (!in_array($status, self::STATUSES, true)) {
            $this->fail('Choose a valid status.');
        }
        $event = $this->events->find($id);
        if ($event['status'] !== $status) {
            $this->events->setStatus($id, $status);
            Log::record('event.status', 'Changed event status to ' . $status, $id);
        }
        $this->ok(['status' => $status], 'Event status: ' . ucfirst($status) . '.');
    }

    /** Shows or hides the event on the public results page. */
    public function publish(): never
    {
        $id = $this->request->int('id');
        Gate::authorizeEvent($id, true);
        $public = $this->request->bool('public');
        $this->events->setPublic($id, $public);
        Log::record('event.public', $public ? 'Published the event on the public results page' : 'Removed the event from the public results page', $id);
        $this->ok(['is_public' => $public], $public ? 'The event is now on the public results page.' : 'The event is no longer on the public results page.');
    }

    public function __construct(Request $request)
    {
        parent::__construct($request);
        $this->events = new EventRepository();
    }

    public function index(): never
    {
        $isAdmin = Auth::is('admin');
        $events = $this->events->listWithStats($isAdmin ? null : Auth::id());
        foreach ($events as &$e) {
            unset($e['program_text'], $e['program_file'], $e['program_file_name']);
        }
        $this->ok([
            'events' => $events,
            'owners' => $isAdmin ? (new UserRepository())->activeOwners() : [],
        ]);
    }

    public function show(): never
    {
        $id = $this->request->int('id');
        Gate::authorizeEvent($id);
        $canConfigure = Gate::canConfigureEvent($id);
        $event = $this->events->find($id);
        $event['has_document'] = !empty($event['program_file']);
        $event['document_name'] = $event['program_file_name'];
        unset($event['program_file'], $event['program_text'], $event['program_file_name']);
        $this->ok([
            'event' => $event,
            'teams' => (new TeamRepository())->forEvent($id),
            'activities' => (new ActivityRepository())->forEventWithStats($id),
            'codes' => $canConfigure ? (new AccessCodeRepository())->forEvent($id) : [],
            'can_configure' => $canConfigure,
        ]);
    }

    public function save(): never
    {
        $id = $this->request->int('id');
        $existing = null;
        if ($id > 0) {
            Gate::authorizeEvent($id, true);
            $existing = $this->events->find($id);
        }

        // Starting / ending date and time (datetime-local), with plain dates accepted for older clients
        $startAt = self::dateTime($this->request->string('start_at', 20) ?: ($this->request->string('start_date', 10) ? $this->request->string('start_date', 10) . ' 08:00' : ''));
        $endAt = self::dateTime($this->request->string('end_at', 20) ?: ($this->request->string('end_date', 10) ? $this->request->string('end_date', 10) . ' 17:00' : ''));
        if ($startAt === false || $endAt === false) {
            $this->fail('The starting and ending date/time must be valid.');
        }
        if ($startAt && $endAt && $endAt < $startAt) {
            $this->fail('The event cannot end before it starts.');
        }
        $start = $startAt ? substr($startAt, 0, 10) : '';
        $end = $endAt ? substr($endAt, 0, 10) : '';

        // Overall points: 1st, 2nd, 3rd… (standard 10 / 7 / 5) and the points for every other placing
        // (standard 2). Fields that are not sent keep the event's current values.
        [$placementPoints, $participationPoints] = $this->overallPoints($existing);

        $ownerId = $existing ? (int) $existing['owner_id'] : Auth::id();
        if (Auth::is('admin') && ($requested = $this->request->int('owner_id')) > 0 && (new UserRepository())->find($requested)) {
            $ownerId = $requested;
        }
        $status = $this->request->string('status', 20);
        $format = $this->request->string('default_format', 20) ?: ($existing['default_format'] ?? 'score');
        if (!in_array($format, ActivityController::FORMATS, true)) {
            $this->fail('Choose how the activities are decided.');
        }

        // single = the event itself is the competition (one built-in activity); multi = several activities
        $structure = $this->request->string('structure', 10) ?: ($existing['structure'] ?? 'multi');
        if (!in_array($structure, ['multi', 'single'], true)) {
            $this->fail('Choose whether this event is one competition or has several activities.');
        }
        $competition = null;
        if ($structure === 'single' && $existing) {
            $current = (new ActivityRepository())->forEvent($id);
            if (count($current) > 1) {
                $this->fail('This event already has ' . count($current) . ' activities. Delete the extra activities before making it a single competition.');
            }
            $competition = $current ? (new ActivityRepository())->find((int) $current[0]['id']) : null;
            if ($competition && $competition['format'] !== $format && ActivityController::hasOutcomeData($competition)) {
                $this->fail('The competition already has scores or results. Clear them before changing how it is decided.', 409);
            }
        }

        $data = [
            'title' => $this->request->string('title', 200, true, 'Event title'),
            'description' => $this->request->string('description', 5000) ?: null,
            'venue' => $this->request->string('venue', 200) ?: null,
            'nature' => $existing['nature'] ?? null, // nature of activity now lives on each activity
            'start_at' => $startAt ?: null,
            'end_at' => $endAt ?: null,
            'start_date' => $start ?: null,
            'end_date' => $end ?: null,
            'status' => in_array($status, self::STATUSES, true) ? $status : 'upcoming',
            'default_format' => $format,
            'structure' => $structure,
            // overall standings: groups (departments, tribes…) collect points from every activity
            'has_overall' => $this->request->has('has_overall') ? ($this->request->bool('has_overall') ? 1 : 0) : (int) ($existing['has_overall'] ?? 1),
            'points_mode' => in_array($mode = $this->request->string('points_mode', 20) ?: ($existing['points_mode'] ?? 'list'), ['list', 'countdown'], true) ? $mode : 'list',
            'placement_points' => $placementPoints,
            'participation_points' => $participationPoints,
            'owner_id' => $ownerId ?: null,
        ];

        if ($id > 0) {
            $this->events->update($id, $data);
            $changes = [];
            foreach (['title', 'venue', 'start_at', 'end_at', 'status', 'default_format', 'structure', 'owner_id', 'has_overall', 'points_mode', 'placement_points', 'participation_points'] as $field) {
                if ((string) ($existing[$field] ?? '') !== (string) ($data[$field] ?? '')) {
                    $changes[] = $field;
                }
            }
            Log::record('event.updated', 'Updated event ' . Log::q($data['title']) . ($changes ? ' (' . implode(', ', $changes) . ')' : ''), $id);
            if (($existing['status'] ?? '') !== $data['status']) {
                Log::record('event.status', 'Changed event status to ' . $data['status'], $id);
            }
        } else {
            $id = $this->events->create($data);
            Log::record('event.created', 'Created event ' . Log::q($data['title']) . ($structure === 'single' ? ' (single competition)' : ''), $id);
        }
        // groups typed in the event form (one per line): created once, existing names are skipped
        $groups = 0;
        if ($data['has_overall']) {
            $teams = new TeamRepository();
            foreach ($this->request->array('groups') as $groupName) {
                $groupName = mb_substr(trim((string) $groupName), 0, 150);
                if ($groupName !== '' && !$teams->findByName($id, $groupName)) {
                    $teams->create($id, $groupName, null);
                    $groups++;
                }
            }
            if ($groups) {
                Log::record('team.created', 'Added ' . $groups . ' group' . ($groups === 1 ? '' : 's') . ' for the overall standings', $id);
            }
        }
        $activityId = $structure === 'single' ? $this->syncCompetition($id, $data, $competition) : null;
        $this->attachScannedDocument($id, $existing);
        $this->ok(['id' => $id, 'activity_id' => $activityId], 'Event saved.');
    }

    /** @return array{0:string, 1:float} placement points as JSON ("[10,7,5]") and the points for other placings */
    private function overallPoints(?array $existing): array
    {
        $points = $existing['placement_points'] ?? json_encode([10, 7, 5]);
        if ($this->request->has('placement_points')) {
            $raw = $this->request->get('placement_points');
            $list = is_array($raw) ? $raw : preg_split('/[\s,\/]+/', trim((string) $raw), -1, PREG_SPLIT_NO_EMPTY);
            $clean = [];
            foreach ($list as $p) {
                if (!is_numeric($p) || (float) $p < 0 || (float) $p > 1000) {
                    $this->fail('Placement points must be numbers from 0 to 1000, e.g. 10, 7, 5.');
                }
                $clean[] = round((float) $p, 2) + 0;
            }
            if (!$clean || count($clean) > 20) {
                $this->fail('Enter the points for 1st, 2nd, 3rd… (1 to 20 places).');
            }
            for ($i = 1; $i < count($clean); $i++) {
                if ($clean[$i] > $clean[$i - 1]) {
                    $this->fail('A lower place cannot earn more points than a higher place.');
                }
            }
            $points = json_encode($clean);
        }
        $participation = (float) ($existing['participation_points'] ?? 2);
        if ($this->request->has('participation_points')) {
            $participation = (float) $this->request->float('participation_points', 0);
            $last = json_decode($points, true);
            if ($participation < 0 || $participation > (float) end($last)) {
                $this->fail('Points for other placings must be between 0 and the points of the last listed place.');
            }
        }
        return [$points, round($participation, 2)];
    }

    /** Live Ops: every activity of the event at a glance, for running the event day. */
    public function ops(): never
    {
        $id = $this->request->int('id') ?: (int) (Auth::user()['event_id'] ?? 0);
        Gate::authorizeEvent($id);
        $event = $this->events->find($id);
        $tabulator = new \App\Services\Tabulator();
        $activities = [];
        foreach ((new ActivityRepository())->forEventWithStats($id) as $a) {
            $row = [
                'id' => (int) $a['id'], 'title' => $a['title'], 'format' => $a['format'], 'status' => $a['status'],
                'schedule_at' => $a['schedule_at'], 'venue' => $a['venue'],
                'certified' => !empty($a['certified_at']), 'source_activity_id' => $a['source_activity_id'] ? (int) $a['source_activity_id'] : null,
                'contestants' => (int) $a['contestants'], 'criteria' => (int) $a['criteria'],
                'matches' => (int) $a['matches'], 'matches_done' => (int) $a['matches_done'], 'results' => (int) $a['results'],
                'judges' => [], 'leader' => null, 'alerts' => [],
            ];
            if ($a['format'] === 'score') {
                $r = $tabulator->activity((int) $a['id'], true);
                $row['judges'] = array_map(fn($j) => [
                    'id' => $j['id'], 'name' => $j['name'], 'is_active' => $j['is_active'], 'scored' => $j['scored'], 'expected' => $j['expected'],
                    'submitted' => $j['submitted'], 'flags' => $j['flags'],
                ], $r['judges']);
                $lead = array_values(array_filter($r['rows'], fn($x) => $x['rank'] === 1));
                $row['leader'] = $lead ? implode(' / ', array_column($lead, 'name')) : null;
                if ($a['status'] === 'open' && !$row['judges']) {
                    $row['alerts'][] = 'No judges assigned';
                }
                foreach ($r['judges'] as $j) {
                    foreach ($j['flags'] as $flag) {
                        $row['alerts'][] = $j['name'] . ': ' . ['flat' => 'gives nearly the same total to everyone', 'harsh' => 'scores far below the panel', 'generous' => 'scores far above the panel'][$flag];
                    }
                }
            }
            $activities[] = $row;
        }
        $this->ok([
            'event' => ['id' => $id, 'title' => $event['title'], 'status' => $event['status'], 'is_public' => (bool) $event['is_public'], 'archived' => !empty($event['archived_at'])],
            'activities' => $activities,
            'can_configure' => Gate::canConfigureEvent($id),
            'generated_at' => date('Y-m-d H:i:s'),
        ]);
    }

    /** Full backup of an event as JSON (everything except the access codes themselves). */
    public function export(): never
    {
        $id = $this->request->int('id');
        Gate::authorizeEvent($id, true);
        $db = $this->db();
        $event = $this->events->find($id);
        unset($event['program_text']);
        $in = 'SELECT id FROM activities WHERE event_id = ?';
        $contestantsIn = 'SELECT c.id FROM contestants c JOIN activities a ON a.id = c.activity_id WHERE a.event_id = ?';
        $data = [
            'format' => 'coc-tabulation-event-backup', 'version' => 1, 'exported_at' => date('c'),
            'event' => $event,
            'teams' => $db->all('SELECT id, name, color FROM teams WHERE event_id = ?', [$id]),
            'activities' => $db->all('SELECT * FROM activities WHERE event_id = ?', [$id]),
            'criteria' => $db->all("SELECT * FROM criteria WHERE activity_id IN ($in)", [$id]),
            'contestants' => $db->all("SELECT id, activity_id, team_id, number, name, details, color, source_contestant_id FROM contestants WHERE activity_id IN ($in)", [$id]),
            'judges' => $db->all("SELECT id, role, name, is_active FROM access_codes WHERE event_id = ?", [$id]),
            'judge_activities' => $db->all("SELECT * FROM judge_activities WHERE activity_id IN ($in)", [$id]),
            'scores' => $db->all("SELECT judge_id, contestant_id, criterion_id, score, updated_at FROM scores WHERE contestant_id IN ($contestantsIn)", [$id]),
            'judge_submissions' => $db->all("SELECT * FROM judge_submissions WHERE activity_id IN ($in)", [$id]),
            'contestant_submissions' => $db->all("SELECT * FROM contestant_submissions WHERE contestant_id IN ($contestantsIn)", [$id]),
            'score_history' => $db->all("SELECT * FROM score_history WHERE activity_id IN ($in)", [$id]),
            'deductions' => $db->all("SELECT * FROM deductions WHERE activity_id IN ($in)", [$id]),
            'awards' => $db->all("SELECT * FROM awards WHERE activity_id IN ($in)", [$id]),
            'matches' => $db->all("SELECT * FROM matches WHERE activity_id IN ($in)", [$id]),
            'activity_results' => $db->all("SELECT * FROM activity_results WHERE activity_id IN ($in)", [$id]),
            'overall' => (new \App\Services\Tabulator())->overall($id),
        ];
        Log::record('event.exported', 'Downloaded a backup of the event', $id);
        $name = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $event['title']) . '_backup_' . date('Ymd_His') . '.json';
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $name . '"');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ------------------------------------------------------------------ scanned event documents */

    /**
     * Upload an event memo / program / proposal: it goes to the file server, is read (PDF, DOCX or OCR)
     * and the detected event details and activities come back for the user to confirm.
     * Nothing is created here; the confirmed data is saved with events.save / activities.save.
     */
    public function scan(): never
    {
        $eventId = $this->request->int('event_id');
        if ($eventId > 0) {
            Gate::authorizeEvent($eventId, true);
        }
        $upload = new UploadedFile($this->request->file('file'), DocumentReader::ALLOWED_EXT, (int) config('storage.max_bytes'));
        try {
            $result = (new DocumentIntake())->intake($upload, 'event-documents/scans', fn(string $text) => EventDocumentParser::parse($text));
        } catch (Throwable $e) {
            $this->fail('The document could not be processed: ' . $e->getMessage());
        }

        // the stored file is attached when the user confirms; the token proves it came from this session
        $token = bin2hex(random_bytes(16));
        $pending = Session::get('event_scans', []);
        $pending = array_slice($pending, -9, null, true); // keep the last few scans only
        $pending[$token] = ['key' => $result['key'], 'name' => $upload->originalName, 'text' => mb_substr($result['text'], 0, 60000)];
        Session::set('event_scans', $pending);

        $parsed = $result['parsed'];
        $warnings = $result['warnings'];
        if (trim($result['text']) === '') {
            $warnings[] = 'No readable text was found. Check that the photo is clear, or fill in the details yourself.';
        } elseif (!$parsed['found']) {
            $warnings[] = 'Text was read, but no event details or activities were recognised. You can still type them in below.';
        } elseif (!$parsed['activities']) {
            $warnings[] = 'No list of activities was found. Add them below, or leave the list empty.';
        }

        Log::record('event.scanned', 'Scanned the event document ' . Log::q($upload->originalName) . ' (' . ($parsed['activities'] ? count($parsed['activities']) . ' activities' : 'no activities') . ' detected)', $eventId ?: null);
        $this->ok([
            'token' => $token,
            'file_name' => $upload->originalName,
            'engine' => $result['engine'],
            'text' => $result['text'],
            'warnings' => $warnings,
            'event' => ['title' => $parsed['title'], 'venue' => $parsed['venue'], 'start_at' => $parsed['start_at'], 'end_at' => $parsed['end_at']],
            'activities' => $parsed['activities'],
            'head' => $parsed['head'],
            'judges' => $parsed['judges'],
            'facilitators' => $parsed['facilitators'],
            'found' => $parsed['found'],
        ]);
    }

    /** events.save with document_token: the scanned file becomes the event's document. */
    private function attachScannedDocument(int $eventId, ?array $existing): void
    {
        $token = $this->request->string('document_token', 64);
        if ($token === '') {
            return;
        }
        $pending = Session::get('event_scans', []);
        $scan = $pending[$token] ?? null;
        if (!$scan) {
            return; // expired session: the event is still saved, just without the file
        }
        unset($pending[$token]);
        Session::set('event_scans', $pending);
        $this->events->setDocument($eventId, $scan['key'], $scan['name'], $scan['text']);
        if (!empty($existing['program_file']) && $existing['program_file'] !== $scan['key']) {
            (new DocumentIntake())->deleteQuietly($existing['program_file']);
        }
        Log::record('event.document', 'Attached the scanned document ' . Log::q($scan['name']), $eventId);
    }

    /** Open the event's scanned document. */
    public function document(): never
    {
        $id = $this->request->int('id');
        Gate::authorizeEvent($id);
        $event = $this->events->find($id);
        if (empty($event['program_file'])) {
            $this->fail('This event has no document.', 404);
        }
        (new DocumentIntake())->stream($event['program_file'], $event['program_file_name']);
    }

    /** A single-competition event keeps exactly one activity mirroring the event's title, venue, schedule and format. */
    private function syncCompetition(int $eventId, array $event, ?array $competition): int
    {
        $activities = new ActivityRepository();
        $nature = $this->request->has('nature') ? ($this->request->string('nature', 80) ?: null) : ($competition['nature'] ?? null);
        $fields = [
            'event_id' => $eventId,
            'title' => $event['title'],
            'venue' => $event['venue'],
            'schedule_at' => $event['start_at'],
            'format' => $event['default_format'],
            'nature' => $nature,
        ];
        if ($competition) {
            $activities->update((int) $competition['id'], $fields + $competition);
            return (int) $competition['id'];
        }
        $id = $activities->create($fields + [
            'description' => null, 'score_label' => null, 'rank_direction' => 'desc', 'third_place' => 1,
            'counts_to_overall' => 1, 'sort_order' => 1,
        ]);
        Log::record('activity.created', 'Created the competition of ' . Log::q($event['title']), $eventId, $id);
        return $id;
    }

    /** Staff who may archive, restore or delete (works on archived events too). */
    private function ownedEvent(): array
    {
        $id = $this->request->int('id');
        if (!Gate::canConfigureEvent($id)) {
            $this->fail('Event not found or access denied.', 403);
        }
        return $this->events->find($id) ?? $this->fail('Event not found.', 404);
    }

    public function archive(): never
    {
        $event = $this->ownedEvent();
        if (!$event['archived_at']) {
            $this->events->setArchived((int) $event['id'], Auth::id());
            Log::record('event.archived', 'Archived event ' . Log::q($event['title']), (int) $event['id']);
        }
        $this->ok([], 'Event archived. It is hidden from the events list and its access codes no longer work.');
    }

    public function restore(): never
    {
        $event = $this->ownedEvent();
        if ($event['archived_at']) {
            $this->events->setArchived((int) $event['id'], null);
            Log::record('event.restored', 'Restored event ' . Log::q($event['title']) . ' from the archive', (int) $event['id']);
        }
        $this->ok([], 'Event restored.');
    }

    /** What deleting the event would remove, for the confirmation window. */
    public function deletePreview(): never
    {
        $event = $this->ownedEvent();
        $this->ok(['title' => $event['title'], 'counts' => array_map('intval', $this->events->contentCounts((int) $event['id']))]);
    }

    public function delete(): never
    {
        $event = $this->ownedEvent();
        $id = (int) $event['id'];
        // the user must type the event title: a slip of the mouse cannot delete a whole event
        $typed = mb_strtolower(trim(preg_replace('/\s+/u', ' ', $this->request->string('confirm_title', 200))));
        if ($typed !== mb_strtolower(trim(preg_replace('/\s+/u', ' ', (string) $event['title'])))) {
            $this->fail('Type the event title exactly to confirm deleting it.');
        }
        $files = (new ActivityRepository())->criteriaFilesForEvent($id);
        $files[] = $event['program_file'] ?? null; // uploaded before program flows were removed
        $files = array_merge($files, (new \App\Repositories\ContestantRepository())->photoKeysForEvent($id), (new TeamRepository())->logoKeysForEvent($id));

        // Logged before deleting so the entry still names the event (event_id becomes NULL afterwards).
        Log::record('event.deleted', 'Deleted event ' . Log::q($event['title'] ?? ''), $id);
        $this->events->delete($id);
        $intake = new DocumentIntake();
        foreach (array_filter($files) as $key) {
            $intake->deleteQuietly($key);
        }
        $this->ok([], 'Event deleted.');
    }
}
