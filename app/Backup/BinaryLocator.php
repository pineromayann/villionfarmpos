<?php

namespace App\Backup;

/**
 * Locates the MySQL command line tools.
 *
 * Shared hosting frequently does not expose mysqldump or mysql on PATH, so
 * the usual install locations are probed as well. Callers are expected to fail
 * loudly with an actionable message rather than silently degrading: a missing
 * binary during a restore is the difference between a recoverable outage and a
 * corrupt database.
 */
class BinaryLocator
{
    /**
     * Extra locations probed on top of PATH and the common install roots.
     *
     * Each entry may be either a directory to search or the full path of a
     * binary, because that is how operators are told to configure it.
     *
     * @var array<int, string>
     */
    protected array $extraPaths = [];

    /**
     * @param  array<int, string>  $extraPaths
     */
    public function withPaths(array $extraPaths): static
    {
        $this->extraPaths = array_values(array_filter($extraPaths));

        return $this;
    }

    /**
     * Resolve a binary, returning null when it cannot be found.
     */
    public function find(string $binary): ?string
    {
        foreach ($this->candidates($binary) as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Every path that could hold the binary, most specific first.
     *
     * A configured entry is only used directly when it actually names the
     * binary being looked for; otherwise it is treated as a directory to
     * search. Without that check, configuring mysqldump would satisfy a request
     * for the mysql client and the wrong program would be run.
     *
     * @return array<int, string>
     */
    protected function candidates(string $binary): array
    {
        $candidates = [];

        foreach ($this->extraPaths as $configured) {
            $name = pathinfo($configured, PATHINFO_FILENAME);

            if (strcasecmp($name, $binary) === 0) {
                $candidates[] = $configured;
            }

            foreach (['.exe', ''] as $suffix) {
                $candidates[] = rtrim($configured, '\\/').DIRECTORY_SEPARATOR.$binary.$suffix;
            }
        }

        foreach ([...$this->pathDirectories(), ...$this->wellKnownDirectories()] as $directory) {
            foreach (['.exe', ''] as $suffix) {
                $candidates[] = rtrim($directory, '\\/').DIRECTORY_SEPARATOR.$binary.$suffix;
            }
        }

        return $candidates;
    }

    /**
     * Resolve a binary or fail with an actionable message.
     */
    public function findOrFail(string $binary, string $purpose): string
    {
        $path = $this->find($binary);

        if ($path === null) {
            throw new BackupException(
                "The \"{$binary}\" binary could not be found, so {$purpose} is not possible. "
                .'Set the absolute path in your .env file, for example: '
                .'BACKUP_MYSQLDUMP_BINARY=C:\\\\Program Files\\\\MySQL\\\\MySQL Server 8.0\\\\bin\\\\mysqldump.exe',
            );
        }

        return $path;
    }

    /**
     * Every executable directory on the current PATH.
     *
     * @return array<int, string>
     */
    protected function pathDirectories(): array
    {
        $path = getenv('PATH');

        if (! is_string($path) || $path === '') {
            return [];
        }

        return array_filter(explode(PATH_SEPARATOR, $path), fn (string $entry) => $entry !== '');
    }

    /**
     * Standard install locations for MySQL, MariaDB and Percona on every
     * platform this project is likely to be deployed to.
     *
     * @return array<int, string>
     */
    protected function wellKnownDirectories(): array
    {
        $roots = [
            '/usr/bin',
            '/usr/local/bin',
            '/usr/local/mysql/bin',
            '/opt/homebrew/bin',
            '/opt/homebrew/opt/mysql-client/bin',
            '/usr/local/opt/mysql-client/bin',
            '/Applications/MAMP/Library/bin',
            'C:\\xampp\\mysql\\bin',
            'C:\\laragon\\bin\\mysql',
            'C:\\wamp64\\bin\\mysql',
        ];

        foreach (['ProgramFiles', 'ProgramFiles(x86)'] as $variable) {
            $base = getenv($variable);

            if (is_string($base) && $base !== '') {
                $roots[] = $base.'\\MySQL\\MySQL Server 8.0\\bin';
                $roots[] = $base.'\\MySQL\\MySQL Server 8.4\\bin';
                $roots[] = $base.'\\MySQL\\MySQL Server 5.7\\bin';
                $roots[] = $base.'\\MariaDB 11.4\\bin';
            }
        }

        return $roots;
    }
}
