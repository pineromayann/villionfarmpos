<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Backups
|--------------------------------------------------------------------------
|
| Times come from config/backup.php so a deployment can move them without a
| code change. Every backup is a read-only operation, so a cashier completing
| a sale or inventory receiving a delivery while one runs is safe: the dump
| takes a consistent snapshot and never blocks the writer.
|
| withoutOverlapping() stops a slow dump from being stacked on top of the next
| scheduled run, and onOneServer() keeps a multi-node deployment from taking a
| duplicate snapshot.
|
*/

Schedule::command('backup:create --database --frequency=daily')
    ->dailyAt(config('backup.schedule.daily', '02:00'))
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Create the daily database backup');

Schedule::command('backup:create --full --frequency=weekly')
    ->weeklyOn(1, config('backup.schedule.weekly', '03:00'))
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Create the weekly full backup');

Schedule::command('backup:create --full --frequency=monthly')
    ->monthlyOn(1, config('backup.schedule.monthly', '04:00'))
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Create the monthly full backup');

Schedule::command('backup:cleanup')
    ->dailyAt(config('backup.schedule.cleanup', '05:00'))
    ->withoutOverlapping()
    ->onOneServer()
    ->description('Delete backups outside the retention window');
