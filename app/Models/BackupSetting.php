<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The single row of installation-wide backup settings.
 */
class BackupSetting extends Model
{
    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'restore_enabled',
        'updated_by',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'restore_enabled' => 'boolean',
            'updated_by' => 'integer',
        ];
    }

    /**
     * The administrator who last changed the restore switch.
     *
     * @return BelongsTo<User, $this>
     */
    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
