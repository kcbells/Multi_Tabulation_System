<?php
declare(strict_types=1);

namespace App\Services;

/** Ranking format: contestants ordered by a result value (higher or lower is better). */
final class RankingService
{
    /**
     * @param array $contestants rows from ContestantRepository::forActivity
     * @param array<int, array{value:?float, remarks:?string}> $results
     */
    public static function standings(array $contestants, array $results, string $direction): array
    {
        $rows = [];
        foreach ($contestants as $c) {
            $r = $results[(int) $c['id']] ?? null;
            $rows[] = [
                'id' => (int) $c['id'], 'number' => (int) $c['number'], 'name' => $c['name'], 'details' => $c['details'],
                'team_id' => $c['team_id'] !== null ? (int) $c['team_id'] : null, 'team_name' => $c['team_name'],
                'color' => $c['color'] ?? null, 'photo' => $c['photo'] ?? null,
                'value' => $r['value'] ?? null, 'remarks' => $r['remarks'] ?? null, 'rank' => null,
            ];
        }
        $asc = $direction === 'asc';
        usort($rows, function ($a, $b) use ($asc) {
            if ($a['value'] === null || $b['value'] === null) {
                return (($a['value'] === null) <=> ($b['value'] === null)) ?: ($a['number'] <=> $b['number']);
            }
            return ($asc ? $a['value'] <=> $b['value'] : $b['value'] <=> $a['value']) ?: ($a['number'] <=> $b['number']);
        });
        $prev = null;
        $rank = 0;
        foreach ($rows as $i => &$row) {
            if ($row['value'] === null) {
                continue;
            }
            if ($prev === null || abs($row['value'] - $prev) > 0.0005) {
                $rank = $i + 1;
                $prev = $row['value'];
            }
            $row['rank'] = $rank;
        }
        unset($row);
        return $rows;
    }
}
