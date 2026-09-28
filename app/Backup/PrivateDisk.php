<?php

namespace App\Backup;

/**
 * Decides whether a filesystem disk is safe to hold a backup in.
 *
 * A backup artifact contains every business record in the system, so it must
 * never be reachable by guessing a web address. That property cannot be proven
 * for an arbitrary remote bucket from configuration alone, so this class
 * requires two independent gates to both pass:
 *
 *  1. A structural check that cannot be switched off. A local disk may not be
 *     rooted inside the web root, may not be the target of a storage symlink
 *     and may not be marked public. This is what stops the "public" disk being
 *     used by accident, even if someone lists it as private.
 *  2. An allow list. A disk must also be declared private, because a remote
 *     container's real permissions are invisible to this application and only
 *     the operator can vouch for them.
 *
 * Refusing early is deliberate: the Backups screen states that backups are
 * unreachable from the web, and that statement is only true for a disk that
 * cleared both gates.
 */
class PrivateDisk
{
    /**
     * Refuse the given disk unless it is provably safe to hold a backup in.
     *
     * @throws BackupException
     */
    public function assertIsPrivate(string $disk): void
    {
        if ($this->isDeclaredPrivate($disk)) {
            return;
        }

        throw new BackupException(
            "Backups cannot be written to the [{$disk}] disk. A backup holds every business record in the "
            .'system, so it must stay somewhere the website cannot serve. Use the private "local" disk, which '
            .'is stored outside the web root, by setting BACKUP_DISK=local.'
        );
    }

    /**
     * Whether the disk has been declared private by the operator.
     */
    public function isDeclaredPrivate(string $disk): bool
    {
        $declared = array_map(
            static fn (string $name): string => trim($name),
            (array) config('backup.private_disks', ['local'])
        );

        if (! in_array($disk, array_filter($declared), true)) {
            return false;
        }

        return $this->isStructurallyPrivate($disk);
    }

    /**
     * Whether the disk's own configuration keeps it out of the web root.
     *
     * Remote drivers are not judged here: their ACLs cannot be inspected, so
     * the allow list above carries that decision.
     */
    protected function isStructurallyPrivate(string $disk): bool
    {
        $diskConfig = config("filesystems.disks.{$disk}");

        if (! is_array($diskConfig)) {
            return false;
        }

        if (($diskConfig['visibility'] ?? null) === 'public') {
            return false;
        }

        if (($diskConfig['driver'] ?? null) !== 'local') {
            return true;
        }

        $root = $diskConfig['root'] ?? null;

        if (! is_string($root) || $root === '') {
            return false;
        }

        return ! $this->isInsideWebRoot($root);
    }

    /**
     * Whether a local disk root sits somewhere the web server can serve.
     *
     * Both the public directory and the targets of the storage symlinks count,
     * because a symlink is exactly what makes a disk reachable over HTTP while
     * still looking like an ordinary directory.
     */
    protected function isInsideWebRoot(string $root): bool
    {
        $root = $this->normalise($root);

        $served = [$this->normalise(public_path())];

        foreach ((array) config('filesystems.links', []) as $target) {
            if (is_string($target) && $target !== '') {
                $served[] = $this->normalise($target);
            }
        }

        foreach ($served as $servedPath) {
            if ($root === $servedPath || str_starts_with($root, $servedPath.'/')) {
                return true;
            }
        }

        return false;
    }

    /**
     * Reduce a path to a comparable absolute form.
     *
     * realpath() only works on a directory that already exists, and a backup
     * disk may not have been written to yet, so the segments are resolved by
     * hand when it fails. Separators are unified because config values may be
     * written with either slash on Windows.
     */
    protected function normalise(string $path): string
    {
        $real = realpath($path);

        if ($real !== false) {
            $path = $real;
        }

        $path = preg_replace('#[\\\\/]+#', '/', $path) ?? $path;

        $resolved = [];

        foreach (explode('/', $path) as $segment) {
            if ($segment === '.') {
                continue;
            }

            if ($segment === '..' && $resolved !== [] && end($resolved) !== '..' && end($resolved) !== '') {
                array_pop($resolved);

                continue;
            }

            $resolved[] = $segment;
        }

        return implode('/', $resolved);
    }
}
