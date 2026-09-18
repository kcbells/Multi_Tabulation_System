<?php
/**
 * Columns added after the first release. Applied by install/setup.php to
 * existing databases (fresh installs already have them from schema.sql).
 *
 * table => [column => definition]
 */
return [
    'events' => [
        'program_file'      => 'VARCHAR(255) NULL AFTER `owner_id`',
        'program_file_name' => 'VARCHAR(255) NULL AFTER `program_file`',
        'program_text'      => 'MEDIUMTEXT NULL AFTER `program_file_name`',
        'nature'            => 'VARCHAR(80) NULL AFTER `venue`',
        'start_at'          => 'DATETIME NULL AFTER `nature`',
        'end_at'            => 'DATETIME NULL AFTER `start_at`',
        'default_format'    => "VARCHAR(20) NOT NULL DEFAULT 'score' AFTER `status`",
        'structure'         => "ENUM('multi','single') NOT NULL DEFAULT 'multi' AFTER `default_format`",
        'archived_at'       => 'DATETIME NULL AFTER `structure`',
        'archived_by'       => 'INT UNSIGNED NULL AFTER `archived_at`',
    ],
    'teams' => [
        'color'     => 'VARCHAR(7) NULL AFTER `name`',
        'logo_file' => 'VARCHAR(255) NULL AFTER `color`',
    ],
    'contestants' => [
        'color'      => 'VARCHAR(7) NULL AFTER `details`',
        'photo_file' => 'VARCHAR(255) NULL AFTER `color`',
    ],
    'activities' => [
        'nature'         => 'VARCHAR(80) NULL AFTER `venue`',
        'format'         => "ENUM('score','bracket','round_robin','ranking') NOT NULL DEFAULT 'score' AFTER `status`",
        'score_label'    => 'VARCHAR(40) NULL AFTER `format`',
        'rank_direction' => "ENUM('desc','asc') NOT NULL DEFAULT 'desc' AFTER `score_label`",
        'third_place'    => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `rank_direction`',
    ],
];