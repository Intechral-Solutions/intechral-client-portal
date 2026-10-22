<?php

use App\Http\Middleware\EnforceCustomerPilotSurface;
use App\Http\Middleware\HandleInertiaRequests;
use App\Shared\Pilot\CustomerPilotGate;
use App\Support\Runtime\CronFailureMarker;
use App\Support\Runtime\RuntimeChallenges;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('tickets:auto-close')->dailyAt('02:00');

        // Release 0.9 (R0.9 WP3-A2): the SCHEDULER half of the cross-context runtime check. In-process (no child process), production only
        // (`when` is evaluated before the callback, so development never queries), and with no active challenge one SELECT. It runs at
        // every scheduler tick rather than at minutes divisible by five: with the planned `*/5` cron that is every five minutes, and a
        // manual `schedule:run` at any minute still answers a challenge. docs/architecture/runtime-preflight.md.
        $schedule->call(fn () => RuntimeChallenges::observeQuietly('scheduler'))
            ->name('runtime-context-observation')
            ->everyMinute()
            ->when(fn () => app()->environment('production'));
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            EnforceCustomerPilotSurface::class,
            HandleInertiaRequests::class,
        ]);

        // Release 0.9 (R0.9-CS1): the customer pilot boundary needs the session user, and must refuse a route before
        // route-model binding loads anything for it.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: EnforceCustomerPilotSurface::class);

        // ...and Inertia's asset-version check must run before binding too (R0.9-CS1 review R-2). It answers 409 to a
        // stale `X-Inertia-Version` as soon as it runs, so after binding an existing project got that 409 while an unknown
        // id got binding's 404, which told a customer which ids exist. Listed after the boundary: a refused route is still
        // a plain 404 whatever version the client sent.
        $middleware->prependToPriorityList(before: SubstituteBindings::class, prepend: HandleInertiaRequests::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Release 0.9 (R0.9 WP3-B1): the database half of the cron wrapper's failure marker. `queue:work` REPORTS a failure to pop a job and carries on (exit 0),
        // so the reporting path is where it is seen. The callback returns nothing: the exception is still logged exactly as before, and the marker is silent
        // outside the wrapper. (A method, so Laravel reads the `Throwable` type from its signature; this file has no namespace to import it into.)
        $exceptions->reportable(CronFailureMarker::reported(...));

        // Release 0.9 (R0.9-CS1): for a customer, every JSON 404 carries the same body. Laravel puts an HttpException's own
        // message in a JSON response even with debug off, and route-model binding's message names the model and the id
        // ("No query results for model [App\\Models\\Project] 12"), so an unknown id and a project the customer may not see
        // would otherwise differ. HTML and Inertia 404s already render one page.
        $exceptions->render(function (NotFoundHttpException $exception, Request $request) {
            if ($request->expectsJson() && CustomerPilotGate::restricts($request->user())) {
                return response()->json(['message' => 'Not Found'], 404);
            }

            return null;
        });
    })->create();
