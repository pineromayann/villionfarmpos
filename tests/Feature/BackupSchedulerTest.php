<?php

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Collection;

/**
 * The scheduled backup events, keyed by their artisan subcommand.
 *
 * @return Collection<string, Event>
 */
function scheduledBackupEvents(): Collection
{
    return collect(app(Schedule::class)->events())
        ->filter(fn (Event $event) => str_contains($event->command ?? '', 'backup:'))
        ->keyBy(fn (Event $event) => preg_replace('/^.*?artisan"?\s*/', '', (string) $event->command));
}

test('the daily, weekly, monthly and cleanup backups are scheduled', function () {
    expect(scheduledBackupEvents()->keys()->all())->toEqualCanonicalizing([
        'backup:create --database --frequency=daily',
        'backup:create --full --frequency=weekly',
        'backup:create --full --frequency=monthly',
        'backup:cleanup',
    ]);
});

test('each scheduled backup runs at the time configured in config', function () {
    $expressions = scheduledBackupEvents()
        ->mapWithKeys(fn (Event $event, string $command) => [$command => $event->expression])
        ->all();

    [$hour, $minute] = array_map('intval', explode(':', (string) config('backup.schedule.daily')));
    [$weeklyHour, $weeklyMinute] = array_map('intval', explode(':', (string) config('backup.schedule.weekly')));
    [$monthlyHour, $monthlyMinute] = array_map('intval', explode(':', (string) config('backup.schedule.monthly')));
    [$cleanupHour, $cleanupMinute] = array_map('intval', explode(':', (string) config('backup.schedule.cleanup')));

    expect($expressions)->toBe([
        'backup:create --database --frequency=daily' => "{$minute} {$hour} * * *",
        'backup:create --full --frequency=weekly' => "{$weeklyMinute} {$weeklyHour} * * 1",
        'backup:create --full --frequency=monthly' => "{$monthlyMinute} {$monthlyHour} 1 * *",
        'backup:cleanup' => "{$cleanupMinute} {$cleanupHour} * * *",
    ]);
});

test('the configured schedule times are plain clock times, not cron fragments', function () {
    foreach (config('backup.schedule') as $key => $value) {
        expect($value)->toMatch('/^([01]\d|2[0-3]):[0-5]\d$/', "backup.schedule.{$key}");
    }
});

test('scheduled backups run on one server and never overlap', function () {
    $scheduled = scheduledBackupEvents()->filter(fn (Event $event, string $command) => str_contains($command, 'backup:create'));

    expect($scheduled)->not->toBeEmpty();

    foreach ($scheduled as $event) {
        expect($event->withoutOverlapping)->toBeTrue()
            ->and($event->onOneServer)->toBeTrue();
    }
});
