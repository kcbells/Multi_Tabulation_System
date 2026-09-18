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
        $this->ok(['result' => (new Tabulator())->activity((int) $activity['id'], $this->request->bool('drafts'))]);
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
        array_push($header, 'Average', '%');
        fputcsv($out, $header);
        foreach ($r['rows'] as $row) {
            $line = [$row['rank'] ?? '-', $row['number'], $row['name'], $row['team_name'] ?? ''];
            $critAvg = (array) $row['criteria_avg'];
            $totals = (array) $row['judge_totals'];
            foreach ($r['criteria'] as $c) {
                $line[] = $critAvg[$c['id']] ?? '';
            }
            foreach ($judges as $j) {
                $line[] = $totals[$j['id']] ?? '';
            }
            array_push($line, $row['average'] ?? '', $row['percentage'] ?? '');
            fputcsv($out, $line);
        }
        fclose($out);
        exit;
    }
}
