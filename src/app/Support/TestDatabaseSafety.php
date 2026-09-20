<?php

namespace App\Support;

use RuntimeException;

final class TestDatabaseSafety
{
    public const DATABASE = 'intechral_client_portal_testing';

    public static function assertSafe(string $environment, string $database): void
    {
        if ($environment === 'testing' && $database === self::DATABASE) {
            return;
        }

        throw new RuntimeException(
            'Test database safety check failed before database access. '.
            sprintf(
                'Expected APP_ENV="testing" and DB_DATABASE="%s"; resolved APP_ENV="%s" and DB_DATABASE="%s".',
                self::DATABASE,
                $environment,
                $database,
            )
        );
    }
}
