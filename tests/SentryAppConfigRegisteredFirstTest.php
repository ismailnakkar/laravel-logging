<?php

declare(strict_types=1);

namespace Logging\Tests;

use Sentry\Laravel\ServiceProvider as SentryServiceProvider;

/** The same, with Sentry's provider registered first: an app that lists the providers by hand. */
class SentryAppConfigRegisteredFirstTest extends SentryAppConfigTest
{
    protected function getPackageProviders($app): array
    {
        self::loadAppSentryConfig($app);

        return self::hasSentry() ? [SentryServiceProvider::class, ...TestCase::getPackageProviders($app)] : TestCase::getPackageProviders($app);
    }
}
