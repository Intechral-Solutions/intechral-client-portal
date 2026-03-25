<?php

use App\Http\Controllers\Admin\RoleController;
use App\Http\Controllers\Admin\UserController;
use App\Http\Controllers\Auth\InvitationController;
use App\Http\Controllers\Auth\SocialiteController;
use App\Http\Controllers\ProfileController;
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

// Admin: user management
Route::middleware(['auth', 'can:users.view'])->prefix('admin')->name('users.')->group(function () {
    Route::get('/users', [UserController::class, 'index'])->name('index');
    Route::get('/users/{user}', [UserController::class, 'show'])->name('show');

    Route::put('/users/{user}/roles', [UserController::class, 'updateRoles'])
        ->middleware('can:users.manage')
        ->name('roles.update');
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
