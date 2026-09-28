<?php

namespace App\Console\Commands;

use App\Backup\RetentionService;
use Illuminate\Console\Command;

class CleanupBackupsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:cleanup {--pretend : Report what would be deleted without deleting it}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Delete backups that have fallen outside the configured retention window';

    /**
     * Execute the console command.
     */
    public function handle(RetentionService $retention): int
    {
        $pretend = (bool) $this->option('pretend');
        $result = $retention->prune($pretend);

        if ($pretend) {
            $this->components->info('Dry run. No files were removed.');
        }

        foreach ($result['deleted'] as $filename) {
            $this->components->twoColumnDetail($pretend ? 'Would delete' : 'Deleted', $filename);
        }

        $this->components->twoColumnDetail('Retained', (string) count($result['retained']));
        $this->components->twoColumnDetail('Deleted', (string) count($result['deleted']));

        if ($result['deleted'] === []) {
            $this->components->info('Nothing to clean up. Every backup is inside its retention window.');
        }

        return self::SUCCESS;
    }
}
