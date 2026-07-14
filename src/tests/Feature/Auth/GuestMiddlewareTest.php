<?php

use Illuminate\Support\Facades\Route;

// Tests confirming that protected routes redirect unauthenticated users to /login.
describe('Guest access to protected routes', function () {

    it('redirects guests from the dashboard to login', function () {
        // The dashboard route will be registered in EPIC-002; this confirms
        // the middleware is wired correctly once it exists.
        if (! Route::has('dashboard')) {
            $this->markTestSkipped('Dashboard route not yet defined (EPIC-002).');
        }

        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('login'));
    });

});
