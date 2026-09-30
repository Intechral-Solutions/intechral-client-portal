<?php

namespace App\Providers;

use App\Models\Invoice;
use App\Models\Project;
use App\Models\Task;
use App\Models\Ticket;
use App\Policies\InvoicePolicy;
use App\Policies\ProjectPolicy;
use App\Policies\TaskPolicy;
use App\Policies\TicketPolicy;
use App\Support\TestDatabaseSafety;
use App\View\Composers\ShellComposer;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
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
    }
}
