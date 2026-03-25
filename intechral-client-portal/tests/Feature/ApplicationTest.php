<?php

// Smoke test — root URL redirects guests to login.
it('redirects guests from the root URL to login', function () {
    $this->get('/')->assertRedirect('/login');
});
