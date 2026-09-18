<?php
/**
 * Column definition changes for existing databases.
 * table => [column => [expected COLUMN_TYPE, full definition used for MODIFY]]
 * Only applied when the current type differs.
 */
return [
    'events' => [
        'status' => [
            "enum('draft','upcoming','ongoing','completed','cancelled')",
            "ENUM('draft','upcoming','ongoing','completed','cancelled') NOT NULL DEFAULT 'upcoming'",
        ],
    ],
];