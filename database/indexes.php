<?php
/**
 * Performance indexes, matched to the queries in app/Repositories.
 * Applied by install/setup.php on new AND existing databases (idempotent).
 *
 * name => [table, columns, unique]
 */
return [
    // Admin list of active staff / "is there an admin?" check
    'idx_users_role_active'          => ['users', ['role', 'is_active'], false],

    // Program head dashboard: WHERE owner_id = ? ORDER BY status, start_date
    'idx_events_owner_status'        => ['events', ['owner_id', 'status', 'start_date'], false],
    // Events list: active first, archived in their own tab
    'idx_events_archived'            => ['events', ['archived_at'], false],

    // One team name per event (also serves WHERE event_id = ? ORDER BY name)
    'uq_teams_event_name'            => ['teams', ['event_id', 'name'], true],

    // Event page: WHERE event_id = ? ORDER BY sort_order, id  /  counts by status
    'idx_activities_event_order'     => ['activities', ['event_id', 'sort_order', 'id'], false],
    'idx_activities_event_status'    => ['activities', ['event_id', 'status'], false],

    // Criteria list: WHERE activity_id = ? ORDER BY sort_order, id
    'idx_criteria_activity_order'    => ['criteria', ['activity_id', 'sort_order', 'id'], false],

    // Contestant list: WHERE activity_id = ? ORDER BY number, name
    'idx_contestants_activity_number' => ['contestants', ['activity_id', 'number'], false],

    // Judge lists: WHERE event_id = ? AND role = 'judge' ORDER BY name
    'idx_codes_event_role_name'      => ['access_codes', ['event_id', 'role', 'name'], false],

    // Judge panel of an activity (reverse of the primary key)
    'idx_ja_activity_judge'          => ['judge_activities', ['activity_id', 'judge_id'], false],
    'idx_js_activity_judge'          => ['judge_submissions', ['activity_id', 'judge_id'], false],

    // Tabulation: scores JOIN contestants ON contestant_id — covering index (no table lookups)
    'idx_scores_contestant_judge'    => ['scores', ['contestant_id', 'judge_id', 'criterion_id', 'score'], false],

    // Activity logs: newest first per event, global feed, per-person history
    'idx_logs_event_time'            => ['activity_logs', ['event_id', 'id'], false],
    'idx_logs_action_time'           => ['activity_logs', ['action', 'id'], false],
    'idx_logs_actor'                 => ['activity_logs', ['actor_kind', 'actor_id', 'id'], false],
    'idx_logs_created'               => ['activity_logs', ['created_at'], false],

    // Brackets / round robin: WHERE activity_id = ? ORDER BY stage, round, position
    'idx_matches_activity_order'     => ['matches', ['activity_id', 'stage', 'round', 'position'], false],
    'idx_matches_next'               => ['matches', ['next_match_id'], false],

    // Activities by nature (reports / filters)
    'idx_activities_event_nature'    => ['activities', ['event_id', 'nature'], false],

    // Rate-limit cleanup: DELETE ... WHERE attempted_at < ?
    'idx_attempts_time'              => ['login_attempts', ['attempted_at'], false],
];
