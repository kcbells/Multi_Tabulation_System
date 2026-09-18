<?php
declare(strict_types=1);

namespace App\Core;

use App\Repositories\ActivityRepository;
use App\Repositories\EventRepository;

/**
 * Authorization rules.
 *   manage    — admin (all events), program head (own events), facilitator (their event)
 *   configure — admin / program head only (criteria, access codes, event settings)
 *   judge     — judge assigned to the activity
 */
final class Gate
{
    public static function canManageEvent(int $eventId): bool
    {
        $user = Auth::user();
        if (!$user || $eventId <= 0) {
            return false;
        }
        $events = new EventRepository();
        return match ($user['role']) {
            'admin'        => $events->exists($eventId),
            'program_head' => $events->isOwnedBy($eventId, (int) $user['id']),
            'facilitator'  => (int) $user['event_id'] === $eventId,
            default        => false,
        };
    }

    public static function canConfigureEvent(int $eventId): bool
    {
        return Auth::isStaff() && self::canManageEvent($eventId);
    }

    public static function authorizeEvent(int $eventId, bool $configure = false): void
    {
        $ok = $configure ? self::canConfigureEvent($eventId) : self::canManageEvent($eventId);
        if (!$ok) {
            throw new HttpException('Event not found or access denied.', 403);
        }
        // archived events stay viewable, but nothing in them can be changed
        if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (new EventRepository())->isArchived($eventId)) {
            throw new HttpException('This event is archived. Restore it before making changes.', 423);
        }
    }

    /** Returns the activity row after checking event access. */
    public static function authorizeActivity(int $activityId, bool $configure = false): array
    {
        $activity = $activityId > 0 ? (new ActivityRepository())->find($activityId) : null;
        if (!$activity) {
            throw new HttpException('Activity not found.', 404);
        }
        self::authorizeEvent((int) $activity['event_id'], $configure);
        return $activity;
    }

    /** Judges: activity must be assigned to them. Everyone else: manage access. */
    public static function authorizeActivityRead(int $activityId): array
    {
        if (Auth::role() === 'judge') {
            $activity = (new ActivityRepository())->findForJudge($activityId, Auth::id());
            if (!$activity) {
                throw new HttpException('Activity not found.', 404);
            }
            return $activity;
        }
        return self::authorizeActivity($activityId);
    }
}
