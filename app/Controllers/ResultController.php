<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Controller;
use App\Core\Gate;
use App\Services\ActivityLogger as Log;
use App\Services\Tabulator;

final class ResultController extends Controller
{
    public function activity(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('id'));
        $result = (new Tabulator())->activity((int) $activity['id'], $this->request->bool('drafts'));
        $result['activity']['certificate'] = \App\Services\Certification::short($result['activity']['certified_hash']);
        unset($result['activity']['certified_hash']);
        $this->ok(['result' => $result]);
    }

    /** Placements of any format (the results book prints brackets, round robins and rankings this way). */
    public function standings(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('id'));
        $data = \App\Services\StandingsView::build($activity, false);
        $data['activity']['certified_at'] = $activity['certified_at'] ?? null;
        $data['activity']['certified_by'] = $activity['certified_by'] ?? null;
        $data['activity']['certificate'] = \App\Services\Certification::short($activity['certified_hash'] ?? null);
        $this->ok($data);
    }

    public function overall(): never
    {
        $eventId = $this->request->int('id');
        Gate::authorizeEvent($eventId);
        $this->ok(['result' => (new Tabulator())->overall($eventId, $this->request->bool('final_only'), $this->request->bool('drafts'))]);
    }

    /** CSV download of an activity's tabulation. */
    public function exportActivity(): never
    {
        $activity = Gate::authorizeActivity($this->request->int('id'));
        $r = (new Tabulator())->activity((int) $activity['id'], $this->request->bool('drafts'));
        Log::record('result.exported', 'Exported the results of ' . Log::q($activity['title']) . ' to CSV', (int) $activity['event_id'], (int) $activity['id']);
        $judges = array_values(array_filter($r['judges'], fn($j) => in_array($j['id'], $r['counted_judges'], true)));

        $filename = preg_replace('/[^A-Za-z0-9_-]+/', '_', $r['activity']['title']) . '_results.csv';
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        $out = fopen('php://output', 'w');
        fwrite($out, "\xEF\xBB\xBF"); // Excel UTF-8 BOM
        fputcsv($out, [$r['activity']['event_title'] . ' — ' . $r['activity']['title']]);
        fputcsv($out, ['Generated', $r['generated_at']]);
        fputcsv($out, []);
        $header = ['Rank', 'No.', 'Contestant', 'Team'];
        foreach ($r['criteria'] as $c) {
            $header[] = $c['name'] . ' (' . $c['max_score'] . ')';
        }
        foreach ($judges as $j) {
            $header[] = $j['name'];
        }
        $rankSum = $r['method']['scoring'] === 'rank_sum';
        $carry = $r['method']['carry_weight'] > 0;
        array_push($header, 'Deduction');
        if ($carry) {
            array_push($header, 'Previous round (' . $r['method']['carry_weight'] . '%)', 'This round');
        }
        if ($rankSum) {
            $header[] = 'Rank sum';
        }
        array_push($header, $carry ? 'Final' : 'Average', '%', 'Note');
        fputcsv($out, $header);
        foreach ($r['rows'] as $row) {
            $line = [$row['rank'] ?? '-', $row['number'], $row['name'], $row['team_name'] ?? ''];
            $critAvg = (array) $row['criteria_avg'];
            $totals = (array) $row['judge_totals'];
            foreach ($r['criteria'] as $c) {
                $line[] = $critAvg[$c['id']] ?? '';
            }
            foreach ($judges as $j) {
                $line[] = ($totals[$j['id']] ?? '') . (in_array($j['id'], $row['dropped'], true) ? ' (dropped)' : '');
            }
            $line[] = $row['deduction'] ? -$row['deduction'] : '';
            if ($carry) {
                array_push($line, $row['previous'] ?? '', $row['round_score'] ?? '');
            }
            if ($rankSum) {
                $line[] = $row['rank_sum'] ?? '';
            }
            array_push($line, $row['average'] ?? '', $row['percentage'] ?? '', $row['tie_note'] ?? '');
            fputcsv($out, $line);
        }
        if ($r['awards']) {
            fputcsv($out, []);
            fputcsv($out, ['Special awards']);
            foreach ($r['awards'] as $a) {
                fputcsv($out, [$a['name'], implode(' / ', array_column($a['winners'], 'name'))]);
            }
        }
        if (!empty($r['activity']['certified_hash'])) {
            fputcsv($out, []);
            fputcsv($out, ['Certified', $r['activity']['certified_at'], $r['activity']['certified_by'], \App\Services\Certification::short($r['activity']['certified_hash'])]);
        }
        fclose($out);
        exit;
    }
}
