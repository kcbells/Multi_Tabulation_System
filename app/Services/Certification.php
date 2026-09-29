<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\HttpException;

/**
 * Certified results: a program head signs off a final activity. The placements are then
 * locked, and a fingerprint (SHA-256 of every contestant's rank and result) is stored and
 * printed, so a printed tally can always be checked against the system.
 */
final class Certification
{
    /** Fingerprint of the official placements (submitted scores only). */
    public static function hash(array $activity): string
    {
        $rows = (new PlacementService())->forActivity($activity)['rows'];
        $data = array_map(fn($r) => [$r['id'], $r['rank'], $r['display']], $rows);
        usort($data, fn($a, $b) => $a[0] <=> $b[0]);
        return hash('sha256', $activity['id'] . '|' . json_encode($data));
    }

    /** Short form printed on result sheets, e.g. "3F9A-12C0-77DE". */
    public static function short(?string $hash): ?string
    {
        return $hash ? strtoupper(implode('-', str_split(substr($hash, 0, 12), 4))) : null;
    }

    /** Stops any change to the results of a certified activity. */
    public static function ensureNotCertified(array $activity): void
    {
        if (!empty($activity['certified_at'])) {
            throw new HttpException('The results of “' . $activity['title'] . '” are certified and locked. Remove the certification first (program head or administrator).', 423);
        }
    }
}
