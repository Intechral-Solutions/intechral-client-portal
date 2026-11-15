<?php

use Database\Seeders\DevSeeder;

// The browser suite answers the seeded operators' second-factor challenge with a TOTP computed from this secret; if the two ever drift, every
// operator sign-in in the browser suite (and the hosted job) fails at the challenge with an unhelpful timeout.
it('keeps the browser suite\'s TOTP secret identical to the seeded one', function () {
    $typescript = (string) file_get_contents(base_path('tests/Browser/support/totp.ts'));

    preg_match("/E2E_TOTP_SECRET = '([A-Z2-7]+)'/", $typescript, $match);

    expect($match[1] ?? null)->toBe(DevSeeder::E2E_TOTP_SECRET);
});

it('refuses to seed published credentials anywhere but a disposable environment, even when run directly', function (string $environment) {
    app()->detectEnvironment(fn () => $environment);

    expect(fn () => (new DevSeeder)->run())->toThrow(RuntimeException::class, 'published credentials');
})->with(['production', 'staging']);
