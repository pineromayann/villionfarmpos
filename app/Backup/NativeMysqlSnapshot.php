<?php

namespace App\Backup;

use PDO;
use PDOException;
use Throwable;

/**
 * Produces a consistent MySQL snapshot without the mysqldump binary.
 *
 * InnoDB is read at REPEATABLE READ inside an explicit transaction, so every
 * statement in the dump observes the same point in time: a cashier checkout, a
 * purchase receipt, a stock adjustment, a return or a consignment sale that
 * commits mid-dump is either entirely present or entirely absent. Because
 * ordinary SELECTs take no locks in this mode, no writer is ever blocked and
 * the POS keeps running.
 *
 * A dedicated PDO handle is opened rather than reusing the application
 * connection so the transaction never leaks into a web request or a business
 * query, and so unbuffered reads can stream large tables to disk.
 */
class NativeMysqlSnapshot implements DatabaseSnapshot
{
    /**
     * Tables that are skipped because they carry no business value.
     *
     * @var array<int, string>
     */
    protected array $excluded = [];

    protected ?PDO $pdo = null;

    /**
     * @param  array<int, string>  $excluded
     */
    public function __construct(array $excluded = [])
    {
        $this->excluded = $excluded;
    }

    /**
     * {@inheritDoc}
     */
    public function dumpTo(string $target): array
    {
        $this->connect();

        $handle = @gzopen($target, 'wb9');

        if ($handle === false) {
            throw new BackupException("Unable to open the dump file for writing at [{$target}].");
        }

        try {
            $pdo = $this->pdo;

            $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');

            $tables = $this->tableNames();
            $rows = 0;

            $this->write($handle, $this->header($tables));

            foreach ($tables as $table) {
                $rows += $this->writeTable($handle, $table);
            }

            $this->write($handle, $this->footer());
            $pdo->exec('COMMIT');
        } catch (Throwable $e) {
            if ($this->pdo instanceof PDO && $this->pdo->inTransaction()) {
                $this->pdo->exec('ROLLBACK');
            }

            @gzclose($handle);
            @unlink($target);

            throw $e instanceof BackupException
                ? $e
                : new BackupException('The native database snapshot failed: '.$e->getMessage(), 0, $e);
        }

        gzclose($handle);

        return ['driver' => 'mysql-native', 'tables' => $tables, 'rows' => $rows];
    }

    /**
     * Open an unbuffered PDO handle using the application connection settings.
     */
    protected function connect(): void
    {
        $name = config('database.default');
        $config = config("database.connections.{$name}");

        if (! is_array($config) || ($config['driver'] ?? null) !== 'mysql') {
            throw new BackupException('The native MySQL snapshot can only be used with a mysql connection.');
        }

        $charset = $config['charset'] ?? 'utf8mb4';

        $dsn = isset($config['unix_socket']) && $config['unix_socket'] !== ''
            ? "mysql:unix_socket={$config['unix_socket']};dbname={$config['database']};charset={$charset}"
            : "mysql:host={$config['host']};port={$config['port']};dbname={$config['database']};charset={$charset}";

        try {
            $this->pdo = new PDO(
                $dsn,
                $config['username'],
                $config['password'],
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false,
                ],
            );
        } catch (PDOException $e) {
            throw new BackupException('Could not open a dedicated database connection for the backup: '.$e->getMessage(), 0, $e);
        }
    }

    /**
     * Every base table that belongs in the dump, in dependency-friendly order.
     *
     * @return array<int, string>
     */
    protected function tableNames(): array
    {
        $statement = $this->pdo->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
        $tables = [];

        while (($row = $statement->fetch()) !== false) {
            $tables[] = (string) reset($row);
        }

        $excluded = array_map('strtolower', $this->excluded);
        $tables = array_values(array_filter(
            $tables,
            fn (string $table) => ! in_array(strtolower($table), $excluded, true),
        ));

        sort($tables);

        return $tables;
    }

    /**
     * Write the CREATE TABLE statement and every row of a single table.
     *
     * @param  resource  $handle
     */
    protected function writeTable($handle, string $table): int
    {
        $quoted = $this->quote($table);
        $create = $this->pdo->query("SHOW CREATE TABLE {$quoted}")->fetch();
        $ddl = (string) $create['Create Table'];

        $this->write($handle, "\n--\n-- Table structure for {$table}\n--\n\n");
        $this->write($handle, "DROP TABLE IF EXISTS {$quoted};\n");
        $this->write($handle, $ddl.";\n\n");

        $statement = $this->pdo->query("SELECT * FROM {$quoted}");
        $count = 0;
        $buffer = '';

        while (($row = $statement->fetch()) !== false) {
            $buffer .= $this->insertStatement($table, $row);

            if (strlen($buffer) > 512 * 1024) {
                $this->write($handle, $buffer);
                $buffer = '';
            }

            $count++;
        }

        if ($buffer !== '') {
            $this->write($handle, $buffer);
        }

        $this->write($handle, "\n");

        return $count;
    }

    /**
     * Build a single multi-row INSERT statement.
     *
     * @param  array<string, mixed>  $row
     */
    protected function insertStatement(string $table, array $row): string
    {
        $columns = array_map(
            fn (string $column) => $this->quote($column),
            array_keys($row),
        );

        $values = array_map(fn ($value) => $this->literal($value), array_values($row));

        return 'INSERT INTO '.$this->quote($table).' ('.implode(', ', $columns).') VALUES ('.implode(', ', $values).');'."\n";
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

        if ($value instanceof \DateTimeInterface) {
            return $this->pdo->quote($value->format('Y-m-d H:i:s'));
        }

        if (is_string($value) && preg_match('/^(?:[0-9]{4}-[0-9]{2}-[0-9]{2}|[0-9]{4}-[0-9]{2}-[0-9]{2} [0-9]{2}:[0-9]{2}:[0-9]{2})$/', $value)) {
            return "'".$value."'";
        }

        return $this->pdo->quote((string) $value);
    }

    /**
     * Backtick quote an identifier.
     */
    protected function quote(string $identifier): string
    {
        return '`'.str_replace('`', '``', $identifier).'`';
    }

    /**
     * The preamble written before any table.
     *
     * @param  array<int, string>  $tables
     */
    protected function header(array $tables): string
    {
        $database = config('database.connections.'.config('database.default').'.database');

        return implode("\n", [
            '-- VillonFarm POS backup',
            '-- Generated: '.now()->toDateTimeString(),
            '-- Application: '.config('app.name'),
            '-- Database: '.$database,
            '-- Driver: mysql-native (consistent snapshot, REPEATABLE READ)',
            '-- Tables: '.count($tables),
            '',
            'SET NAMES utf8mb4;',
            'SET FOREIGN_KEY_CHECKS = 0;',
            'SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";',
            'SET @OLD_SQL_MODE = @@SQL_MODE;',
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
            'SET SQL_MODE = @OLD_SQL_MODE;',
            'SET FOREIGN_KEY_CHECKS = 1;',
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
