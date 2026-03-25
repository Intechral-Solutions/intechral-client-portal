<?php

use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\Operator\TicketBulkController;
use App\Http\Controllers\Operator\TicketReplyController as OperatorReplyController;
use App\Http\Controllers\Operator\TicketReportController;
use App\Http\Controllers\Operator\TicketController as OperatorTicketController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\ProjectBoardController;
use App\Http\Controllers\ProjectController;
use App\Http\Controllers\ProjectMilestoneController;
use App\Http\Controllers\ProjectTaskController;
use App\Http\Controllers\TicketController;
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
Route::get('/invitation/{token}', [InvitationController::class, 'show'])->name('invitation.show');
Route::post('/invitation/{token}', [InvitationController::class, 'register'])->name('invitation.register');

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

// Dashboard
Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware('auth')->name('dashboard');

// Profile
Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'show'])->name('profile.show');
    Route::delete('/profile/sessions', [ProfileController::class, 'destroyOtherSessions'])->name('profile.sessions.destroy');
    Route::delete('/profile/social/{provider}', [ProfileController::class, 'unlinkSocial'])->name('profile.social.unlink');
});
