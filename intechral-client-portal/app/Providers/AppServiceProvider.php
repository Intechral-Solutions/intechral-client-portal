<?php

namespace App\Providers;

use App\Models\Project;
use App\Models\Ticket;
use App\Policies\ProjectPolicy;
use App\Policies\TicketPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        Gate::policy(Ticket::class, TicketPolicy::class);
        Gate::policy(Project::class, ProjectPolicy::class);
    }
}
