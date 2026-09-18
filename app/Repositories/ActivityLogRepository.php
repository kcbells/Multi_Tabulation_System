<?php
declare(strict_types=1);

namespace App\Repositories;

final class ActivityLogRepository extends Repository
{
    public const CATEGORIES = [
        'auth'       => 'Sign-ins',
        'event'      => 'Events',
        'program'    => 'Program flow',
        'activity'   => 'Activities',
        'team'       => 'Teams',
        'contestant' => 'Contestants',
        'criteria'   => 'Criteria',
        'code'       => 'Access codes',
        'score'      => 'Scoring',
        'result'     => 'Results',
        'user'       => 'Staff accounts',
    ];

    public function create(array $d): void
    {
        $this->db->execute(
            'INSERT INTO activity_logs (event_id, activity_id, actor_kind, actor_id, actor_name, actor_role, action, description, meta, ip)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$d['event_id'], $d['activity_id'], $d['actor_kind'], $d['actor_id'], $d['actor_name'], $d['actor_role'],
             $d['action'], $d['description'], $d['meta'], $d['ip']]
        );
    }

    /**
     * Newest first, keyset-paginated with $beforeId.
     * Filters: event_id (null = all events), category (action prefix), q (text search).
     */
    public function search(?int $eventId, ?string $category, string $q, ?int $beforeId, int $limit): array
    {
        $where = [];
        $params = [];
        if ($eventId !== null) {
            $where[] = 'l.event_id = ?';
            $params[] = $eventId;
        }
        if ($category !== null && $category !== '') {
            $where[] = 'l.action LIKE ?';
            $params[] = $category . '.%';
        }
        if ($q !== '') {
            $where[] = '(l.description LIKE ? OR l.actor_name LIKE ?)';
            $like = '%' . addcslashes($q, '%_\\') . '%';
            array_push($params, $like, $like);
        }
        if ($beforeId !== null && $beforeId > 0) {
            $where[] = 'l.id < ?';
            $params[] = $beforeId;
        }
        $params[] = $limit + 1;
        $rows = $this->db->all(
            'SELECT l.id, l.event_id, l.activity_id, l.actor_kind, l.actor_name, l.actor_role, l.action, l.description, l.ip, l.created_at,
                    e.title AS event_title
             FROM activity_logs l LEFT JOIN events e ON e.id = l.event_id
             ' . ($where ? 'WHERE ' . implode(' AND ', $where) : '') . '
             ORDER BY l.id DESC LIMIT ?',
            $params
        );
        $hasMore = count($rows) > $limit;
        return ['logs' => array_slice($rows, 0, $limit), 'has_more' => $hasMore];
    }
}
