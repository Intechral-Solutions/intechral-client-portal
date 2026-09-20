<?php

use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\Billing\ClientInvoiceController;
use App\Http\Controllers\Billing\InvoiceController;
use App\Http\Controllers\Billing\InvoicePaymentController;
use App\Http\Controllers\Billing\StripeWebhookController;
use App\Http\Controllers\CmsController;
use App\Http\Controllers\Crm\CompanyController;
use App\Http\Controllers\Crm\ContactController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\Operator\CmsPageController;
use App\Http\Controllers\Operator\TicketBulkController;
use App\Http\Controllers\Operator\TicketController as OperatorTicketController;
use App\Http\Controllers\Operator\TicketReplyController as OperatorReplyController;
use App\Http\Controllers\Operator\TicketReportController;
use App\Http\Controllers\Operator\TimeReportController;
use App\Http\Controllers\Organization\OrganizationController;
use App\Http\Controllers\Organization\OrganizationMemberController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectBoardController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMilestoneController;
use App\Http\Controllers\ProjectTaskController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\TimeEntryController;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Support\Facades\Route;

// Root redirect
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

// Fortify owns: GET/POST /login, POST /logout, GET/POST /forgot-password,
//               GET/POST /reset-password, GET/POST /two-factor-challenge,
//               GET/POST /confirm-password, GET/POST /user/profile-information,
//               GET/POST /user/password, GET/POST /user/two-factor-*

// Invitation-based registration (replaces Fortify registration)
Route::middleware('guest')->group(function () {
    Route::get('/invitation/{token}', [InvitationController::class, 'show'])->name('invitation.show');
    Route::post('/invitation/{token}', [InvitationController::class, 'register'])->name('invitation.register');
});

// SSO (Socialite)
Route::get('/auth/{provider}/redirect', [SocialiteController::class, 'redirect'])->name('sso.redirect');
Route::get('/auth/{provider}/callback', [SocialiteController::class, 'callback'])->name('sso.callback');

// Operator: send invitation
Route::middleware(['auth', 'can:users.invite'])->group(function () {
    Route::post('/invitations', [InvitationController::class, 'store'])->name('invitations.store');
});

// Admin: role management
Route::middleware(['auth', 'can:roles.view'])->prefix('admin')->name('roles.')->group(function () {
    Route::get('/roles', [RoleController::class, 'index'])->name('index');

    Route::middleware('can:roles.manage')->group(function () {
        Route::get('/roles/create', [RoleController::class, 'create'])->name('create');
        Route::post('/roles', [RoleController::class, 'store'])->name('store');
        Route::get('/roles/{role}/edit', [RoleController::class, 'edit'])->name('edit');
        Route::put('/roles/{role}', [RoleController::class, 'update'])->name('update');
    });

    Route::delete('/roles/{role}', [RoleController::class, 'destroy'])
        ->middleware('can:roles.admin')
        ->name('destroy');
});

// Ticket attachment download (auth only — policy check inside controller)
Route::middleware('auth')
    ->get('/attachments/{attachment}/download', [TicketController::class, 'downloadAttachment'])
    ->name('tickets.attachment.download');

// Tickets (user-facing) — literal routes before wildcard {ticket}
Route::middleware(['auth', 'can:tickets.create'])->group(function () {
    Route::get('/tickets/create', [TicketController::class, 'create'])->name('tickets.create');
    Route::post('/tickets', [TicketController::class, 'store'])->name('tickets.store');
});

Route::middleware(['auth', 'can:tickets.view'])->group(function () {
    Route::get('/tickets', [TicketController::class, 'index'])->name('tickets.index');
    Route::get('/tickets/{ticket}', [TicketController::class, 'show'])->name('tickets.show');
});

// Ticket replies (authenticated users can reply to their own tickets)
Route::middleware('auth')
    ->post('/tickets/{ticket}/replies', [OperatorReplyController::class, 'store'])
    ->name('tickets.replies.store');

