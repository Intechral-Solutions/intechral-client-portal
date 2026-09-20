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
];

foreach ($testEnvironment as $name => $value) {
    putenv("{$name}={$value}");
    $_ENV[$name] = $value;
    $_SERVER[$name] = $value;
}

require_once __DIR__.'/../vendor/autoload.php';
