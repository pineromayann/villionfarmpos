<?php

namespace App\Console\Commands;

use App\Models\Backup;
use Illuminate\Console\Command;

class ListBackupsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup:list {--status= : Limit to pending, running, completed or failed}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List every backup recorded on this installation';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $status = $this->option('status');

        if ($status !== null && ! in_array($status, (array) config('backup.statuses', []), true)) {
            $this->components->error("Unknown status [{$status}].");

            return self::FAILURE;
        }

        $backups = Backup::with('creator')
            ->when($status, fn ($query) => $query->where('status', $status))
            ->latest('id')
            ->get();

        if ($backups->isEmpty()) {
            $this->components->info('No backups have been created yet.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Filename', 'Type', 'Size', 'Status', 'Frequency', 'Created by', 'Created at'],
            $backups->map(fn (Backup $backup) => [
                $backup->id,
                $backup->filename,
                $backup->typeLabel(),
                $backup->humanSize(),
                $backup->status,
                $backup->frequency ?? '—',
                $backup->creator?->name ?? 'system',
                (string) $backup->created_at,
            ])->all(),
        );

        return self::SUCCESS;
    }
}
