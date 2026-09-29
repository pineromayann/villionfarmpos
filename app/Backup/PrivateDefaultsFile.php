<?php

namespace App\Backup;

/**
 * Writes the short lived file that carries the database credentials to the
 * mysqldump and mysql clients.
 *
 * The credentials are passed through a defaults file rather than argv or
 * MYSQL_PWD so they never appear in the process list or in the environment of
 * other users on the host.
 *
 * The file is written inside the application's own private storage and not to
 * the system temporary directory. On shared hosting /tmp is usually shared
 * between every account on the machine and world readable, so a predictable
 * path there would expose the database password to anyone who can list the
 * directory or guess the filename. The work directory is private to this
 * account, is excluded from the public document root, and is already proven
 * writable because a backup cannot run without it.
 */
class PrivateDefaultsFile
{
    public function __construct(protected FileArchiver $archiver)
    {
        //
    }

    /**
     * Write the client defaults file and return its absolute path.
     *
     * @param  array<string, mixed>  $config
     *
     * @throws BackupException
     */
    public function write(array $config, string $prefix): string
    {
        $directory = $this->archiver->workPath();
        $this->archiver->ensureDirectoryExists($directory);

        // tempnam() is used only for its atomic exclusive create, not for its
        // location: the directory has already been pinned to private storage.
        $path = @tempnam($directory, $prefix);

        if ($path === false) {
            throw new BackupException(
                'Unable to create a temporary file for the database credentials in ['.$directory.']. '
                .'Check that this directory is writable by PHP.'
            );
        }

        $contents = "[client]\n";

        // The user is always written, even when the account is passwordless,
        // so the client never silently connects as somebody else.
        if (($config['username'] ?? '') !== '') {
            $contents .= 'user='.$config['username']."\n";
        }

        if (($config['password'] ?? '') !== '') {
            $contents .= 'password='.str_replace(["\r", "\n", '"'], ['', '', '\\"'], $config['password'])."\n";
        }

        if (@file_put_contents($path, $contents) === false) {
            @unlink($path);

            throw new BackupException('Unable to write the database credentials to ['.$path.'].');
        }

        @chmod($path, 0600);

        return $path;
    }

    /**
     * Remove a defaults file, ignoring a file that is already gone.
     */
    public function remove(string $path): void
    {
        if ($path !== '') {
            @unlink($path);
        }
    }
}
