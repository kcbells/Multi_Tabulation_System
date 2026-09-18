<?php
declare(strict_types=1);

namespace App\Services;

use App\Documents\CriteriaParser;
use App\Repositories\ActivityRepository;

/** Criteria sheet upload: store on the file server, read the text, detect criteria. */
final class CriteriaScanService
{
    private DocumentIntake $intake;
    private ActivityRepository $activities;

    public function __construct(?DocumentIntake $intake = null)
    {
        $this->intake = $intake ?? new DocumentIntake();
        $this->activities = new ActivityRepository();
    }

    /** @return array{engine:string,text:string,criteria:array,total:float,warnings:string[],file_name:string} */
    public function scan(array $activity, UploadedFile $upload): array
    {
        $folder = sprintf('criteria/event-%d/activity-%d', $activity['event_id'], $activity['id']);
        $result = $this->intake->intake($upload, $folder, fn(string $text) => CriteriaParser::parse($text));

        $this->intake->deleteQuietly($activity['criteria_file'] ?? null);
        $this->activities->setCriteriaFile((int) $activity['id'], $result['key'], $upload->originalName, $result['text']);

        $parsed = $result['parsed'];
        $warnings = $result['warnings'];
        if ($result['text'] === '') {
            $warnings[] = 'No readable text was found in the document.';
        } elseif (!$parsed['criteria']) {
            $warnings[] = 'Text was read, but no criteria with scores or percentages were recognized. Correct the text and read it again, or add the criteria manually.';
        }

        return [
            'engine' => $result['engine'],
            'text' => $result['text'],
            'criteria' => $parsed['criteria'],
            'total' => $parsed['total'],
            'warnings' => $warnings,
            'file_name' => $upload->originalName,
        ];
    }

    public function deleteQuietly(?string $key): void
    {
        $this->intake->deleteQuietly($key);
    }
}