// Operator ticket management — literal routes before wildcard {ticket}
Route::middleware(['auth', 'can:tickets.assign'])->prefix('operator')->name('operator.tickets.')->group(function () {
    Route::get('/tickets', [OperatorTicketController::class, 'index'])->name('index');
    Route::post('/tickets/bulk', [TicketBulkController::class, 'update'])->name('bulk');
    Route::get('/tickets/reports', [TicketReportController::class, 'index'])->name('reports');
    Route::get('/tickets/reports/export', [TicketReportController::class, 'export'])->name('export');
    Route::get('/tickets/{ticket}', [OperatorTicketController::class, 'show'])->name('show');
    Route::put('/tickets/{ticket}/status', [OperatorTicketController::class, 'updateStatus'])->name('status');
    Route::put('/tickets/{ticket}/assign', [OperatorTicketController::class, 'assign'])->name('assign');
    Route::post('/tickets/{ticket}/replies', [OperatorReplyController::class, 'store'])->name('replies.store');
});

// Admin: user management
Route::middleware(['auth', 'can:users.view'])->prefix('admin')->name('users.')->group(function () {
    Route::get('/users', [UserController::class, 'index'])->name('index');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('show');

    Route::put('/users/{user}/roles', [UserController::class, 'updateRoles'])
        ->middleware('can:users.manage')
        ->name('roles.update');
});

// Projects
Route::middleware('auth')->prefix('projects')->name('projects.')->group(function () {
    Route::get('/', [ProjectController::class, 'index'])->name('index');

    // Literal routes before wildcard {project}
    Route::middleware('can:projects.manage')->group(function () {
        Route::get('/create', [ProjectController::class, 'create'])->name('create');
        Route::post('/', [ProjectController::class, 'store'])->name('store');
    });

    Route::get('/{project}', [ProjectController::class, 'show'])->name('show');
    Route::get('/{project}/board', [ProjectBoardController::class, 'show'])->name('board');

    Route::middleware('can:projects.manage')->group(function () {
        Route::get('/{project}/edit', [ProjectController::class, 'edit'])->name('edit');
        Route::put('/{project}', [ProjectController::class, 'update'])->name('update');
        Route::delete('/{project}', [ProjectController::class, 'destroy'])->name('destroy');
        Route::put('/{project}/members', [ProjectController::class, 'syncMembers'])->name('members.sync');
        Route::put('/{project}/companies', [ProjectController::class, 'syncCompanies'])->name('companies.sync');
    });

    // Tasks (nested under project)
    Route::post('/{project}/tasks', [ProjectTaskController::class, 'store'])->name('tasks.store');
    Route::get('/{project}/tasks/{task}', [ProjectTaskController::class, 'show'])->name('tasks.show');
    Route::put('/{project}/tasks/{task}', [ProjectTaskController::class, 'update'])->name('tasks.update');
    Route::delete('/{project}/tasks/{task}', [ProjectTaskController::class, 'destroy'])->name('tasks.destroy');
    Route::put('/{project}/tasks/{task}/move', [ProjectTaskController::class, 'move'])->name('tasks.move');
    Route::post('/{project}/tasks/{task}/comments', [ProjectTaskController::class, 'addComment'])->name('tasks.comments.store');
    Route::put('/{project}/tasks/{task}/checklist/{item}/toggle', [ProjectTaskController::class, 'toggleChecklistItem'])->name('tasks.checklist.toggle');

    // Milestones
    Route::get('/{project}/milestones', [ProjectMilestoneController::class, 'index'])->name('milestones.index');
    Route::middleware('can:projects.manage')->group(function () {
        Route::post('/{project}/milestones', [ProjectMilestoneController::class, 'store'])->name('milestones.store');
        Route::put('/{project}/milestones/{milestone}', [ProjectMilestoneController::class, 'update'])->name('milestones.update');
        Route::delete('/{project}/milestones/{milestone}', [ProjectMilestoneController::class, 'destroy'])->name('milestones.destroy');
    });
});

// Stripe webhook (no auth, no CSRF — verified by Stripe signature)
Route::post('/webhooks/stripe', [StripeWebhookController::class, 'handle'])
    ->withoutMiddleware([PreventRequestForgery::class])
    ->name('webhooks.stripe');

