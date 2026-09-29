<?php
/** Idempotent data fixes run by install/setup.php after columns are added. */
return [
    'Event start/end times from dates' =>
        "UPDATE events SET start_at = CONCAT(start_date, ' 08:00:00') WHERE start_at IS NULL AND start_date IS NOT NULL",
    'Event end times from dates' =>
        "UPDATE events SET end_at = CONCAT(end_date, ' 17:00:00') WHERE end_at IS NULL AND end_date IS NOT NULL",
    // overall standings became optional: older events without any group had none to begin with
    // (limited to events made before the option existed, so a new event waiting for its groups keeps it)
    'Events without groups have no overall standings' =>
        "UPDATE events e SET e.has_overall = 0
          WHERE e.created_at < '2026-09-30' AND e.has_overall = 1
            AND NOT EXISTS (SELECT 1 FROM teams t WHERE t.event_id = e.id)",
    'Link contestants to the team with the same name' =>
        "UPDATE contestants c
           JOIN activities a ON a.id = c.activity_id
           JOIN teams t ON t.event_id = a.event_id AND LOWER(TRIM(t.name)) = LOWER(TRIM(c.name))
            SET c.team_id = t.id
          WHERE c.team_id IS NULL",
];