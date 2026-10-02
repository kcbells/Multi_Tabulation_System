<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

/**
 * Upgrades the database by itself after new code is deployed, so nobody has to
 * remember to open install/setup.php. Bump VERSION whenever database/schema.sql,
 * columns.php, column_changes.php, backfills.php or indexes.php change.
 *
 * The version that was applied is kept in storage/tmp/schema.<database>.version:
 * a normal request only reads that small file.
 */
final class SchemaGuard
{
    public const VERSION = '2026-10-02.1';

    private static function file(?string $database = null): string
    {
        $database ??= (string) config('db.name');
        return APP_ROOT . '/storage/tmp/schema.' . preg_replace('/[^A-Za-z0-9_-]+/', '_', $database) . '.version';
    }

    public static function check(PDO $pdo, string $database): void
    {
        if (@file_get_contents(self::file($database)) === self::VERSION) {
            return;
        }
        // not installed yet (no tables): install/setup.php creates everything and the first admin
        if (!$pdo->query("SHOW TABLES LIKE 'users'")->fetchColumn()) {
            return;
        }
        try {
            $results = (new SchemaUpgrader($pdo, $database))->upgrade();
            foreach ($results as $line) {
                if (str_starts_with($line, 'Could not') || str_starts_with($line, 'Backfill failed')) {
                    error_log('[tabulation] schema upgrade: ' . $line);
                }
            }
            self::markCurrent($database);
        } catch (Throwable $e) {
            error_log('[tabulation] schema upgrade failed: ' . $e->getMessage());
        }
    }

    public static function markCurrent(?string $database = null): void
    {
        @file_put_contents(self::file($database), self::VERSION, LOCK_EX);
    }
}
