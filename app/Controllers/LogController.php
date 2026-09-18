<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\Controller;
use App\Core\Gate;
use App\Repositories\ActivityLogRepository;

/** Activity logs (audit trail). Per event for administrators and facilitators; system-wide for administrators. Program heads cannot see them. */
final class LogController extends Controller
{
    public function index(): never
    {
        if (Auth::is('program_head')) {
            $this->fail('Activity logs are only available to administrators.', 403);
        }
        $eventId = $this->request->int('event_id');
        if ($eventId > 0) {
            Gate::authorizeEvent($eventId);
        } elseif (!Auth::is('admin')) {
            $this->fail('Choose an event to see its activity logs.', 403);
        }

        $category = $this->request->string('category', 20);
        if ($category !== '' && !isset(ActivityLogRepository::CATEGORIES[$category])) {
            $category = '';
        }
        $limit = max(10, min(100, $this->request->int('limit', 50)));
        $result = (new ActivityLogRepository())->search(
            $eventId > 0 ? $eventId : null,
            $category,
            $this->request->string('q', 100),
            $this->request->int('before') ?: null,
            $limit
        );
        $this->ok($result + ['categories' => ActivityLogRepository::CATEGORIES]);
    }
}
