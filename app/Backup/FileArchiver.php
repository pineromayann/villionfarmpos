<?php

namespace App\Backup;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use ZipArchive;

/**
 * Collects the business critical files listed in config/backup.php into a zip
 * archive. Nothing is copied into the public directory, and the backup
 * directory itself is always excluded so a full backup can never recurse into
 * previous backups.
 */
class FileArchiver
{
    /**
     * Build a zip archive at $target containing every configured path.
     *
     * @param  array<string, string>  $extraFiles  Entry name => absolute path,
     *                                             added at the archive root.
     * @return array<int, string> The project relative paths that were included.
     */
    public function archiveTo(string $target, array $extraFiles = []): array
    {
        $zip = new ZipArchive;

        if ($zip->open($target, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new BackupException("Unable to create the backup archive at [{$target}].");
        }

        foreach ($extraFiles as $entry => $absolute) {
            if ($zip->addFile($absolute, $entry) !== true) {
                $zip->close();

                throw new BackupException("Unable to add [{$entry}] to the backup archive.");
            }
        }

        $included = [];
        $root = $this->basePath();

        foreach ((array) config('backup.files', []) as $relative) {
            $relative = trim((string) $relative, '/');

            if ($relative === '') {
                continue;
            }

            $absolute = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (! file_exists($absolute)) {
                continue;
            }

            foreach ($this->collect($absolute, $relative) as $entry) {
                if ($zip->addFile($entry['absolute'], 'files/'.$entry['relative']) !== true) {
                    $zip->close();

                    throw new BackupException("Unable to add [{$entry['relative']}] to the backup archive.");
                }

                $included[] = $entry['relative'];
            }
        }

        if ($zip->close() !== true) {
            throw new BackupException("Unable to finalise the backup archive at [{$target}].");
        }

        return $included;
    }

    /**
     * Unpack a full backup's archived files into a staging directory without
     * touching the project.
     *
     * Staging first means a truncated archive, an unsafe entry name or a disk
     * error is discovered before the database is replaced, so a failed full
     * restore can never leave half of the project files overwritten.
     *
     * @return array<int, string> Staged absolute paths, keyed by the project
     *                            relative path each one belongs at.
     */
    public function stage(ZipArchive $zip, string $stagingDirectory, string $markerPrefix = 'files/'): array
    {
        $this->ensureDirectoryExists($stagingDirectory);

        $staged = [];
        $excluded = (array) config('backup.exclude', []);
        $prefix = trim((string) config('backup.directory', 'backups'), '/').'/';

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = (string) $zip->getNameIndex($index);

            if ($name === '' || ! str_starts_with($name, $markerPrefix) || str_ends_with($name, '/')) {
                continue;
            }

            $relative = $this->safeRelativePath(substr($name, strlen($markerPrefix)));

            if ($relative === null || $this->isExcluded($relative, $excluded) || str_starts_with($relative, $prefix)) {
                continue;
            }

            $destination = $stagingDirectory.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $this->ensureDirectoryExists(dirname($destination));

            $this->copyEntry($zip, $name, $destination);
            $staged[$relative] = $destination;
        }

        return $staged;
    }

    /**
     * Move files staged by stage() into the project.
     *
     * @param  array<string, string>  $staged  Relative path => staged absolute path.
     * @return array<int, string> The project relative paths that were written.
     */
    public function commit(array $staged): array
    {
        $root = $this->basePath();
        $written = [];

        foreach ($staged as $relative => $source) {
            $destination = $root.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $this->ensureDirectoryExists(dirname($destination));

            if (! @rename($source, $destination) && ! @copy($source, $destination)) {
                throw new BackupException("Unable to write [{$relative}] while restoring the backup.");
            }

            $written[] = $relative;
        }

        return $written;
    }

    /**
     * Copy one zip entry to disk.
     */
    protected function copyEntry(ZipArchive $zip, string $name, string $destination): void
    {
        $stream = $zip->getStream($name);

        if ($stream === false) {
            throw new BackupException("Unable to read [{$name}] from the backup archive.");
        }

        $target = @fopen($destination, 'wb');

        if ($target === false) {
            fclose($stream);

            throw new BackupException("Unable to write [{$destination}] while restoring the backup.");
        }

        stream_copy_to_stream($stream, $target);
        fclose($stream);
        fclose($target);
    }

