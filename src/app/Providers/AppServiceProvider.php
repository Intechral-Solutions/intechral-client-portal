<?php

namespace App\Providers;

use App\Auth\ActiveUserProvider;
use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Notifications\SkipSuspendedRecipients;
use App\Policies\InvoicePolicy;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TicketPolicy;
use App\Support\Database\MigrationProfileGuard;
use App\Support\Permissions\EpochPermissionRegistrar;
use App\Support\Permissions\PermissionCacheEpoch;
use App\Support\TestDatabaseSafety;
use App\View\Composers\ShellComposer;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Database\Events\MigrationsEnded;
use Illuminate\Database\Events\MigrationsStarted;
use Illuminate\Notifications\Events\NotificationSending;
use Illuminate\Pagination\Paginator;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Spatie\Permission\PermissionRegistrar;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Release 0.9 (R0.9-1E): the permission library's shared cache is fenced by a database-backed epoch
        // (docs/architecture/role-governance.md). `extend` rather than `singleton`, because the library binds its own
        // registrar in its boot(), after every register(), and `extend` also applies to an instance that already exists.
        $this->app->singleton(PermissionCacheEpoch::class);
        $this->app->extend(PermissionRegistrar::class, fn (PermissionRegistrar $registrar, Application $app) => new EpochPermissionRegistrar(
            $app->make(CacheManager::class),
            $app->make(PermissionCacheEpoch::class),
        ));
    }

    public function boot(): void
    {
        if ($this->app->runningUnitTests() || $this->app->environment('testing')) {
            $connection = (string) config('database.default');

            TestDatabaseSafety::assertSafe(
                environment: $this->app->environment(),
                database: (string) config("database.connections.{$connection}.database"),
            );
        }

        // Release 0.9 (H3): the `users` provider (config/auth.php) resolves active accounts only, and no notification
        // reaches a suspended user, including one queued before the suspension. docs/architecture/account-lifecycle.md.
        Auth::provider('active-eloquent', fn (Application $app, array $config) => new ActiveUserProvider($app['hash'], $config['model']));
        Event::listen(NotificationSending::class, SkipSuspendedRecipients::class);

        // Release 0.9 (R0.9 WP3): in production a migration neither starts nor is reported complete on a database that does
        // not match the supported profile (InnoDB, UTC session, strict mode, utf8mb4). docs/architecture/database-profile.md.
        Event::listen([MigrationsStarted::class, MigrationsEnded::class], MigrationProfileGuard::class);

        // Release 0.9 (R0.9-1E): the registrar is a singleton and a queue worker only resets scoped instances between
        // jobs, so a worker would otherwise keep the permission mapping it loaded for its first job. Each job starts with
        // none and reads the current epoch for its first check. The `sync` connection runs inside the request that
        // dispatched it, whose registrar is request-bounded already.
        Event::listen(JobProcessing::class, function (JobProcessing $event) {
            if ($event->connectionName !== 'sync') {
                app(PermissionRegistrar::class)->clearPermissionsCollection();
            }
        });

        Gate::policy(Ticket::class, TicketPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        // EPIC-014 WP1: registered for the domain boundary; no route authorizes through it yet.
        Gate::policy(Task::class, TaskPolicy::class);

        // The Blade shell receives its navigation from the one builder, never by instantiating it
        // inside a view (EPIC-013 §12.3 rule 11). Bound to the Blade root once, so the builder runs
        // once per request and every `layouts.partials.shell.*` include inherits the payload; the
        // root <html> needs it too, for the pre-paint bootstrap's inputs (§15.3).
        View::composer('layouts.app', ShellComposer::class);

        // EPIC-016 WP1 §7.4: every Blade `->links()` renders through the Direction D pagination view
        // instead of the vendor Tailwind one. Laravel still builds every URL, so query strings and
        // numbered/prev/next behaviour are unchanged.
        Paginator::defaultView('pagination.direction-d');
        Paginator::defaultSimpleView('pagination.simple-direction-d');
    }
}
