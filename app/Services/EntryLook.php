<?php
declare(strict_types=1);

namespace App\Services;

/**
 * The visual identity of a contestant: its own colour/picture, falling back to its team's.
 * Adds `color` (hex or null) and `photo` ({r, id, v} for the media API, or null) to a row.
 */
final class EntryLook
{
    public const COLOR_PATTERN = '/^#[0-9a-fA-F]{6}$/';

    /**
     * @param array  $row     row containing the raw columns below
     * @param string $prefix  column prefix used in the query (e.g. '' or 'a_')
     *   {prefix}id, {prefix}color, {prefix}photo_file, {prefix}team_id, {prefix}team_color, {prefix}team_logo
     */
    public static function of(array $row, string $prefix = ''): array
    {
        $color = self::validColor($row[$prefix . 'color'] ?? null) ?? self::validColor($row[$prefix . 'team_color'] ?? null);
        $photo = null;
        if (!empty($row[$prefix . 'photo_file'])) {
            $photo = ['r' => 'media.contestant', 'id' => (int) $row[$prefix . 'id'], 'v' => substr(md5((string) $row[$prefix . 'photo_file']), 0, 8)];
        } elseif (!empty($row[$prefix . 'team_logo']) && !empty($row[$prefix . 'team_id'])) {
            $photo = ['r' => 'media.team', 'id' => (int) $row[$prefix . 'team_id'], 'v' => substr(md5((string) $row[$prefix . 'team_logo']), 0, 8)];
        }
        return ['color' => $color, 'photo' => $photo];
    }

    public static function validColor(?string $value): ?string
    {
        $value = trim((string) $value);
        return preg_match(self::COLOR_PATTERN, $value) ? strtoupper($value) : null;
    }
}
