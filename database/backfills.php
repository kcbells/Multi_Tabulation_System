<?php
/** Idempotent data fixes run by install/setup.php after columns are added. */
return [
    'Event start/end times from dates' =>
        "UPDATE events SET start_at = CONCAT(start_date, ' 08:00:00') WHERE start_at IS NULL AND start_date IS NOT NULL",
    'Event end times from dates' =>
        "UPDATE events SET end_at = CONCAT(end_date, ' 17:00:00') WHERE end_at IS NULL AND end_date IS NOT NULL",
    'Link contestants to the team with the same name' =>
        "UPDATE contestants c
           JOIN activities a ON a.id = c.activity_id
           JOIN teams t ON t.event_id = a.event_id AND LOWER(TRIM(t.name)) = LOWER(TRIM(c.name))
            SET c.team_id = t.id
          WHERE c.team_id IS NULL",
];