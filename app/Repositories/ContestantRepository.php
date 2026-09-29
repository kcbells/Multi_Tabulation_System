<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Services\EntryLook;

final class ContestantRepository extends Repository
{
    /** Contestants with team name and their look (colour + picture, team fallback). */
    public function forActivity(int $activityId): array
    {
        $rows = $this->db->all(
            'SELECT c.id, c.number, c.name, c.details, c.members, c.team_id, c.color, c.photo_file, c.stage_bg, c.source_contestant_id,
                    t.name AS team_name, t.color AS team_color, t.logo_file AS team_logo
             FROM contestants c LEFT JOIN teams t ON t.id = c.team_id
             WHERE c.activity_id = ? ORDER BY c.number, c.name',
            [$activityId]
        );
        foreach ($rows as &$row) {
            $look = EntryLook::of($row);
            $row['own_color'] = $row['color'];
            $row['has_photo'] = !empty($row['photo_file']);
            $row['color'] = $look['color'];
            $row['photo'] = $look['photo'];
            $row['background'] = self::background($row);
            unset($row['photo_file'], $row['team_logo'], $row['stage_bg']);
        }
        return $rows;
    }

    public function find(int $id): ?array
    {
        return $this->db->one(
            'SELECT c.*, a.event_id FROM contestants c JOIN activities a ON a.id = c.activity_id WHERE c.id = ?',
            [$id]
        );
    }

    public function exists(int $activityId): bool
    {
        return (bool) $this->db->value('SELECT 1 FROM contestants WHERE activity_id = ?', [$activityId]);
    }

    public function idsForActivity(int $activityId): array
    {
        return array_map('intval', array_column(
            $this->db->all('SELECT id FROM contestants WHERE activity_id = ?', [$activityId]),
            'id'
        ));
    }

    public function nextNumber(int $activityId): int
    {
        return (int) $this->db->value('SELECT COALESCE(MAX(number), 0) + 1 FROM contestants WHERE activity_id = ?', [$activityId]);
    }

    public function create(array $d): int
    {
        return $this->db->insert(
            'INSERT INTO contestants (activity_id, number, name, details, members, team_id, color) VALUES (?, ?, ?, ?, ?, ?, ?)',
            [$d['activity_id'], $d['number'], $d['name'], $d['details'], $d['members'] ?? null, $d['team_id'], $d['color'] ?? null]
        );
    }

    public function update(int $id, int $activityId, array $d): void
    {
        $this->db->execute(
            'UPDATE contestants SET number = ?, name = ?, details = ?, members = ?, team_id = ?, color = ? WHERE id = ? AND activity_id = ?',
            [$d['number'], $d['name'], $d['details'], $d['members'] ?? null, $d['team_id'], $d['color'] ?? null, $id, $activityId]
        );
    }

    /** A finalist copied from an earlier round (keeps its number, team, colour and pictures). */
    public function copyTo(int $activityId, array $source, int $number): int
    {
        return $this->db->insert(
            'INSERT INTO contestants (activity_id, number, name, details, members, team_id, color, photo_file, stage_bg, source_contestant_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
            [$activityId, $number, $source['name'], $source['details'], $source['members'] ?? null, $source['team_id'], $source['color'], $source['photo_file'], $source['stage_bg'], $source['id']]
        );
    }

    /** @return int[] ids of the earlier-round contestants already copied into this activity */
    public function sourceIds(int $activityId): array
    {
        return array_map('intval', array_column($this->db->all(
            'SELECT source_contestant_id FROM contestants WHERE activity_id = ? AND source_contestant_id IS NOT NULL', [$activityId]
        ), 'source_contestant_id'));
    }

    /** Pictures are shared between rounds: a file is only removed when no contestant uses it any more. */
    public function fileInUse(string $key): bool
    {
        return (bool) $this->db->value('SELECT 1 FROM contestants WHERE photo_file = ? OR stage_bg = ? LIMIT 1', [$key, $key]);
    }

    public function setPhoto(int $id, ?string $key): void
    {
        $this->db->execute('UPDATE contestants SET photo_file = ? WHERE id = ?', [$key, $id]);
    }

    public function delete(int $id, int $activityId): void
    {
        $this->db->execute('DELETE FROM contestants WHERE id = ? AND activity_id = ?', [$id, $activityId]);
    }

    /** Stored picture keys (for file-server cleanup when deleting). */
    /** Stage background of a contestant for the media API ({r, id, v}), or null. */
    public static function background(array $row): ?array
    {
        return empty($row['stage_bg']) ? null : ['r' => 'media.stage', 'id' => (int) $row['id'], 'v' => substr(md5((string) $row['stage_bg']), 0, 8)];
    }

    public function setStageBackground(int $id, ?string $key): void
    {
        $this->db->execute('UPDATE contestants SET stage_bg = ? WHERE id = ?', [$key, $id]);
    }

    /** Pictures and stage backgrounds on the file server (removed with the activity). */
    public function photoKeysForActivity(int $activityId): array
    {
        return array_merge(
            array_column($this->db->all('SELECT photo_file FROM contestants WHERE activity_id = ? AND photo_file IS NOT NULL', [$activityId]), 'photo_file'),
            array_column($this->db->all('SELECT stage_bg FROM contestants WHERE activity_id = ? AND stage_bg IS NOT NULL', [$activityId]), 'stage_bg')
        );
    }

    public function photoKeysForEvent(int $eventId): array
    {
        $rows = $this->db->all(
            'SELECT c.photo_file, c.stage_bg FROM contestants c JOIN activities a ON a.id = c.activity_id WHERE a.event_id = ? AND (c.photo_file IS NOT NULL OR c.stage_bg IS NOT NULL)',
            [$eventId]
        );
        return array_values(array_filter(array_merge(array_column($rows, 'photo_file'), array_column($rows, 'stage_bg'))));
    }
}
