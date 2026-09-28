<?php

namespace App\Models;

use Database\Factories\ActivityLogFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ActivityLog extends Model
{
    /** @use HasFactory<ActivityLogFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    public const ACTION_BACKUP_CREATED = 'backup.created';

    public const ACTION_BACKUP_COMPLETED = 'backup.completed';

    public const ACTION_BACKUP_FAILED = 'backup.failed';

    public const ACTION_BACKUP_DOWNLOADED = 'backup.downloaded';

    public const ACTION_BACKUP_DELETED = 'backup.deleted';

    public const ACTION_RESTORE_STARTED = 'restore.started';

    public const ACTION_RESTORE_COMPLETED = 'restore.completed';

    public const ACTION_RESTORE_FAILED = 'restore.failed';

    public const ACTION_RESTORE_ACCESS_CHANGED = 'restore.access_changed';

    public const RESULT_SUCCESS = 'success';

    public const RESULT_FAILURE = 'failure';

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'user_id',
        'action',
        'subject_type',
        'subject_id',
        'result',
        'ip_address',
        'user_agent',
        'context',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'context' => 'array',
            'created_at' => 'datetime',
        ];
    }

    /**
     * The user responsible for the recorded action, if still known.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Limit the query to entries for a given subject record.
     *
     * @param  Builder<ActivityLog>  $query
     * @return Builder<ActivityLog>
     */
    public function scopeForSubject(Builder $query, Model $subject): Builder
    {
        return $query
            ->where('subject_type', $subject::class)
            ->where('subject_id', $subject->getKey());
    }
}
