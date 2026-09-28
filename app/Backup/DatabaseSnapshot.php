<?php

namespace App\Backup;

interface DatabaseSnapshot
{
    /**
     * Write a consistent, restorable dump of the whole database to the given
     * path. The implementation must produce a gzip compressed SQL file
     * containing schema (tables, columns, indexes, constraints, foreign keys)
     * and data for every business table.
     *
     * Reading must be consistent: a sale, receipt, stock adjustment, return or
     * consignment sale committed while the dump is running is captured either
     * whole or not at all, and writers must never be blocked.
     *
     * @param  string  $target  Absolute path of the .sql.gz file to create.
     * @return array{driver: string, tables: array<int, string>, rows: int}
     */
    public function dumpTo(string $target): array;
}
