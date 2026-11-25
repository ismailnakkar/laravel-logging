<?php

declare(strict_types=1);

namespace Logging\Tests;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Contracts\Debug\ExceptionHandler;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

/** App throttle callbacks run first, and Handler::throttle() stops at the first non-null return. */
class CallbackOrderTest extends TestCase
{
    /** @var list<class-string> */
    private static array $counted = [];

    private ?Limit $appLimit = null;

    protected function defineEnvironment($app): void
    {
        self::$counted = [];

        $app['config']->set('reporting.limit', 1);
        $app['config']->set('reporting.window', 300);

        $limit = $this->appLimit;

        // Registered before the package's provider boots, as withExceptions() would be.
        $app->afterResolving(ExceptionHandler::class, function ($handler) use ($limit): void {
            $handler->throttleUsing(function (Throwable $e) use ($limit): ?Limit {
                self::$counted[] = $e::class;

                return $limit;
            });
        });
    }

    #[Test]
    public function a_counter_registered_first_sees_every_exception(): void
    {
        $this->captureReports();

        foreach (range(1, 3) as $ignored) {
            report(new RuntimeException('boom'));
        }

        $this->assertCount(3, self::$counted, 'the counter must see every exception');
        $this->assertCount(1, $this->reported, 'and the package must still gate behind it');
    }

    #[Test]
    public function an_app_that_overrides_the_gate_does_not_starve_pulse(): void
    {
        $this->skipWithoutPulse();

        $this->appLimit = Limit::perMinute(3);
        $this->refreshApplication();
        $this->captureReports();

        $counted = $this->pulseExceptions(function (): void {
            foreach (range(1, 5) as $i) {
                report(new RuntimeException("boom {$i}"));
            }
        });

        $this->assertCount(5, $counted, 'Pulse must still see everything');
        $this->assertCount(3, $this->reported, "and the app's own limit must still win");
    }
}
