<?php

$testEnvironment = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'mysql',
    'DB_HOST' => 'db',
    'DB_PORT' => '3306',
    'DB_DATABASE' => 'intechral_client_portal_testing',
    'DB_USERNAME' => 'portal',
    'DB_PASSWORD' => 'portal',
    'DB_URL' => '',
    // EPIC-017 O3: unset means "no operator alert". A value inherited from the shell or the container would make every ticket test send
    // a second notification and change what they assert. Tests that need the alert set the config key themselves.
    'HELPDESK_NOTIFY_ADDRESS' => '',
];

foreach ($testEnvironment as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require_once __DIR__.'/../vendor/autoload.php';
