<?php

namespace App\Policies;

use App\Backup\RestoreAccess;
use App\Models\Backup;
use App\Models\User;

class BackupPolicy
{
    public function __construct(protected RestoreAccess $access)
    {
        //
    }

    /**
     * Determine whether the user can browse the backup list.
     */
    public function viewAny(User $user): bool
    {
        return $user->hasPermission('backup.view');
    }

    /**
     * Determine whether the user can inspect a single backup.
     */
    public function view(User $user, Backup $backup): bool
    {
        return $user->hasPermission('backup.view');
    }

    /**
     * Determine whether the user can request a new backup.
     */
    public function create(User $user): bool
    {
        return $user->hasPermission('backup.create');
    }

    /**
     * Determine whether the user can download the backup artifact. Only a
     * completed backup has a readable file behind it.
     */
    public function download(User $user, Backup $backup): bool
    {
        return $user->hasPermission('backup.download') && $backup->isCompleted();
    }

    /**
     * Determine whether the user can delete a backup. Backups that are still
     * in flight are protected so a running job is never pulled out from under
     * itself.
     */
    public function delete(User $user, Backup $backup): bool
    {
        return $user->hasPermission('backup.delete') && ! $backup->isInFlight();
    }

    /**
     * Determine whether the user can restore the database from this backup.
     *
     * Restoring is only possible while the installation-wide switch is on, so
     * the button disappears for everyone the moment an administrator turns it
     * off. The service checks the same switch again, so hiding the button is
     * a convenience rather than the control itself.
     */
    public function restore(User $user, Backup $backup): bool
    {
        return $user->hasPermission('backup.restore')
            && $backup->isCompleted()
            && $this->access->enabled();
    }

    /**
     * Determine whether the user can turn restoring on or off.
     */
    public function manageRestoreAccess(User $user): bool
    {
        return $user->hasPermission('backup.restore');
    }
}