// Billing — operator invoice management (literal routes before {invoice} wildcard)
Route::middleware(['auth', 'can:billing.manage'])->prefix('billing')->name('billing.invoices.')->group(function () {
    // Literal routes before {invoice} wildcard
    Route::get('/invoices', [InvoiceController::class, 'index'])->name('index');
    Route::get('/invoices/create', [InvoiceController::class, 'create'])->name('create');
    Route::post('/invoices', [InvoiceController::class, 'store'])->name('store');

    // Wildcard routes
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('show');
    Route::get('/invoices/{invoice}/edit', [InvoiceController::class, 'edit'])->name('edit');
    Route::put('/invoices/{invoice}', [InvoiceController::class, 'update'])->name('update');
    Route::post('/invoices/{invoice}/send', [InvoiceController::class, 'send'])->name('send');
    Route::post('/invoices/{invoice}/payments', [InvoiceController::class, 'recordPayment'])->name('payment.record');
    Route::delete('/invoices/{invoice}', [InvoiceController::class, 'destroy'])->name('destroy');
});

// Billing — client invoice views (any authenticated user can see their own invoices)
Route::middleware('auth')->prefix('my')->name('billing.client.invoices.')->group(function () {
    Route::get('/invoices', [ClientInvoiceController::class, 'index'])->name('index');
    Route::get('/invoices/{invoice}', [ClientInvoiceController::class, 'show'])->name('show');
});

// Billing — Stripe payment flow
Route::middleware('auth')->prefix('billing')->name('billing.invoices.')->group(function () {
    Route::get('/invoices/{invoice}/pay', [InvoicePaymentController::class, 'show'])->name('pay');
    Route::post('/invoices/{invoice}/pay/intent', [InvoicePaymentController::class, 'intent'])->name('pay.intent');
});

// Time tracking — user (log own time, timer)
Route::middleware(['auth', 'can:time.log'])->prefix('time')->name('time.')->group(function () {
    Route::get('/', [TimeEntryController::class, 'index'])->name('index');
    Route::post('/', [TimeEntryController::class, 'store'])->name('store');

    // Literal routes must come before wildcards ─────────────────────────────

    // Allocation chart view (sub-tab of Time)
    Route::get('/allocation', [TimeEntryController::class, 'allocationView'])->name('allocation');

    // JSON: active timers for the global overlay
    Route::get('/timers/active', [TimeEntryController::class, 'activeTimersJson'])->name('timers.active');

    // JSON: context-aware options for the cascading timer-start selector
    Route::get('/context-options', [TimeEntryController::class, 'contextOptions'])->name('context.options');

    // Timer start/stop
    Route::post('/timer/start', [TimeEntryController::class, 'timerStart'])->name('timer.start');
    Route::post('/timer/{entry}/stop', [TimeEntryController::class, 'timerStop'])->name('timer.stop');
    Route::patch('/timer/{entry}/description', [TimeEntryController::class, 'updateTimerDescription'])->name('timer.description');

    // Block allocation adjustment (from allocation chart drag)
    Route::patch('/blocks/{block}/allocation', [TimeEntryController::class, 'updateBlockAllocation'])->name('blocks.allocation');

    // Wildcard entry routes ─────────────────────────────────────────────────
    Route::put('/{entry}', [TimeEntryController::class, 'update'])->name('update');
    Route::delete('/{entry}', [TimeEntryController::class, 'destroy'])->name('destroy');
});

// Time tracking — operator reports & export (literal before wildcard)
Route::middleware(['auth', 'can:time.view_all'])->prefix('operator/time')->name('operator.time.')->group(function () {
    Route::get('/export', [TimeReportController::class, 'export'])->name('export');
    Route::get('/', [TimeReportController::class, 'index'])->name('index');
});

