<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Database;
use App\Core\Gate;

/**
 * Live updates: a small "version" of the data a page shows. Pages poll it every few seconds
 * and reload their data only when it changed, so every record updates without a refresh.
 * Changes are seen through the activity logs (every save writes one) plus the tables that
 * change without a log entry: judges' scores, match results and ranking results.
 */
final class SyncController extends Controller
{
    public function version(): never
    {
        $user = Auth::user();
        $eventId = $this->request->int('event_id');
        if (in_array($user['role'] ?? '', ['judge', 'facilitator'], true)) {
            $eventId = (int) ($user['event_id'] ?? 0); // code users only ever see their own event
        } elseif ($eventId > 0) {
            Gate::authorizeEvent($eventId);
        }
        $this->ok(['v' => self::fingerprint($eventId)]);
    }

    /** Short hash of everything that can change what an event (or, with 0, the whole system) shows. */
    public static function fingerprint(int $eventId): string
    {
        $db = Database::instance();
        if ($eventId > 0) {
            $parts = [
                $db->value('SELECT COALESCE(MAX(id), 0) FROM activity_logs WHERE event_id = ?', [$eventId]),
                $db->one('SELECT COUNT(*) n, MAX(s.updated_at) t, COALESCE(SUM(s.score), 0) s FROM scores s JOIN criteria c ON c.id = s.criterion_id JOIN activities a ON a.id = c.activity_id WHERE a.event_id = ?', [$eventId]),
                $db->one('SELECT COUNT(*) n, MAX(j.submitted_at) t FROM judge_submissions j JOIN activities a ON a.id = j.activity_id WHERE a.event_id = ?', [$eventId]),
                $db->one('SELECT COUNT(*) n, MAX(m.updated_at) t FROM matches m JOIN activities a ON a.id = m.activity_id WHERE a.event_id = ?', [$eventId]),
                $db->one('SELECT COUNT(*) n, MAX(r.updated_at) t, COALESCE(SUM(r.value), 0) s FROM activity_results r JOIN activities a ON a.id = r.activity_id WHERE a.event_id = ?', [$eventId]),
            ];
        } else {
            $parts = [
                $db->value('SELECT COALESCE(MAX(id), 0) FROM activity_logs'),
                $db->one('SELECT COUNT(*) n, MAX(id) i FROM events'),
                $db->one('SELECT COUNT(*) n, MAX(updated_at) t, COALESCE(SUM(score), 0) s FROM scores'),
                $db->one('SELECT COUNT(*) n, MAX(submitted_at) t FROM judge_submissions'),
                $db->one('SELECT COUNT(*) n, MAX(updated_at) t FROM matches'),
                $db->one('SELECT COUNT(*) n, MAX(updated_at) t FROM activity_results'),
            ];
        }
        return substr(md5(json_encode($parts)), 0, 16);
    }
}
