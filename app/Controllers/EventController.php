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

        // Overall points are not edited in the event form: new events use the standard
        // 10 / 7 / 5 (others 2) and existing events keep whatever they already have.

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
            'placement_points' => $existing['placement_points'] ?? json_encode([10, 7, 5]),
            'participation_points' => $existing['participation_points'] ?? 2,
            'owner_id' => $ownerId ?: null,
        ];

        if ($id > 0) {
            $this->events->update($id, $data);
            $changes = [];
            foreach (['title', 'venue', 'start_at', 'end_at', 'status', 'default_format', 'structure', 'owner_id'] as $field) {
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
        $activityId = $structure === 'single' ? $this->syncCompetition($id, $data, $competition) : null;
        $this->attachScannedDocument($id, $existing);
        $this->ok(['id' => $id, 'activity_id' => $activityId], 'Event saved.');
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
