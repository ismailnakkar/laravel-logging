<?php

declare(strict_types=1);

namespace Logging\Tests;

use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Events\LongWaitDetected;
use Logging\LogThrottle;
use PHPUnit\Framework\Attributes\Test;

class LongWaitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! class_exists(LongWaitDetected::class)) {
            $this->markTestSkipped('laravel/horizon is not installed.');
        }
    }

    #[Test]
    public function it_stays_off_until_long_wait_is_set(): void
    {
        $log = Log::spy();

        event(new LongWaitDetected('redis', 'default', 75));
        event(new LongWaitDetected('redis', 'default', 135));

        $log->shouldNotHaveReceived('error');
    }

    #[Test]
    public function a_backlog_logs_one_error_per_queue_per_interval(): void
    {
        config(['reporting.long_wait' => 3600]);
        $log = Log::spy();

        event(new LongWaitDetected('redis', 'default', 75));
        event(new LongWaitDetected('redis', 'default', 135));
        event(new LongWaitDetected('redis', 'mail', 90));

        $log->shouldHaveReceived('error')->twice();
        $log->shouldHaveReceived('error')->with('Queue wait is above its Horizon threshold', [
            'connection' => 'redis',
            'queue'      => 'default',
            'seconds'    => 75,
        ])->once();
        $log->shouldHaveReceived('error')->with('Queue wait is above its Horizon threshold', [
            'connection' => 'redis',
            'queue'      => 'mail',
            'seconds'    => 90,
        ])->once();
    }

    #[Test]
    public function a_queue_still_backed_up_after_the_interval_is_reported_again(): void
    {
        config(['reporting.long_wait' => 3600]);
        $log = Log::spy();

        event(new LongWaitDetected('redis', 'visits', 75));
        $this->travel(3599)->seconds();
        event(new LongWaitDetected('redis', 'visits', 3674));
        $log->shouldHaveReceived('error')->once();

        $this->travel(1)->seconds();
        event(new LongWaitDetected('redis', 'visits', 3675));
        $log->shouldHaveReceived('error')->twice();
    }

    #[Test]
    public function a_window_the_apps_own_listener_opened_carries_over(): void
    {
        // What the apps' copies called before the package took the alert over: a deploy keeps their open windows.
        LogThrottle::once('horizon:long-wait:redis:default', 3600);

        config(['reporting.long_wait' => 3600]);
        $log = Log::spy();

        event(new LongWaitDetected('redis', 'default', 75));

        $log->shouldNotHaveReceived('error');
    }
}
