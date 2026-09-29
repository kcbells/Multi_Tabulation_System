<?php
declare(strict_types=1);

namespace App\Core;

use PDO;
use Throwable;

/**
 * Brings an existing database up to date without touching data:
 *   - adds columns listed in database/columns.php
 *   - adds indexes listed in database/indexes.php
 * Safe to run repeatedly.
 */
final class SchemaUpgrader
{
    public function __construct(private PDO $pdo, private string $database) {}

    /** Runs database/schema.sql (CREATE TABLE IF NOT EXISTS …): creates tables that are missing. */
    public function applySchemaFile(string $file): void
    {
        foreach (preg_split('/;\s*(\r?\n|$)/', (string) file_get_contents($file)) as $statement) {
            $statement = trim((string) preg_replace('/^--.*$/m', '', $statement));
            if ($statement !== '') {
                $this->pdo->exec($statement);
            }
        }
    }

    /** The whole upgrade: tables, columns, column types, backfills and indexes. @return string[] */
    public function upgrade(): array
    {
        $this->applySchemaFile(APP_ROOT . '/database/schema.sql');
        return array_merge(
            $this->ensureColumns(require APP_ROOT . '/database/columns.php'),
            $this->ensureColumnTypes(require APP_ROOT . '/database/column_changes.php'),
            $this->runBackfills(require APP_ROOT . '/database/backfills.php'),
            $this->ensureIndexes(require APP_ROOT . '/database/indexes.php')
        );
    }

    /** @return string[] */
    public function ensureColumns(array $tables): array
    {
        $st = $this->pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?');
        $st->execute([$this->database]);
        $existing = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[strtolower($row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'])] = true;
        }

        $results = [];
        foreach ($tables as $table => $columns) {
            foreach ($columns as $column => $definition) {
                if (isset($existing[strtolower("$table.$column")])) {
                    continue;
                }
                try {
                    $this->pdo->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
                    $results[] = "Added column $table.$column.";
                } catch (Throwable $e) {
                    $results[] = "Could not add column $table.$column: " . $e->getMessage();
                }
            }
        }
        return $results;
    }

    /**
     * Changes column definitions whose current type differs (e.g. widening an ENUM).
     * @param array $tables table => [column => [expectedType, fullDefinition]]
     * @return string[]
     */
    public function ensureColumnTypes(array $tables): array
    {
        $st = $this->pdo->prepare('SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ?');
        $st->execute([$this->database]);
        $types = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $types[strtolower($row['TABLE_NAME'] . '.' . $row['COLUMN_NAME'])] = strtolower(str_replace(' ', '', $row['COLUMN_TYPE']));
        }
        $results = [];
        foreach ($tables as $table => $columns) {
            foreach ($columns as $column => [$expected, $definition]) {
                $current = $types[strtolower("$table.$column")] ?? null;
                if ($current === null || $current === strtolower(str_replace(' ', '', $expected))) {
                    continue;
                }
                try {
                    $this->pdo->exec("ALTER TABLE `$table` MODIFY COLUMN `$column` $definition");
                    $results[] = "Updated column $table.$column.";
                } catch (Throwable $e) {
                    $results[] = "Could not update column $table.$column: " . $e->getMessage();
                }
            }
        }
        return $results;
    }

    /** Runs idempotent UPDATE statements; reports the ones that changed rows. */
    public function runBackfills(array $statements): array
    {
        $results = [];
        foreach ($statements as $label => $sql) {
            try {
                $count = $this->pdo->exec($sql);
                if ($count) {
                    $results[] = "$label ($count rows).";
                }
            } catch (Throwable $e) {
                $results[] = "Backfill failed ($label): " . $e->getMessage();
            }
        }
        return $results;
    }

    /** @return string[] */
    public function ensureIndexes(array $definitions): array
    {
        $st = $this->pdo->prepare('SELECT TABLE_NAME, INDEX_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ?');
        $st->execute([$this->database]);
        $existing = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $existing[strtolower($row['TABLE_NAME'] . '.' . $row['INDEX_NAME'])] = true;
        }

        $results = [];
        foreach ($definitions as $name => [$table, $columns, $unique]) {
            if (isset($existing[strtolower("$table.$name")])) {
                continue;
            }
            $cols = implode(', ', array_map(fn($c) => '`' . str_replace('`', '', $c) . '`', $columns));
            try {
                $this->pdo->exec("ALTER TABLE `$table` ADD " . ($unique ? 'UNIQUE INDEX' : 'INDEX') . " `$name` ($cols)");
                $results[] = "Added index $name.";
            } catch (Throwable $e) {
                if (!$unique) {
                    $results[] = "Could not add index $name: " . $e->getMessage();
                    continue;
                }
                // Duplicate rows block a unique index — fall back to a normal one.
                $this->pdo->exec("ALTER TABLE `$table` ADD INDEX `$name` ($cols)");
                $results[] = "Added index $name (not unique: duplicate rows exist).";
            }
        }
        return $results;
    }
}
