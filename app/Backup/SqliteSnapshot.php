<?php

namespace App\Backup;

use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Produces a logical SQL dump for SQLite connections.
 *
 * The test suite runs against an in-memory SQLite database, where VACUUM INTO
 * is not usable because the test transaction cannot be committed. Emitting
 * schema and INSERT statements keeps the dump portable, human readable and
 * genuinely restorable through the same code path the MySQL dump uses.
 */
class SqliteSnapshot implements DatabaseSnapshot
{
    /**
     * {@inheritDoc}
     */
    public function dumpTo(string $target): array
    {
        $tables = $this->includedTables();
        $rows = 0;

        $handle = @gzopen($target, 'wb9');

        if ($handle === false) {
            throw new BackupException("Unable to open the dump file for writing at [{$target}].");
        }

        try {
            $this->write($handle, $this->header($tables));

            foreach ($tables as $table) {
                $rows += $this->writeTable($handle, $table);
            }

            $this->write($handle, $this->footer());
        } catch (Throwable $e) {
            gzclose($handle);
            @unlink($target);

            throw $e instanceof BackupException
                ? $e
                : new BackupException('The SQLite snapshot failed: '.$e->getMessage(), 0, $e);
        }

        gzclose($handle);

        return ['driver' => 'sqlite', 'tables' => $tables, 'rows' => $rows];
    }

    /**
     * Write the CREATE TABLE statement and every row of a single table.
     *
     * @param  resource  $handle
     */
    protected function writeTable($handle, string $table): int
    {
        $quoted = $this->quote($table);
        $create = DB::selectOne(
            'SELECT sql FROM sqlite_master WHERE type = ? AND name = ?',
            ['table', $table],
        );

        $this->write($handle, "\n--\n-- Table structure for {$table}\n--\n\n");
        $this->write($handle, "DROP TABLE IF EXISTS {$quoted};\n");

        if ($create !== null && is_string($create->sql)) {
            $this->write($handle, rtrim(rtrim($create->sql), ';').";\n\n");
        }

        $count = 0;
        $buffer = '';

        foreach (DB::select("SELECT * FROM {$quoted}") as $row) {
            $record = (array) $row;
            $columns = array_map(fn (string $column) => $this->quote($column), array_keys($record));
            $values = array_map(fn ($value) => $this->literal($value), array_values($record));

            $buffer .= 'INSERT INTO '.$quoted.' ('.implode(', ', $columns).') VALUES ('.implode(', ', $values).');'."\n";
            $count++;

            if (strlen($buffer) > 512 * 1024) {
                $this->write($handle, $buffer);
                $buffer = '';
            }
        }

        if ($buffer !== '') {
            $this->write($handle, $buffer);
        }

        $this->write($handle, "\n");

        return $count;
    }

    /**
     * The tables included in the dump, recorded on the backup row.
     *
     * Laravel returns schema qualified names for SQLite ("main.products"), so
     * the schema is stripped here and SQLite's own bookkeeping tables are
     * dropped; neither belongs in a business backup.
     *
     * @return array<int, string>
     */
    protected function includedTables(): array
    {
        $excluded = array_map('strtolower', (array) config('backup.exclude_tables', []));

        return array_values(array_filter(
            array_map($this->unqualify(...), DB::getSchemaBuilder()->getTableListing()),
            function (string $table) use ($excluded): bool {
                $name = strtolower($table);

                return ! str_starts_with($name, 'sqlite_') && ! in_array($name, $excluded, true);
            },
        ));
    }

    /**
     * Strip a schema prefix from a table name.
     */
    protected function unqualify(string $table): string
    {
        return str_contains($table, '.') ? substr($table, strrpos($table, '.') + 1) : $table;
    }

    /**
     * Escape a value for inclusion in a SQL literal.
     */
    protected function literal(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        return "'".str_replace("'", "''", (string) $value)."'";
    }

    /**
     * Double quote an identifier.
     *
     * SQLite treats a double quoted name that does not resolve as an identifier
     * as a string literal, so each part of a qualified name has to be quoted
     * separately.
     */
    protected function quote(string $identifier): string
    {
        return implode('.', array_map(
            fn (string $part) => '"'.str_replace('"', '""', $part).'"',
            explode('.', $this->unqualify($identifier)),
        ));
    }

    /**
     * The preamble written before any table.
     *
     * @param  array<int, string>  $tables
     */
    protected function header(array $tables): string
    {
        return implode("\n", [
            '-- VillonFarm POS backup',
            '-- Generated: '.now()->toDateTimeString(),
            '-- Application: '.config('app.name'),
            '-- Driver: sqlite',
            '-- Tables: '.count($tables),
            '',
            'PRAGMA foreign_keys = OFF;',
            'BEGIN TRANSACTION;',
            '',
        ]);
    }

    /**
     * The epilogue written after the last table.
     */
    protected function footer(): string
    {
        return implode("\n", [
            '',
            'COMMIT;',
            'PRAGMA foreign_keys = ON;',
            '',
            '-- End of backup',
            '',
        ]);
    }

    /**
     * Append a chunk to the gzip stream.
     *
     * @param  resource  $handle
     */
    protected function write($handle, string $chunk): void
    {
        if ($chunk === '') {
            return;
        }

        if (@gzwrite($handle, $chunk) === false) {
            throw new BackupException('Failed while writing the database dump to disk. Check free space on the backup disk.');
        }
    }
}
