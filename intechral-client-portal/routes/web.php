<?php

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
