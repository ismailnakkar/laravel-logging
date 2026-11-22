<?php

declare(strict_types=1);

namespace Logging\Tests;

use Laravel\Pulse\Events\ExceptionReported;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class PulseDisabledTest extends TestCase
{
    protected function setUp(): void
    {
        // Through the env, so the REPORTING_PULSE wiring in the config file is covered too.
        putenv('REPORTING_PULSE=false');

        parent::setUp();

        $this->skipWithoutPulse();
    }

    protected function tearDown(): void
    {
        putenv('REPORTING_PULSE');

        parent::tearDown();
    }

    #[Test]
    public function pulse_report_is_still_counted(): void
    {
        $counted = $this->pulseExceptions(
            fn () => event(new ExceptionReported(new RuntimeException('boom'))),
        );

        $this->assertCount(1, $counted);
    }

    #[Test]
    public function switching_it_on_after_boot_counts_in_front_of_the_gate(): void
    {
        config(['reporting.pulse' => true, 'reporting.limit' => 1, 'reporting.window' => 300]);
        $this->captureReports();

        $counted = $this->pulseExceptions(function (): void {
            foreach (range(1, 4) as $i) {
                report(new RuntimeException("boom {$i}"));
            }
        });

        $this->assertCount(1, $this->reported);
        $this->assertCount(4, $counted);
    }

    #[Test]
    public function only_survivors_are_counted(): void
    {
        config(['reporting.limit' => 1, 'reporting.window' => 300]);
        $this->captureReports();

        $counted = $this->pulseExceptions(function (): void {
            foreach (range(1, 4) as $i) {
                report(new RuntimeException("boom {$i}"));
            }
        });

        $this->assertCount(1, $this->reported);
        $this->assertCount(1, $counted);
    }
}
