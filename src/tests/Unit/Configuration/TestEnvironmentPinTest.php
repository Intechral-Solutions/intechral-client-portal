<?php

use App\Support\Helpdesk\HelpdeskNotifyAddress;

/*
 * The suite must start from a known notification configuration whatever the shell or the container exports (S1 review L1): with an
 * inherited HELPDESK_NOTIFY_ADDRESS every ticket test would send a second notification and change what it asserts.
 * tests/bootstrap.php pins it; this fails if that pin is removed. Run it with the variable exported to see it matter:
 *   docker exec -e HELPDESK_NOTIFY_ADDRESS=ops@desk.invalid portal_app ./vendor/bin/pest tests/Unit/Configuration/TestEnvironmentPinTest.php
 */
it('starts every test with no operator alert destination, whatever the process environment holds', function () {
    expect(HelpdeskNotifyAddress::configured())->toBeNull()
        ->and(config('helpdesk.notify_address'))->toBe('')
        ->and(HelpdeskNotifyAddress::usable())->toBeNull();
});
