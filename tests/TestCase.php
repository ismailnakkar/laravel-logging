<?php

declare(strict_types=1);

namespace Logging\Tests;

use Illuminate\Cache\RateLimiter;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Laravel\Pulse\Contracts\Ingest;
use Laravel\Pulse\Entry;
use Laravel\Pulse\Facades\Pulse;
use Laravel\Pulse\PulseServiceProvider;
use Logging\LoggingServiceProvider;
use Logging\Tests\Fixtures\CapturingIngest;
use Orchestra\Testbench\TestCase as Orchestra;
use RuntimeException;
use Sentry\Laravel\ServiceProvider as SentryServiceProvider;
use Throwable;

abstract class TestCase extends Orchestra
{
    /** @var list<Throwable> */
    protected array $reported = [];

    protected function getPackageProviders($app): array
    {
        // CI also runs the suite without laravel/pulse.
        return array_values(array_filter([
            self::hasPulse() ? PulseServiceProvider::class : null,
            LoggingServiceProvider::class,
        ]));
    }

    protected static function hasPulse(): bool
    {
        return class_exists(PulseServiceProvider::class);
    }

    protected function skipWithoutPulse(): void
    {
        if (! self::hasPulse()) {
            $this->markTestSkipped('laravel/pulse is not installed.');
        }
    }

    /** CI also runs the suite without sentry/sentry-laravel. */
    protected static function hasSentry(): bool
    {
        return class_exists(SentryServiceProvider::class);
    }

    /** Records what passed the gate; returning false skips the handler's log write. */
    protected function captureReports(): void
    {
        $this->reported = [];

        app(ExceptionHandler::class)->reportable(function (Throwable $e): bool {
            $this->reported[] = $e;

            return false;
        });
    }

    /** @param  class-string<Throwable>  $class */
    protected function reportTimes(string $class, int $times): void
    {
        foreach (range(1, $times) as $i) {
            report(new $class("boom {$i}"));
        }
    }

    /** As a store outage would: every attempt throws. */
    protected function breakRateLimiter(): void
    {
        $limiter = $this->createStub(RateLimiter::class);
        $limiter->method('attempt')->willThrowException(new RuntimeException('redis is down'));

        $this->app->instance(RateLimiter::class, $limiter);
    }

    /** `about` writes through its own formatter, which expectsOutputToContain() does not see. */
    protected function aboutOutput(): string
    {
        $this->withoutMockingConsoleOutput();
        $this->artisan('about --only=reporting');

        return $this->app[Kernel::class]->output();
    }

    /**
     * Runs $work and flushes Pulse into a capturing ingest, with no database.
     *
     * @return list<string> keys of the `exception` entries Pulse recorded
     */
    protected function pulseExceptions(callable $work): array
    {
        $this->app->instance(Ingest::class, $ingest = new CapturingIngest);

        $work();

        Pulse::ingest();

        return array_values(array_map(
            static fn (Entry $entry): string => $entry->key,
            array_filter($ingest->entries, static fn (Entry $entry): bool => $entry->type === 'exception'),
        ));
    }
}
