<?php

declare(strict_types=1);

namespace Logging\Tests;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\Test;
use Sentry\Laravel\ServiceProvider as SentryServiceProvider;
use Sentry\State\HubInterface;

/** An app whose config/sentry.php sets each key the Sentry driver defaults. */
class SentryAppConfigTest extends TestCase
{
    /** None is the driver's default or the SDK's, so each value that arrives names its source. */
    private const APP_SENTRY_CONFIG = [
        'max_request_body_size' => 'small',
        'http_timeout'          => 4,
        'http_connect_timeout'  => 3,
    ];

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::hasSentry()) {
            $this->markTestSkipped('sentry/sentry-laravel is not installed.');
        }
    }

    protected function getPackageProviders($app): array
    {
        self::loadAppSentryConfig($app);

        return self::hasSentry() ? [...parent::getPackageProviders($app), SentryServiceProvider::class] : parent::getPackageProviders($app);
    }

    /**
     * Testbench lists the providers after loading config and before registering them: where an app's
     * config/sentry.php is read. defineEnvironment() runs after they register.
     */
    protected static function loadAppSentryConfig(Application $app): void
    {
        $app['config']->set('sentry', self::APP_SENTRY_CONFIG);
    }

    #[Test]
    public function the_apps_value_wins_for_each_default(): void
    {
        $options = $this->app->make(HubInterface::class)->getClient()?->getOptions();
        $this->assertNotNull($options);

        $this->assertSame('small', $options->getMaxRequestBodySize());
        $this->assertSame(4.0, $options->getHttpTimeout());
        $this->assertSame(3.0, $options->getHttpConnectTimeout());
    }
}
