<?php

use Illuminate\Support\Facades\Route;

// Root redirects authenticated users to the dashboard, guests to login.
// Auth routes (login, register, password reset) are registered by Fortify (EPIC-002).
// Dashboard and module routes are registered per epic as they are built.
Route::get('/', function () {
    return auth()->check()
        ? redirect()->route('dashboard')
        : redirect()->route('login');
});

// Temporary login stub — replaced by Laravel Fortify in EPIC-002.
Route::get('/login', function () {
    return view('auth.login');
})->middleware('guest')->name('login');

Route::post('/login', function (\Illuminate\Http\Request $request) {
    $credentials = $request->validate([
        'email'    => ['required', 'email'],
        'password' => ['required'],
    ]);

    if (\Illuminate\Support\Facades\Auth::attempt($credentials, $request->boolean('remember'))) {
        $request->session()->regenerate();
        return redirect()->intended(route('dashboard'));
    }

    return back()->withErrors(['email' => 'These credentials do not match our records.'])->onlyInput('email');
})->middleware('guest');

Route::post('/logout', function (\Illuminate\Http\Request $request) {
    \Illuminate\Support\Facades\Auth::logout();
    $request->session()->invalidate();
    $request->session()->regenerateToken();
    return redirect()->route('login');
})->middleware('auth')->name('logout');

// Placeholder dashboard — replaced with a real implementation in EPIC-002.
Route::get('/dashboard', function () {
    return view('dashboard');
})->middleware('auth')->name('dashboard');
