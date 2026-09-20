<?php

test('inertia server side rendering is disabled', function () {
    expect(config('inertia.ssr.enabled'))->toBeFalse();
});

test('test bootstrap normalizes inherited environment before application creation', function () {
    expect(getenv('APP_ENV'))->toBe('testing')
        ->and($_ENV['APP_ENV'])->toBe('testing')
        ->and($_SERVER['APP_ENV'])->toBe('testing')
        ->and(getenv('DB_DATABASE'))->toBe('intechral_client_portal_testing')
        ->and($_ENV['DB_DATABASE'])->toBe('intechral_client_portal_testing')
        ->and($_SERVER['DB_DATABASE'])->toBe('intechral_client_portal_testing')
        ->and(app()->environment())->toBe('testing')
        ->and(config('database.connections.'.config('database.default').'.database'))
        ->toBe('intechral_client_portal_testing');
});

test('browser facing URLs use the externally reachable application origin', function () {
    expect(config('app.url'))->toBe('http://localhost:4242')
        ->and(config('services.google.redirect'))->toBe('http://localhost:4242/auth/google/callback')
        ->and(config('services.microsoft.redirect'))->toBe('http://localhost:4242/auth/microsoft/callback')
        ->and(route('password.reset', ['token' => 'reset-token']))
        ->toBe('http://localhost:4242/reset-password/reset-token')
        ->and(route('invitation.show', ['token' => 'invitation-token']))
        ->toBe('http://localhost:4242/invitation/invitation-token');
});
