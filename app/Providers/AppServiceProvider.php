<?php

namespace App\Providers;

use App\Backup\BinaryLocator;
use App\Backup\RestoreAccess;
use App\Models\Backup;
use App\Models\User;
use App\Policies\BackupPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(BinaryLocator::class, function () {
            $configured = array_filter([
                config('backup.mysql.dump_binary'),
                config('backup.mysql.client_binary'),
            ]);

            return (new BinaryLocator)->withPaths($configured);
        });

        // A singleton so the restore switch is read from the database once per
        // request instead of once per view, policy check and view.
        $this->app->singleton(RestoreAccess::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Backup::class, BackupPolicy::class);

        foreach (array_keys(config('permissions.permissions', [])) as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPermission($permission));
        }
    }
}
