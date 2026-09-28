<?php

namespace App\Backup;

use App\Models\BackupSetting;
use App\Models\User;

/**
 * Answers "is restoring switched on?" from a single database row.
 *
 * This deliberately lives in the database rather than in config. A config value
 * can only be changed from a terminal, and on a host that caches config the
 * change does not even take effect until the cache is cleared, which is exactly
 * the kind of task a shop owner cannot do. Keeping it here lets an
 * administrator turn restoring on from the Backups screen and nothing else.
 *
 * The value is memoised for the lifetime of the request. The service is bound
 * as a singleton, so a fresh instance is built per request and per test, and the
 * memo can never leak a stale answer across them.
 */
class RestoreAccess
{
    /**
     * The resolved answer, or null when it has not been read yet.
     */
    protected ?bool $enabled = null;

    /**
     * Whether restoring is currently switched on.
     */
    public function enabled(): bool
    {
        if ($this->enabled !== null) {
            return $this->enabled;
        }

        return $this->enabled = (bool) (BackupSetting::query()->first()?->restore_enabled ?? false);
    }

    /**
     * Turn restoring on or off, remembering who did it.
     */
    public function set(bool $enabled, ?User $user = null): BackupSetting
    {
        $setting = BackupSetting::query()->first() ?? new BackupSetting;

        $setting->forceFill([
            'restore_enabled' => $enabled,
            'updated_by' => $user?->getKey(),
        ])->save();

        $this->enabled = $enabled;

        return $setting;
    }
}