    /**
     * Resolve every file under a path, relative to that path.
     *
     * @return array<int, array{absolute: string, relative: string}>
     */
    protected function collect(string $absolute, string $relative): array
    {
        $excluded = (array) config('backup.exclude', []);

        if (is_file($absolute)) {
            return $this->isExcluded($relative, $excluded) ? [] : [['absolute' => $absolute, 'relative' => $relative]];
        }

        $entries = [];
        $prefix = trim((string) config('backup.directory', 'backups'), '/').'/';
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolute, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST,
        );

        foreach ($iterator as $item) {
            /** @var SplFileInfo $item */
            if (! $item->isFile()) {
                continue;
            }

            $path = $this->relativePath($absolute, $item->getPathname());
            $key = $relative.'/'.$path;

            if (str_starts_with($key, $prefix) || $this->isExcluded($key, $excluded)) {
                continue;
            }

            $entries[] = ['absolute' => $item->getPathname(), 'relative' => $key];
        }

        return $entries;
    }

    /**
     * Match a path against a single glob.
     *
     * A double star followed by a separator spans zero or more whole directory
     * segments, a bare star never crosses a directory separator, and a
     * trailing double star matches everything below a directory. A pattern
     * with no wildcard also matches a bare name anywhere in the path.
     *
     * @param  array<int, string>  $patterns
     */
    protected function isExcluded(string $path, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if ($this->matches($path, (string) $pattern)) {
                return true;
            }
        }

        return false;
    }

    protected function matches(string $path, string $pattern): bool
    {
        $pattern = trim($pattern, '/');

        if ($pattern === '') {
            return false;
        }

        if (! str_contains($pattern, '*')) {
            return $path === $pattern
                || str_ends_with($path, '/'.$pattern)
                || basename($path) === $pattern;
        }

        $regex = '';
        $length = strlen($pattern);
        $cursor = 0;

        while ($cursor < $length) {
            if (substr($pattern, $cursor, 2) === '**') {
                $cursor += 2;

                if (substr($pattern, $cursor, 1) === '/') {
                    $cursor++;
                    $regex .= '(?:.*/)?';
                } else {
                    $regex .= '.*';
                }

                continue;
            }

            $regex .= match ($pattern[$cursor]) {
                '*' => '[^/]*',
                '?' => '[^/]',
                default => preg_quote($pattern[$cursor], '#'),
            };

            $cursor++;
        }

        return (bool) preg_match('#^'.$regex.'$#', $path);
    }

    /**
     * Turn an absolute path into one relative to a base directory.
     */
    protected function relativePath(string $base, string $absolute): string
    {
        $base = rtrim($base, '\\/').DIRECTORY_SEPARATOR;

        return str_starts_with($absolute, $base)
            ? str_replace(DIRECTORY_SEPARATOR, '/', substr($absolute, strlen($base)))
            : basename($absolute);
    }

    /**
     * Reject any archive entry that tries to escape the project directory.
     */
    protected function safeRelativePath(string $path): ?string
    {
        $path = str_replace('\\', '/', $path);

        if ($path === '' || str_starts_with($path, '/') || preg_match('#^[A-Za-z]:#', $path) === 1) {
            return null;
        }

        foreach (explode('/', $path) as $segment) {
            if ($segment === '..') {
                return null;
            }
        }

        return ltrim($path, '/');
    }

    /**
     * The project base path.
     */
    protected function basePath(): string
    {
        return base_path();
    }

    /**
     * The local directory used to stage backup artifacts before they are
     * written to the configured disk. It lives on the private disk root and is
     * therefore never web reachable.
     */
    public function workPath(): string
    {
        return rtrim(
            (string) (config('backup.work_directory') ?: storage_path('app/private/backup-work')),
            '\\/',
        );
    }

    /**
     * Create a directory, including parents, if it does not already exist.
     */
    public function ensureDirectoryExists(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new BackupException("Unable to create the directory [{$directory}].");
        }
    }
}
