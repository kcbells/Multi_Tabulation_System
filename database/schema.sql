-- PHINMA COC Multi-Event Tabulation System schema (MySQL 5.7+/8.x, MariaDB 10.3+)
-- Performance indexes live in database/indexes.php and are applied by install/setup.php.

CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(120) NOT NULL,
    username      VARCHAR(60)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('admin','program_head') NOT NULL DEFAULT 'program_head',
    program       VARCHAR(120) NULL,
    is_active     TINYINT(1) NOT NULL DEFAULT 1,
    last_login_at DATETIME NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS events (
    id                   INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title                VARCHAR(200) NOT NULL,
    description          TEXT NULL,
    venue                VARCHAR(200) NULL,
    nature               VARCHAR(80) NULL,
    start_at             DATETIME NULL,
    end_at               DATETIME NULL,
    start_date           DATE NULL,
    end_date             DATE NULL,
    status               ENUM('draft','upcoming','ongoing','completed','cancelled') NOT NULL DEFAULT 'upcoming',
    default_format       VARCHAR(20) NOT NULL DEFAULT 'score',
    structure            ENUM('multi','single') NOT NULL DEFAULT 'multi',
    archived_at          DATETIME NULL,
    archived_by          INT UNSIGNED NULL,
    is_public            TINYINT(1) NOT NULL DEFAULT 0,
    has_overall          TINYINT(1) NOT NULL DEFAULT 1,
    points_mode          VARCHAR(20) NOT NULL DEFAULT 'list',
    placement_points     VARCHAR(255) NOT NULL DEFAULT '[10,7,5]',
    participation_points DECIMAL(6,2) NOT NULL DEFAULT 2,
    owner_id             INT UNSIGNED NULL,
    program_file         VARCHAR(255) NULL,
    program_file_name    VARCHAR(255) NULL,
    program_text         MEDIUMTEXT NULL,
    created_at           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_events_owner FOREIGN KEY (owner_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS teams (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id   INT UNSIGNED NOT NULL,
    name       VARCHAR(150) NOT NULL,
    color      VARCHAR(7) NULL,
    logo_file  VARCHAR(255) NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_teams_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS activities (
    id                 INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id           INT UNSIGNED NOT NULL,
    title              VARCHAR(200) NOT NULL,
    description        TEXT NULL,
    venue              VARCHAR(200) NULL,
    schedule_at        DATETIME NULL,
    status             ENUM('pending','open','closed') NOT NULL DEFAULT 'pending',
    nature             VARCHAR(80) NULL,
    format             ENUM('score','bracket','round_robin','ranking') NOT NULL DEFAULT 'score',
    score_label        VARCHAR(40) NULL,
    rank_direction     ENUM('desc','asc') NOT NULL DEFAULT 'desc',
    third_place        TINYINT(1) NOT NULL DEFAULT 1,
    counts_to_overall  TINYINT(1) NOT NULL DEFAULT 1,
    scoring_method     VARCHAR(20) NOT NULL DEFAULT 'average',
    drop_extremes      TINYINT(1) NOT NULL DEFAULT 0,
    score_scale        DECIMAL(6,2) NULL,
    tie_break          VARCHAR(20) NOT NULL DEFAULT 'share',
    tie_criterion_id   INT UNSIGNED NULL,
    source_activity_id INT UNSIGNED NULL,
    advance_count      INT UNSIGNED NULL,
    carry_weight       DECIMAL(5,2) NOT NULL DEFAULT 0,
    certified_at       DATETIME NULL,
    certified_by       VARCHAR(150) NULL,
    certified_hash     CHAR(64) NULL,
    criteria_file      VARCHAR(255) NULL,
    criteria_file_name VARCHAR(255) NULL,
    criteria_text      MEDIUMTEXT NULL,
    sort_order         INT NOT NULL DEFAULT 0,
    created_at         DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_activities_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS criteria (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id INT UNSIGNED NOT NULL,
    name        VARCHAR(200) NOT NULL,
    description TEXT NULL,
    max_score   DECIMAL(7,2) NOT NULL,
    sort_order  INT NOT NULL DEFAULT 0,
    CONSTRAINT fk_criteria_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contestants (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id INT UNSIGNED NOT NULL,
    team_id     INT UNSIGNED NULL,
    number      INT NOT NULL DEFAULT 0,
    name        VARCHAR(200) NOT NULL,
    details     VARCHAR(255) NULL,
    members     TEXT NULL,
    color       VARCHAR(7) NULL,
    photo_file  VARCHAR(255) NULL,
    stage_bg    VARCHAR(255) NULL,
    source_contestant_id INT UNSIGNED NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_contestants_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_contestants_team FOREIGN KEY (team_id) REFERENCES teams(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS access_codes (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id     INT UNSIGNED NOT NULL,
    code         VARCHAR(16) NOT NULL UNIQUE,
    role         ENUM('judge','facilitator') NOT NULL,
    name         VARCHAR(150) NOT NULL,
    is_active    TINYINT(1) NOT NULL DEFAULT 1,
    last_used_at DATETIME NULL,
    created_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_codes_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS judge_activities (
    judge_id    INT UNSIGNED NOT NULL,
    activity_id INT UNSIGNED NOT NULL,
    PRIMARY KEY (judge_id, activity_id),
    CONSTRAINT fk_ja_judge FOREIGN KEY (judge_id) REFERENCES access_codes(id) ON DELETE CASCADE,
    CONSTRAINT fk_ja_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS scores (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    judge_id      INT UNSIGNED NOT NULL,
    contestant_id INT UNSIGNED NOT NULL,
    criterion_id  INT UNSIGNED NOT NULL,
    score         DECIMAL(7,2) NOT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uq_score (judge_id, contestant_id, criterion_id),
    CONSTRAINT fk_scores_judge FOREIGN KEY (judge_id) REFERENCES access_codes(id) ON DELETE CASCADE,
    CONSTRAINT fk_scores_contestant FOREIGN KEY (contestant_id) REFERENCES contestants(id) ON DELETE CASCADE,
    CONSTRAINT fk_scores_criterion FOREIGN KEY (criterion_id) REFERENCES criteria(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS judge_submissions (
    judge_id     INT UNSIGNED NOT NULL,
    activity_id  INT UNSIGNED NOT NULL,
    submitted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (judge_id, activity_id),
    CONSTRAINT fk_js_judge FOREIGN KEY (judge_id) REFERENCES access_codes(id) ON DELETE CASCADE,
    CONSTRAINT fk_js_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS contestant_submissions (
    judge_id      INT UNSIGNED NOT NULL,
    contestant_id INT UNSIGNED NOT NULL,
    submitted_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (judge_id, contestant_id),
    CONSTRAINT fk_cs_judge FOREIGN KEY (judge_id) REFERENCES access_codes(id) ON DELETE CASCADE,
    CONSTRAINT fk_cs_contestant FOREIGN KEY (contestant_id) REFERENCES contestants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ip           VARCHAR(45) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_attempts_ip (ip, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Audit trail: who did what, when. Kept when an event is deleted (event_id becomes NULL).
CREATE TABLE IF NOT EXISTS activity_logs (
    id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    event_id    INT UNSIGNED NULL,
    activity_id INT UNSIGNED NULL,
    actor_kind  ENUM('staff','code','guest') NOT NULL DEFAULT 'guest',
    actor_id    INT UNSIGNED NULL,
    actor_name  VARCHAR(150) NULL,
    actor_role  VARCHAR(20) NULL,
    action      VARCHAR(60) NOT NULL,
    description VARCHAR(500) NOT NULL,
    meta        TEXT NULL,
    ip          VARCHAR(45) NULL,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_logs_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bracket (single elimination) and round robin games
CREATE TABLE IF NOT EXISTS matches (
    id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id     INT UNSIGNED NOT NULL,
    stage           ENUM('main','third','rr') NOT NULL DEFAULT 'main',
    round           SMALLINT UNSIGNED NOT NULL,
    position        SMALLINT UNSIGNED NOT NULL,
    contestant_a_id INT UNSIGNED NULL,
    contestant_b_id INT UNSIGNED NULL,
    score_a         DECIMAL(10,2) NULL,
    score_b         DECIMAL(10,2) NULL,
    winner_id       INT UNSIGNED NULL,
    is_bye          TINYINT(1) NOT NULL DEFAULT 0,
    status          ENUM('pending','done') NOT NULL DEFAULT 'pending',
    next_match_id   INT UNSIGNED NULL,
    next_slot       ENUM('a','b') NULL,
    updated_at      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_matches_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_matches_a FOREIGN KEY (contestant_a_id) REFERENCES contestants(id) ON DELETE SET NULL,
    CONSTRAINT fk_matches_b FOREIGN KEY (contestant_b_id) REFERENCES contestants(id) ON DELETE SET NULL,
    CONSTRAINT fk_matches_winner FOREIGN KEY (winner_id) REFERENCES contestants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ranking format: one result value per contestant (points, time, placement…)
CREATE TABLE IF NOT EXISTS activity_results (
    activity_id   INT UNSIGNED NOT NULL,
    contestant_id INT UNSIGNED NOT NULL,
    value         DECIMAL(12,3) NULL,
    remarks       VARCHAR(255) NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (activity_id, contestant_id),
    CONSTRAINT fk_results_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_results_contestant FOREIGN KEY (contestant_id) REFERENCES contestants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Big screen: what the event's display (TV / projector) shows right now, set from the control panel
CREATE TABLE IF NOT EXISTS display_state (
    event_id   INT UNSIGNED NOT NULL PRIMARY KEY,
    state      TEXT NOT NULL,
    version    INT UNSIGNED NOT NULL DEFAULT 1,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_display_event FOREIGN KEY (event_id) REFERENCES events(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Every change to a judge's score after it was first entered (audit trail for unlocks and corrections)
CREATE TABLE IF NOT EXISTS score_history (
    id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id   INT UNSIGNED NOT NULL,
    judge_id      INT UNSIGNED NOT NULL,
    contestant_id INT UNSIGNED NOT NULL,
    criterion_id  INT UNSIGNED NOT NULL,
    old_score     DECIMAL(7,2) NULL,
    new_score     DECIMAL(7,2) NULL,
    after_unlock  TINYINT(1) NOT NULL DEFAULT 0,
    changed_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_history_activity (activity_id, changed_at),
    CONSTRAINT fk_history_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- When a facilitator unlocked a judge's submission (changes after this are flagged in the history)
CREATE TABLE IF NOT EXISTS judge_unlocks (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    judge_id    INT UNSIGNED NOT NULL,
    activity_id INT UNSIGNED NOT NULL,
    unlocked_by VARCHAR(150) NULL,
    unlocked_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_unlocks_activity (activity_id, judge_id),
    CONSTRAINT fk_unlocks_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_unlocks_judge FOREIGN KEY (judge_id) REFERENCES access_codes(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Penalties (overtime, rule violations) taken off a contestant's final score by the facilitator
CREATE TABLE IF NOT EXISTS deductions (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id   INT UNSIGNED NOT NULL,
    contestant_id INT UNSIGNED NOT NULL,
    points        DECIMAL(7,2) NOT NULL,
    reason        VARCHAR(255) NOT NULL,
    created_by    VARCHAR(150) NULL,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    KEY idx_deductions_activity (activity_id),
    CONSTRAINT fk_deductions_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_deductions_contestant FOREIGN KEY (contestant_id) REFERENCES contestants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Special / minor awards of an activity: the best in one criterion, or a contestant picked by hand
CREATE TABLE IF NOT EXISTS awards (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id   INT UNSIGNED NOT NULL,
    name          VARCHAR(150) NOT NULL,
    criterion_id  INT UNSIGNED NULL,
    contestant_id INT UNSIGNED NULL,
    sort_order    INT NOT NULL DEFAULT 0,
    KEY idx_awards_activity (activity_id, sort_order),
    CONSTRAINT fk_awards_activity FOREIGN KEY (activity_id) REFERENCES activities(id) ON DELETE CASCADE,
    CONSTRAINT fk_awards_criterion FOREIGN KEY (criterion_id) REFERENCES criteria(id) ON DELETE CASCADE,
    CONSTRAINT fk_awards_contestant FOREIGN KEY (contestant_id) REFERENCES contestants(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- A judge's private notes on a contestant (memory aid while scoring)
CREATE TABLE IF NOT EXISTS score_notes (
    judge_id      INT UNSIGNED NOT NULL,
    contestant_id INT UNSIGNED NOT NULL,
    note          TEXT NOT NULL,
    updated_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (judge_id, contestant_id),
    CONSTRAINT fk_notes_judge FOREIGN KEY (judge_id) REFERENCES access_codes(id) ON DELETE CASCADE,
    CONSTRAINT fk_notes_contestant FOREIGN KEY (contestant_id) REFERENCES contestants(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
