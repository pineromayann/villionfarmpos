# Backups and Restores

A short guide for the person who runs this site. It covers what happens
automatically, the two things your host must set up, how to restore, and what
each warning means.

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
the website slow. That means one command has to run every minute on the server:

```
* * * * * cd /path/to/this/site && php artisan schedule:run >> /dev/null 2>&1
* * * * * cd /path/to/this/site && php artisan queue:work --queue=backups,restores --stop-when-empty >> /dev/null 2>&1
```

- The first one decides *when* a backup is due.
- The second one actually *does* the work.

The `--queue=backups,restores` part is not optional. Backup and restore jobs are
queued under those two names, and a `queue:work` command without that option
listens only on the queue called `default`, so it will sit there doing nothing
while backups quietly stay stuck on **Pending**.

Without these, no automatic backups are ever taken. Nothing on screen will look
broken, which is why the site warns you instead (see
[Warnings](#warnings-worth-acting-on)).

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

When it finishes, the site puts itself back on its own. The maintenance page says
*"be right back"* — there is nothing to do and nothing to close; just refresh the
page after a minute or two.

**If a restore fails, the maintenance page stays up on purpose.** That stops
people selling against half-restored data. Read the error in
`storage/logs/laravel.log`, fix the cause, and put the site back with:

```
php artisan up
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
because nothing is ever queued in the first place.

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
```

`updatedb.sql` is for existing installs that predate the backup feature. It adds
the backup tables and marks the migrations it covers as already run, so `migrate`
will not try to create them again.

If the site starts returning **419 Page expired** on any form, run
`php artisan config:clear`. A stale cached config file is the usual cause.
