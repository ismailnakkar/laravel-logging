<?php

declare(strict_types=1);

namespace Logging\Tests;

use Illuminate\Support\Facades\Log;
use Logging\RedactLogs;
use Monolog\Handler\TestHandler;
use Monolog\Logger as Monolog;
use PHPUnit\Framework\Attributes\Test;
use Sentry\ClientBuilder;

/** Runs only in the CI job that removes sentry/sentry-laravel. */
class WithoutSentryTest extends TestCase
{
    private TestHandler $sink;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::hasSentry()) {
            $this->markTestSkipped('sentry/sentry-laravel is installed.');
        }
    }

    protected function defineEnvironment($app): void
    {
        $this->sink = new TestHandler;

        $app['config']->set('logging.channels.sink', [
            'driver' => 'custom',
            'via'    => fn (): Monolog => new Monolog('sink', [$this->sink]),
            'tap'    => [RedactLogs::class],
        ]);
    }

    #[Test]
    public function the_sentry_driver_wires_nothing_and_log_lines_are_still_redacted(): void
    {
        $this->assertFalse($this->app->bound(ClientBuilder::class));

        Log::channel('sink')->error('failed', ['token' => 'abc']);

        $this->assertSame(['token' => '[redacted]'], $this->sink->getRecords()[0]->context);
    }
}