// CRM — company + contact management (operators with crm.manage)
Route::middleware(['auth', 'can:crm.manage'])->prefix('crm')->name('crm.')->group(function () {
    // Companies (literal routes before {company} wildcard)
    Route::get('/companies', [CompanyController::class, 'index'])->name('companies.index');
    Route::get('/companies/create', [CompanyController::class, 'create'])->name('companies.create');
    Route::post('/companies', [CompanyController::class, 'store'])->name('companies.store');
    Route::get('/companies/{company}', [CompanyController::class, 'show'])->name('companies.show');
    Route::get('/companies/{company}/edit', [CompanyController::class, 'edit'])->name('companies.edit');
    Route::put('/companies/{company}', [CompanyController::class, 'update'])->name('companies.update');
    Route::delete('/companies/{company}', [CompanyController::class, 'destroy'])->name('companies.destroy');
    Route::post('/companies/{company}/promote', [CompanyController::class, 'promote'])->name('companies.promote');

    // Contacts (literal routes before {contact} wildcard)
    Route::get('/contacts', [ContactController::class, 'index'])->name('contacts.index');
    Route::get('/contacts/create', [ContactController::class, 'create'])->name('contacts.create');
    Route::post('/contacts', [ContactController::class, 'store'])->name('contacts.store');
    Route::get('/contacts/{contact}', [ContactController::class, 'show'])->name('contacts.show');
    Route::get('/contacts/{contact}/edit', [ContactController::class, 'edit'])->name('contacts.edit');
    Route::put('/contacts/{contact}', [ContactController::class, 'update'])->name('contacts.update');
    Route::delete('/contacts/{contact}', [ContactController::class, 'destroy'])->name('contacts.destroy');
});

// Organizations (operators only)
Route::middleware(['auth', 'can:crm.manage'])->prefix('organizations')->name('organizations.')->group(function () {
    Route::get('/', [OrganizationController::class, 'index'])->name('index');
    Route::get('/{organization}', [OrganizationController::class, 'show'])->name('show');
    Route::post('/{organization}/members', [OrganizationMemberController::class, 'store'])->name('members.store');
    Route::put('/{organization}/members/{user}/role', [OrganizationMemberController::class, 'updateRole'])->name('members.role');
    Route::delete('/{organization}/members/{user}', [OrganizationMemberController::class, 'destroy'])->name('members.destroy');
});

// CMS — operator page management (literal routes before {page} wildcard)
Route::middleware(['auth', 'can:cms.edit'])->prefix('operator/cms')->name('operator.cms.')->group(function () {
    Route::get('/', [CmsPageController::class, 'index'])->name('index');
    Route::get('/create', [CmsPageController::class, 'create'])->name('create');
    Route::post('/', [CmsPageController::class, 'store'])->name('store');
    Route::get('/{page}/edit', [CmsPageController::class, 'edit'])->name('edit');
    Route::put('/{page}', [CmsPageController::class, 'update'])->name('update');
    Route::post('/{page}/publish', [CmsPageController::class, 'publish'])->name('publish');
    Route::post('/{page}/unpublish', [CmsPageController::class, 'unpublish'])->name('unpublish');
    Route::delete('/{page}', [CmsPageController::class, 'destroy'])->name('destroy');
});

// CMS — public page viewer (any authenticated user with cms.view)
Route::middleware(['auth', 'can:cms.view'])->prefix('pages')->name('cms.')->group(function () {
    Route::get('/', [CmsController::class, 'index'])->name('index');
    Route::get('/{slug}', [CmsController::class, 'show'])->name('show');
});

// Tasks — unified task list (my tasks + org tasks)
Route::middleware('auth')->prefix('tasks')->name('tasks.')->group(function () {
    Route::get('/', [TaskController::class, 'index'])->name('index');
    Route::post('/', [TaskController::class, 'store'])->name('store');
});

// Dashboard
Route::get('/dashboard', DashboardController::class)->middleware('auth')->name('dashboard');

// Profile
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::get('/profile/confirm-password', [ProfileController::class, 'confirmPassword'])->name('profile.password.confirm');
    Route::delete('/profile/sessions', [ProfileController::class, 'destroyOtherSessions'])->name('profile.sessions.destroy');
    Route::delete('/profile/social/{provider}', [ProfileController::class, 'unlinkSocial'])->name('profile.social.unlink');
});
