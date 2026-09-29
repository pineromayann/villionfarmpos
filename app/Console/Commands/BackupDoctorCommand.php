<?php

namespace App\Console\Commands;

use App\Backup\EnvironmentCheck;
use Illuminate\Console\Command;
use Throwable;

class BackupDoctorCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:doctor';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check that this host can take and restore backups, and report anything that would silently stop it';

    /**
     * Execute the console command.
     */
    public function handle(EnvironmentCheck $check): int
    {
        $this->components->info('Checking whether this host can take and restore backups.');
        $this->newLine();

        try {
            $checks = $check->checks();
        } catch (Throwable $e) {
            $this->components->error('The check could not complete: '.$e->getMessage());

            return self::FAILURE;
        }

        $group = null;

        foreach ($checks as $entry) {
            if ($entry['group'] !== $group) {
                $group = $entry['group'];

                if ($group !== null) {
                    $this->newLine();
                    $this->line('<fg=cyan;options=bold>'.$group.'</>');
                }
            }

            $this->line(sprintf(
                '  %s %s',
                $this->mark($entry['status']),
                sprintf('<fg=gray>%s</>  %s', $entry['label'], $entry['detail']),
            ));
        }

        $failures = $check->failures($checks);
        $warnings = $check->warnings($checks);

        $this->newLine();

        if ($failures === 0 && $warnings === 0) {
            $this->components->info('Everything checks out. Backups and restores will work on this host.');

            return self::SUCCESS;
        }

        if ($failures > 0) {
            $this->components->error($failures.' problem(s) will stop backups or restores working. Fix these before trusting this installation.');
        }

        if ($warnings > 0) {
            $this->components->warn($warnings.' thing(s) worth knowing about.');
        }

        $this->newLine();
        $this->line('  Next: run "php artisan backup:create --database" to take a backup by hand and prove it works.');

        return $failures > 0 ? self::FAILURE : self::SUCCESS;
    }

    /**
     * The symbol shown against a check.
     */
    protected function mark(string $status): string
    {
        return match ($status) {
            EnvironmentCheck::OK => '<fg=green>OK</>',
            EnvironmentCheck::WARN => '<fg=yellow>--</>',
            default => '<fg=red>XX</>',
        };
    }
}
