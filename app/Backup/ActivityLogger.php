<?php

namespace App\Backup;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use Throwable;

/**
 * Writes an audit trail for sensitive operations.
 *
 * Every entry records who acted, what they acted on, the outcome, when and
 * from where. Failures to write the trail are themselves logged so a broken
 * activity log never masks the operation the user was actually trying to
 * perform.
 */
class ActivityLogger
{
    /**
     * Record an action against an optional subject model.
     *
     * @param  array{user?: ?User, result?: string, ip?: ?string, context?: array<string, mixed>}  $options
     */
    public static function record(string $action, ?Model $subject = null, array $options = []): ?ActivityLog
    {
        try {
            $user = $options['user'] ?? Auth::user();

            return ActivityLog::create([
                'user_id' => $user?->id,
                'action' => $action,
                'subject_type' => $subject === null ? null : $subject::class,
                'subject_id' => $subject?->getKey(),
                'result' => $options['result'] ?? ActivityLog::RESULT_SUCCESS,
                'ip_address' => $options['ip'] ?? self::ipAddress(),
                'user_agent' => self::userAgent(),
                'context' => $options['context'] ?? [],
            ]);
        } catch (Throwable $e) {
            Log::error('Unable to write an activity log entry.', [
                'action' => $action,
                'exception' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * The requesting IP address, when the request context is available.
     */
    public static function ipAddress(): ?string
    {
        try {
            if (! app()->bound('request')) {
                return null;
            }

            return Request::ip();
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The requesting user agent, truncated to fit the column.
     */
    protected static function userAgent(): ?string
    {
        try {
            if (! app()->bound('request')) {
                return null;
            }

            $agent = Request::userAgent();

            return $agent === null ? null : mb_substr($agent, 0, 500);
        } catch (Throwable) {
            return null;
        }
    }
}
