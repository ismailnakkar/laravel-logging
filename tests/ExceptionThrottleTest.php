<?php

declare(strict_types=1);

namespace Logging\Tests;

use DomainException;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

class ExceptionThrottleTest extends TestCase
{
    #[Test]
    public function it_registers_itself_without_a_bootstrap_edit(): void
    {
        config(['reporting.limit' => 2, 'reporting.window' => 300]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 5);

        $this->assertCount(2, $this->reported);
    }

    #[Test]
    public function the_budget_refills_when_the_window_ends(): void
    {
        config(['reporting.limit' => 1, 'reporting.window' => 300]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 3);
        $this->travel(301)->seconds();
        $this->reportTimes(RuntimeException::class, 3);

        $this->assertCount(2, $this->reported);
    }

    #[Test]
    public function each_exception_class_gets_its_own_budget(): void
    {
        config(['reporting.limit' => 1, 'reporting.window' => 300]);
        $this->captureReports();

        report(new RuntimeException('first'));
        report(new RuntimeException('second'));
        report(new LogicException('other class'));

        $this->assertSame(
            [RuntimeException::class, LogicException::class],
            array_map(fn (Throwable $e): string => $e::class, $this->reported),
        );
    }

    #[Test]
    public function a_per_class_budget_overrides_the_default(): void
    {
        config([
            'reporting.limit'   => 10,
            'reporting.window'  => 300,
            'reporting.classes' => [RuntimeException::class => ['limit' => 1, 'window' => 300]],
        ]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 3);
        $this->reportTimes(LogicException::class, 3);

        $this->assertCount(4, $this->reported);
    }

    #[Test]
    public function a_per_class_budget_falls_back_to_the_default_window(): void
    {
        config([
            'reporting.limit'   => 10,
            'reporting.window'  => 300,
            'reporting.classes' => [RuntimeException::class => ['limit' => 1]],
        ]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 3);

        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function a_parent_class_entry_covers_its_subclasses(): void
    {
        config([
            'reporting.limit'   => 10,
            'reporting.window'  => 300,
            'reporting.classes' => [LogicException::class => ['limit' => 1]],
        ]);
        $this->captureReports();

        // Each subclass keys its own bucket, but both inherit the limit of 1.
        $this->reportTimes(DomainException::class, 3);
        $this->reportTimes(InvalidArgumentException::class, 3);

        $this->assertCount(2, $this->reported);
    }

    #[Test]
    public function an_interface_entry_covers_everything_under_it(): void
    {
        config([
            'reporting.limit'   => 10,
            'reporting.window'  => 300,
            'reporting.classes' => [Throwable::class => ['limit' => 1]],
        ]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 3);

        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function the_most_specific_entry_wins(): void
    {
        config([
            'reporting.limit'   => 1,
            'reporting.window'  => 300,
            'reporting.classes' => [
                Throwable::class       => ['limit' => 1],
                LogicException::class  => ['limit' => 2],
                DomainException::class => ['limit' => 3],
            ],
        ]);
        $this->captureReports();

        $this->reportTimes(DomainException::class, 5);

        $this->assertCount(3, $this->reported);
    }

    #[Test]
    public function disabling_the_package_stops_it_throttling_anything(): void
    {
        config(['reporting.throttle' => false, 'reporting.limit' => 1]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 4);

        $this->assertCount(4, $this->reported);
    }

    #[Test]
    public function a_missing_budget_fails_open_rather_than_silencing_everything(): void
    {
        config(['reporting.limit' => 0, 'reporting.window' => 0]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 4);

        $this->assertCount(4, $this->reported);
    }
}
