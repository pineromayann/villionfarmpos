# Backups and Restores

A short guide for the person who runs this site. It covers what happens
automatically, the two things your host must set up, how to restore, and what
each warning means.

## After installing: check the host first

Before trusting any of this on a new server, run one command:

```
php artisan backup:doctor
```

It checks the things that quietly break backups on shared hosting — whether
mysqldump and the mysql client are actually installed, whether the host lets PHP
run external programs, whether the database account can replace tables, whether
the backup folder is private and writable, whether there is room on the disk,
and whether the queue is working. Anything that would stop backups or restores
working is marked **XX** and explained in a sentence you can act on.

It also tells you when the last successful backup is too old, which is how you
find out the scheduling below was never set up.

Then take one backup by hand to prove the whole path works:

```
php artisan backup:create --database
```

## What happens on its own

Once the host has done the setup below, the site takes these backups without
anyone logging in or clicking anything:

| When | What is saved |
| --- | --- |
| Every night at 2:00am | The database |
| Every Monday at 3:00am | The database plus uploaded files |
| The 1st of the month at 4:00am | The database plus uploaded files |
| Every night at 5:00am | Old backups outside the retention window are deleted |

Old backups are cleared up automatically: the 7 most recent nightly backups, the
4 most recent weekly ones and the 6 most recent monthly ones are kept. Anything
waiting or still running is never deleted.

Taking a backup never locks the till. A cashier can finish a sale, or a delivery
can be received, while a backup runs.

## The two things your host must set up

Backups and restores are queued on their own queues so a long job never makes
the website slow. That means two commands have to run every minute on the server:

```
* * * * * cd /home/USER/DOMAIN && /usr/local/bin/php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /home/USER/DOMAIN && /usr/local/bin/php artisan queue:work --queue=backups,restores --stop-when-empty --tries=1 --timeout=3600 --memory-limit=512M >> /dev/null 2>&1
```

- The first one decides *when* a backup is due.
- The second one actually *does* the work.

Replace `/home/USER/DOMAIN` with your account path, and `/usr/local/bin/php` with
the exact path to the PHP version this site runs on.

### On cPanel specifically

Three things catch people out here, and all three fail silently:

1. **`php` on its own usually does not work.** cPanel runs cron with a minimal
   PATH, so `php` is often "command not found" and nothing ever happens. Use the
   full path to the PHP binary, which you can find in cPanel under
   **MultiPHP Manager**. A site on PHP 8.3 will silently run against 7.4 if you
   use the default, which breaks the application in confusing ways.
2. **Do not hide the output.** The lines above end in `>> /dev/null 2>&1` so
   the server's mail box is not filled with output every minute. While you are
   setting this up, drop the redirect so you can see the error:

   ```
   * * * * * cd /home/USER/DOMAIN && /usr/local/bin/php artisan schedule:run
   ```

   cPanel emails you the output. Once it runs clean, put the redirect back.
3. **Give the queue a timeout and a memory limit.** A shared host kills a
   process that runs too long. `--timeout=3600` matches `BACKUP_TIMEOUT`, and
   `--memory-limit=512M` stops a large restore being killed part way through.

The `--queue=backups,restores` part is not optional. Backup and restore jobs are
queued under those two names, and a `queue:work` command without that option
listens only on the queue called `default`, so it will sit there doing nothing
while backups quietly stay stuck on **Pending**.

Without these, no automatic backups are ever taken. Nothing on screen will look
broken, which is why the site warns you instead (see
[Warnings](#warnings-worth-acting-on)) and why `backup:doctor` checks the age of
the last backup.

## Taking a backup now

Go to the **Backups** screen and press **Back up now**. It appears in the list
straight away as *Pending* and moves to *Completed* when it has finished.

## Restoring

Restoring replaces live sales, stock and consignment data with what a backup
contained. It is destructive, so it is switched off by default.

1. Turn **Allow restoring** on. Only an administrator can do this, and the
   change is recorded in the activity log with your name.
2. Open the backup you want and follow the confirm page.
3. Type the file name exactly as shown, to prove you meant it.
4. Press restore.

Before anything is replaced, the site:

- takes a fresh backup of the current data, so the last change is not lost;
- puts the site into maintenance mode, so no sale, receipt or stock movement can
  be written while the data is being replaced;
- keeps the site locked for the whole restore, so two restores cannot collide.

When it finishes, the site puts itself back on its own. The maintenance page tells
you what is happening and that there is nothing to do — just refresh the page
after a minute or two.

**If a restore fails, the maintenance page stays up on purpose.** That stops
people selling against half-restored data. The page tells you to run
`php artisan up`; the real cause is in `storage/logs/laravel.log`.

### A note on restoring a large site

A restore is a long job run by the queue worker, and shared hosts cap how long a
process may run. For a small shop's data this is a matter of seconds. If you ever
find yourself restoring a large database, run it from the cPanel **Terminal**
instead of the web screen, where the time limit is much more generous:

```
php artisan queue:work --queue=restores --stop-when-empty --tries=1 --timeout=7200 --memory-limit=1G
```

## Warnings worth acting on

### "New backups are switched off"

The disk configured for backups is somewhere the website could serve, and a
backup holds every record in the system, so nothing is written there. This is
asking your host to move backups somewhere private. The normal setting is
`BACKUP_DISK=local`, which is stored outside the web root.

### "Backups are waiting to run"

A backup or restore has been queued for longer than it should. The host's
`queue:work` cron entry is missing, stopped, or the server was down. Once it is
running again, the waiting job starts by itself — you do not need to re-request
anything.

### No new backups appearing at all

Check both cron entries exist. This is the one problem that produces no warning,
because nothing is ever queued in the first place. `php artisan backup:doctor`
reports it as a failed **Last completed backup** check.

### "There is not enough free disk space"

Shared hosting accounts have a small, hard quota. The site measured the space
before starting and refused rather than filling the account, because a full disk
takes the whole site down, including the screen you would use to fix it. Delete
old backups, ask your host to raise the quota, or move `BACKUP_DISK` somewhere
with more room.

## Where the files are

Backups are stored on a private disk, outside any folder the website can serve,
so they cannot be downloaded by guessing a web address. Only a signed-in
administrator can download one from the Backups screen.

Do not change `BACKUP_DISK` to `public`, and do not point it at a storage bucket
that anyone outside the site can read. The application refuses to write a backup
to a location it cannot confirm is private.

## After a deployment

```
php artisan migrate --force
php artisan config:clear
php artisan backup:doctor
```

`updatedb.sql` is for existing installs that predate the backup feature. It adds
the backup tables and marks the migrations it covers as already run, so `migrate`
will not try to create them again.

If the site starts returning **419 Page expired** on any form, run
`php artisan config:clear`. A stale cached config file is the usual cause.

## If something goes wrong

```
php artisan backup:list        # every backup, with status and size
php artisan queue:failed       # jobs that errored
php artisan backup:cleanup     # delete anything outside the retention window
```

Logs are in `storage/logs/laravel.log`. Backups and restores both log at the
critical level, so a search for `Restore` or `Backup` finds the last run.
