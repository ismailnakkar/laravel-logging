<?php

declare(strict_types=1);

namespace Logging\Tests;

use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/** Runs only in the CI job that removes laravel/pulse. */
class WithoutPulseTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (self::hasPulse()) {
            $this->markTestSkipped('laravel/pulse is installed.');
        }
    }

    #[Test]
    public function the_throttle_works_with_no_pulse_installed(): void
    {
        config(['reporting.limit' => 1, 'reporting.window' => 300]);
        $this->captureReports();

        foreach (range(1, 3) as $i) {
            report(new RuntimeException("boom {$i}"));
        }

        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function about_reports_the_counter_as_off(): void
    {
        $output = $this->aboutOutput();

        $this->assertStringContainsString('Pulse counter', $output);
        $this->assertStringNotContainsString('counts in front of the gate', $output);
    }
}
