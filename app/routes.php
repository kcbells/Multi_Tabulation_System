<?php
/**
 * API route table: "resource.action" => [Controller, method, HTTP method, allowed roles]
 * An empty roles array means the route is public (sign-in).
 */
use App\Controllers\AccessCodeController;
use App\Controllers\ActivityController;
use App\Controllers\AuthController;
use App\Controllers\CompetitionController;
use App\Controllers\ContestantController;
use App\Controllers\CriteriaController;
use App\Controllers\EventController;
use App\Controllers\LogController;
use App\Controllers\MediaController;
use App\Controllers\ResultController;
use App\Controllers\ScoreController;
use App\Controllers\SyncController;
use App\Controllers\TeamController;
use App\Controllers\UserController;
use App\Core\Auth;

$staff    = Auth::STAFF;                        // admin, program_head
$managers = Auth::MANAGERS;                     // + facilitator
$everyone = ['admin', 'program_head', 'facilitator', 'judge'];

return [
    'auth.me'                => [AuthController::class, 'me', 'GET', []],
    'auth.staff_login'       => [AuthController::class, 'staffLogin', 'POST', []],
    'auth.code_login'        => [AuthController::class, 'codeLogin', 'POST', []],
    'auth.logout'            => [AuthController::class, 'logout', 'POST', []],
    'auth.change_password'   => [AuthController::class, 'changePassword', 'POST', $staff],

    'users.list'             => [UserController::class, 'index', 'GET', ['admin']],
    'users.save'             => [UserController::class, 'save', 'POST', ['admin']],
    'users.delete'           => [UserController::class, 'delete', 'POST', ['admin']],

    'events.list'            => [EventController::class, 'index', 'GET', $staff],
    'events.get'             => [EventController::class, 'show', 'GET', $managers],
    'events.save'            => [EventController::class, 'save', 'POST', $staff],
    'events.delete'          => [EventController::class, 'delete', 'POST', $staff],
    'events.delete_preview'  => [EventController::class, 'deletePreview', 'GET', $staff],
    'events.archive'         => [EventController::class, 'archive', 'POST', $staff],
    'events.restore'         => [EventController::class, 'restore', 'POST', $staff],
    'events.status'          => [EventController::class, 'setStatus', 'POST', $managers],
    'events.scan'            => [EventController::class, 'scan', 'POST', $staff],
    'events.document'        => [EventController::class, 'document', 'GET', $managers],

    'teams.save'             => [TeamController::class, 'save', 'POST', $staff],
    'teams.delete'           => [TeamController::class, 'delete', 'POST', $staff],

    'activities.get'         => [ActivityController::class, 'show', 'GET', $managers],
    'activities.save'        => [ActivityController::class, 'save', 'POST', $staff],
    'activities.delete'      => [ActivityController::class, 'delete', 'POST', $staff],
    'activities.status'      => [ActivityController::class, 'setStatus', 'POST', $managers],
    'activities.judges'      => [ActivityController::class, 'assignJudges', 'POST', $staff],
    'activities.reset'       => [ActivityController::class, 'resetScores', 'POST', $staff],

    'competition.get'        => [CompetitionController::class, 'show', 'GET', $managers],
    'competition.generate'   => [CompetitionController::class, 'generate', 'POST', $managers],
    'competition.match'      => [CompetitionController::class, 'recordMatch', 'POST', $managers],
    'competition.clear'      => [CompetitionController::class, 'clearMatch', 'POST', $managers],
    'competition.results'    => [CompetitionController::class, 'saveResults', 'POST', $managers],
    'competition.swap'       => [CompetitionController::class, 'swap', 'POST', $managers],

    'contestants.save'       => [ContestantController::class, 'save', 'POST', $managers],
    'contestants.delete'     => [ContestantController::class, 'delete', 'POST', $managers],
    'contestants.add_teams'  => [ContestantController::class, 'addTeams', 'POST', $managers],

    'criteria.scan'          => [CriteriaController::class, 'scan', 'POST', $staff],
    'criteria.reparse'       => [CriteriaController::class, 'reparse', 'POST', $staff],
    'criteria.save'          => [CriteriaController::class, 'save', 'POST', $staff],
    'criteria.file'          => [CriteriaController::class, 'file', 'GET', $everyone],
    'criteria.engines'       => [CriteriaController::class, 'engines', 'GET', $staff],

    'codes.save'             => [AccessCodeController::class, 'save', 'POST', $staff],
    'codes.bulk'             => [AccessCodeController::class, 'bulk', 'POST', $staff],
    'codes.regenerate'       => [AccessCodeController::class, 'regenerate', 'POST', $staff],
    'codes.toggle'           => [AccessCodeController::class, 'toggle', 'POST', $staff],
    'codes.delete'           => [AccessCodeController::class, 'delete', 'POST', $staff],

    'scores.home'            => [ScoreController::class, 'home', 'GET', ['judge']],
    'scores.sheet'           => [ScoreController::class, 'sheet', 'GET', ['judge']],
    'scores.save'            => [ScoreController::class, 'save', 'POST', ['judge']],
    'scores.submit'          => [ScoreController::class, 'submit', 'POST', ['judge']],
    'scores.submit_contestant' => [ScoreController::class, 'submitContestant', 'POST', ['judge']],
    'scores.unlock'          => [ScoreController::class, 'unlock', 'POST', $managers],
    'scores.judge_sheet'     => [ScoreController::class, 'judgeSheet', 'GET', $managers],

    'results.activity'       => [ResultController::class, 'activity', 'GET', $managers],
    'results.overall'        => [ResultController::class, 'overall', 'GET', $managers],
    'results.export'         => [ResultController::class, 'exportActivity', 'GET', $managers],

    'logs.list'              => [LogController::class, 'index', 'GET', $managers],
    'sync.version'           => [SyncController::class, 'version', 'GET', $everyone],

    'media.contestant'       => [MediaController::class, 'contestant', 'GET', $everyone],
    'media.team'             => [MediaController::class, 'team', 'GET', $everyone],
    'contestants.photo'      => [MediaController::class, 'uploadContestantPhoto', 'POST', $managers],
    'contestants.photo_remove' => [MediaController::class, 'removeContestantPhoto', 'POST', $managers],
    'teams.logo'             => [MediaController::class, 'uploadTeamLogo', 'POST', $staff],
    'teams.logo_remove'      => [MediaController::class, 'removeTeamLogo', 'POST', $staff],
];
