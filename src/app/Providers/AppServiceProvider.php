<?php

namespace App\Providers;

use App\Models\Invoice;
use App\Models\Project;
use App\Models\Ticket;
use App\Policies\InvoicePolicy;
use App\Policies\ProjectPolicy;
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

        // The Blade shell receives its navigation from the one builder, never by instantiating it
        // inside a view (EPIC-013 §12.3 rule 11). WP5 adds the `layouts.partials.shell.*` partials
        // to this binding as they replace `partials/nav`.
        View::composer(['layouts.partials.nav'], ShellComposer::class);
    }
}
