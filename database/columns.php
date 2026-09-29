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
        'is_public'         => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `archived_by`',
        'has_overall'       => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `is_public`',
        'points_mode'       => "VARCHAR(20) NOT NULL DEFAULT 'list' AFTER `has_overall`",
    ],
    'teams' => [
        'color'     => 'VARCHAR(7) NULL AFTER `name`',
        'logo_file' => 'VARCHAR(255) NULL AFTER `color`',
    ],
    'contestants' => [
        'color'      => 'VARCHAR(7) NULL AFTER `details`',
        'members'    => 'TEXT NULL AFTER `details`',
        'photo_file' => 'VARCHAR(255) NULL AFTER `color`',
        'stage_bg'   => 'VARCHAR(255) NULL AFTER `photo_file`',
        'source_contestant_id' => 'INT UNSIGNED NULL AFTER `stage_bg`',
    ],
    'activities' => [
        'nature'         => 'VARCHAR(80) NULL AFTER `venue`',
        'format'         => "ENUM('score','bracket','round_robin','ranking') NOT NULL DEFAULT 'score' AFTER `status`",
        'score_label'    => 'VARCHAR(40) NULL AFTER `format`',
        'rank_direction' => "ENUM('desc','asc') NOT NULL DEFAULT 'desc' AFTER `score_label`",
        'third_place'    => 'TINYINT(1) NOT NULL DEFAULT 1 AFTER `rank_direction`',
        'scoring_method'     => "VARCHAR(20) NOT NULL DEFAULT 'average' AFTER `counts_to_overall`",
        'drop_extremes'      => 'TINYINT(1) NOT NULL DEFAULT 0 AFTER `scoring_method`',
        'score_scale'        => 'DECIMAL(6,2) NULL AFTER `drop_extremes`',
        'tie_break'          => "VARCHAR(20) NOT NULL DEFAULT 'share' AFTER `score_scale`",
        'tie_criterion_id'   => 'INT UNSIGNED NULL AFTER `tie_break`',
        'source_activity_id' => 'INT UNSIGNED NULL AFTER `tie_criterion_id`',
        'advance_count'      => 'INT UNSIGNED NULL AFTER `source_activity_id`',
        'carry_weight'       => 'DECIMAL(5,2) NOT NULL DEFAULT 0 AFTER `advance_count`',
        'certified_at'       => 'DATETIME NULL AFTER `carry_weight`',
        'certified_by'       => 'VARCHAR(150) NULL AFTER `certified_at`',
        'certified_hash'     => 'CHAR(64) NULL AFTER `certified_by`',
    ],
];