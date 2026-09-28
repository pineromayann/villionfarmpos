<?php

namespace App\Models;

use Database\Factories\BackupFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Backup extends Model
{
    /** @use HasFactory<BackupFactory> */
    use HasFactory;

    public const TYPE_DATABASE = 'database';

    public const TYPE_FULL = 'full';

    public const STATUS_PENDING = 'pending';

    public const STATUS_RUNNING = 'running';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_FAILED = 'failed';

    public const FREQUENCIES = ['daily', 'weekly', 'monthly'];

    /**
     * @var array<int, string>
     */
    protected $fillable = [
        'filename',
        'disk',
        'path',
        'type',
        'size',
        'checksum',
        'status',
        'frequency',
        'database_name',
        'included_paths',
        'snapshot',
        'created_by',
        'started_at',
        'completed_at',
        'error_message',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'included_paths' => 'array',
            'snapshot' => 'array',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    /**
     * The user who requested the backup, if any.
     *
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Determine if the backup finished successfully and is safe to download.
     */
    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * Determine if the backup is still in flight and must not be removed.
     */
    public function isInFlight(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_RUNNING], true);
    }

    /**
     * Determine if the recorded artifact still exists on its disk.
     */
    public function fileExists(): bool
    {
        return $this->isCompleted()
            && $this->hasSafePath()
            && Storage::disk($this->disk)->exists($this->path);
    }

    /**
     * Determine if the recorded path is one the application itself could have
     * generated.
     *
     * Paths are built from a configured directory plus a generated filename, so
     * anything relative, absolute or containing a parent segment can only come
     * from a tampered row. Refusing those outright keeps a download or a
     * restore from reaching outside the backup directory.
     */
    public function hasSafePath(): bool
    {
        $path = (string) $this->path;

        if ($path === '' || str_starts_with($path, '/') || preg_match('#^[A-Za-z]:#', $path) === 1) {
            return false;
        }

        foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return false;
            }
        }

        return str_starts_with($path, trim((string) config('backup.directory', 'backups'), '/').'/');
    }

    /**
     * A human readable label for the backup type.
     */
    public function typeLabel(): string
    {
        return $this->type === self::TYPE_FULL ? 'Full' : 'Database';
    }

    /**
     * The human readable file size, or an em dash when it is not yet known.
     */
    public function humanSize(): string
    {
        return $this->size === null ? '—' : $this->formatBytes($this->size);
    }

    /**
     * The duration of the run in seconds, or null when it never completed.
     */
    public function durationInSeconds(): ?int
    {
        if (! $this->started_at || ! $this->completed_at) {
            return null;
        }

        return (int) round($this->started_at->diffInSeconds($this->completed_at));
    }

    /**
     * Limit the query to backups that have finished successfully.
     *
     * @param  Builder<Backup>  $query
     * @return Builder<Backup>
     */
    public function scopeCompleted(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_COMPLETED);
    }

    /**
     * Limit the query to backups matching a retention frequency.
     *
     * @param  Builder<Backup>  $query
     * @return Builder<Backup>
     */
    public function scopeFrequency(Builder $query, ?string $frequency): Builder
    {
        return $frequency === null ? $query : $query->where('frequency', $frequency);
    }

    /**
     * Format a byte count for display.
     */
    protected function formatBytes(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $power = $bytes > 0 ? (int) floor(log($bytes, 1024)) : 0;
        $power = min($power, count($units) - 1);

        return round($bytes / (1024 ** $power), $power === 0 ? 0 : 1).' '.$units[$power];
    }
}
