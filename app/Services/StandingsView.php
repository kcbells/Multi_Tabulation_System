<?php
declare(strict_types=1);

namespace App\Services;

use App\Controllers\CompetitionController;
use App\Repositories\ContestantRepository;
use App\Repositories\MatchRepository;

/**
 * Standings of one activity as the audience sees them — on the public results page and the big screen.
 * Only ranks, the entries (name, team, colour, picture) and a short result line are included:
 * never a judge's scores, criterion averages or who judged.
 *
 *   public page   score-based activities stay hidden until they are final (closed);
 *                 brackets, round robins and rankings update live like a scoreboard
 *   big screen    the operator decides what is shown, so nothing is hidden here
 */
final class StandingsView
{
    public static function isPublished(array $activity): bool
    {
        return ($activity['format'] ?? 'score') !== 'score' || $activity['status'] === 'closed';
    }

    /** @param array $activity a full activities row */
    public static function build(array $activity, bool $forPublic): array
    {
        $aid = (int) $activity['id'];
        $format = $activity['format'] ?? 'score';
        $visible = !$forPublic || self::isPublished($activity);

        $data = [
            'activity' => [
                'id' => $aid, 'event_id' => (int) $activity['event_id'], 'title' => $activity['title'],
                'format' => $format, 'status' => $activity['status'], 'nature' => $activity['nature'] ?? null,
                'venue' => $activity['venue'] ?? null, 'schedule_at' => $activity['schedule_at'] ?? null,
                'score_label' => $activity['score_label'] ?? null, 'rank_direction' => $activity['rank_direction'] ?? 'desc',
                'third_place' => (bool) ($activity['third_place'] ?? true),
                'certified' => !empty($activity['certified_at']),
            ],
            'visible' => $visible,
            'complete' => false,
            'awards' => [],
            'rows' => [],
            'lineup' => [],
            'matches' => [],
            'table' => [],
        ];

        if (!$visible) {
            // the line-up only: who competes, without any score or order of finish
            $data['lineup'] = array_map([self::class, 'entry'], (new ContestantRepository())->forActivity($aid));
            return $data;
        }

        $placements = (new PlacementService())->forActivity($activity);
        $data['complete'] = (bool) $placements['complete'];
        $data['rows'] = array_map(fn($r) => self::entry($r) + ['rank' => $r['rank'], 'display' => $r['display']], $placements['rows']);
        if ($format === 'score') {
            // special awards: only who won — criterion averages stay private
            $data['awards'] = array_values(array_filter(array_map(fn($a) => [
                'name' => $a['name'],
                'winners' => array_map(fn($w) => ['id' => $w['id'], 'number' => $w['number'], 'name' => $w['name'], 'team' => $w['team_name'], 'color' => $w['color'], 'photo' => $w['photo']], $a['winners']),
            ], (new Tabulator())->activity($aid)['awards']), fn($a) => $a['winners']));
        }

        if ($format === 'bracket' || $format === 'round_robin') {
            $matches = (new MatchRepository())->forActivity($aid);
            $data['matches'] = array_map([CompetitionController::class, 'matchRow'], $matches);
            if ($format === 'round_robin') {
                $contestants = (new ContestantRepository())->forActivity($aid);
                $data['table'] = array_map(fn($r) => self::entry($r) + [
                    'rank' => $r['rank'], 'played' => $r['played'], 'won' => $r['won'], 'drawn' => $r['drawn'],
                    'lost' => $r['lost'], 'diff' => $r['diff'], 'points' => $r['points'],
                ], RoundRobinService::standings($contestants, $matches));
            }
        }
        return $data;
    }

    /** The audience-facing look of one contestant row. */
    public static function entry(array $r): array
    {
        return [
            'id' => (int) $r['id'],
            'number' => isset($r['number']) ? (int) $r['number'] : null,
            'name' => $r['name'],
            'team' => $r['team_name'] ?? null,
            'details' => $r['details'] ?? null,
            'members' => $r['members'] ?? null,
            'color' => $r['color'] ?? null,
            'photo' => $r['photo'] ?? null,
            'background' => $r['background'] ?? null,
        ];
    }

    /** Overall team standings for the audience (score-based activities count once final). */
    public static function overall(int $eventId): array
    {
        $r = (new Tabulator())->overall($eventId, false, false, true);
        return [
            'has_overall' => $r['has_overall'],
            'placement_points' => $r['placement_points'],
            'participation_points' => $r['participation_points'],
            'standings' => array_map(fn($t) => [
                'team_id' => $t['team_id'], 'name' => $t['name'], 'color' => $t['color'], 'photo' => $t['photo'],
                'total' => $t['total'], 'medals' => $t['medals'], 'rank' => $t['rank'], 'activities' => $t['activities'],
            ], $r['standings']),
            'activities' => array_map(fn($a) => [
                'id' => $a['id'], 'title' => $a['title'], 'status' => $a['status'], 'format' => $a['format'],
                'included' => $a['included'], 'scored' => $a['scored'], 'hidden' => $a['hidden'] ?? false,
                'winners' => array_map(fn($w) => ['rank' => $w['rank'], 'name' => $w['name'], 'team' => $w['team'], 'color' => $w['color'], 'photo' => $w['photo']], $a['winners']),
            ], $r['activities']),
        ];
    }
}
