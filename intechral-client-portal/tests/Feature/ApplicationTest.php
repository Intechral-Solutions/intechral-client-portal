<?php

// Foundation smoke test — confirms the app boots and serves the welcome page.
it('returns a successful response from the root URL', function () {
    $response = $this->get('/');

    $response->assertStatus(200);
});
