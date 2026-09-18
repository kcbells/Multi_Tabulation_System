<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Repositories\ActivityLogRepository;
use Throwable;

/**
 * Writes the audit trail. Logging must never break the request that triggered it,
 * so every failure is swallowed (and written to the PHP error log).
 *
 *   ActivityLogger::record('activity.created', 'Created activity “Quiz Bee”', eventId: 3, activityId: 12);
 */
final class ActivityLogger
{
    public static function record(
        string $action,
        string $description,
        ?int $eventId = null,
        ?int $activityId = null,
        array $meta = [],
        ?array $actor = null
    ): void {
        try {
            $user = $actor ?? Auth::user();
            (new ActivityLogRepository())->create([
                'event_id'    => $eventId ?: ($user['event_id'] ?? null) ?: null,
                'activity_id' => $activityId ?: null,
                'actor_kind'  => $user['kind'] ?? 'guest',
                'actor_id'    => $user['id'] ?? null,
                'actor_name'  => isset($user['name']) ? mb_substr((string) $user['name'], 0, 150) : null,
                'actor_role'  => $user['role'] ?? null,
                'action'      => mb_substr($action, 0, 60),
                'description' => mb_substr($description, 0, 500),
                'meta'        => $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
                'ip'          => substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
            ]);
        } catch (Throwable $e) {
            error_log('[tabulation] activity log failed: ' . $e->getMessage());
        }
    }

    /** “Title” with typographic quotes, trimmed for log lines. */
    public static function q(?string $text): string
    {
        return '“' . mb_substr(trim((string) $text), 0, 120) . '”';
    }
}
