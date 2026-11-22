<?php

declare(strict_types=1);

namespace Logging\Tests;

use DomainException;
use Exception;
use Logging\Tests\Fixtures\BroadMarker;
use Logging\Tests\Fixtures\MarkedException;
use Logging\Tests\Fixtures\NarrowMarker;
use LogicException;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Throwable;

class ClassResolutionTest extends TestCase
{
    #[Test]
    public function an_interface_entry_is_matched_regardless_of_linkage_order(): void
    {
        // class_implements() lists Throwable before NarrowMarker, so only config order may decide
        // between these unrelated entries.
        config([
            'reporting.window'  => 300,
            'reporting.classes' => [
                NarrowMarker::class => ['limit' => 1],
                Throwable::class    => ['limit' => 20],
            ],
        ]);
        $this->captureReports();

        $this->reportTimes(MarkedException::class, 5);

        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function the_nearest_parent_wins_over_a_grandparent(): void
    {
        config([
            'reporting.window'  => 300,
            'reporting.classes' => [
                Exception::class      => ['limit' => 5],
                LogicException::class => ['limit' => 1],
            ],
        ]);
        $this->captureReports();

        $this->reportTimes(DomainException::class, 5);

        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function a_class_entry_beats_an_interface_entry(): void
    {
        config([
            'reporting.window'  => 300,
            'reporting.classes' => [
                Throwable::class        => ['limit' => 1],
                RuntimeException::class => ['limit' => 3],
            ],
        ]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 5);

        $this->assertCount(3, $this->reported);
    }

    #[Test]
    public function a_catch_all_written_first_swallows_a_narrower_entry(): void
    {
        config([
            'reporting.window'  => 300,
            'reporting.classes' => [
                Throwable::class    => ['limit' => 3],
                NarrowMarker::class => ['limit' => 1],
            ],
        ]);
        $this->captureReports();

        $this->reportTimes(MarkedException::class, 5);

        $this->assertCount(3, $this->reported);
    }

    #[Test]
    public function a_narrow_interface_beats_the_broad_one_it_extends(): void
    {
        // Written broad-first on purpose.
        config([
            'reporting.window'  => 300,
            'reporting.classes' => [
                BroadMarker::class  => ['limit' => 5],
                NarrowMarker::class => ['limit' => 1],
            ],
        ]);
        $this->captureReports();

        $this->reportTimes(MarkedException::class, 5);

        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function a_per_class_window_overrides_the_default_window(): void
    {
        // A zero default window means no limit, so only the per-class window can throttle.
        config([
            'reporting.limit'   => 10,
            'reporting.window'  => 0,
            'reporting.classes' => [RuntimeException::class => ['limit' => 1, 'window' => 600]],
        ]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 4);

        $this->assertCount(1, $this->reported);
    }

    #[Test]
    public function a_zero_limit_fails_open(): void
    {
        config(['reporting.limit' => 0, 'reporting.window' => 300]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 4);

        $this->assertCount(4, $this->reported);
    }

    #[Test]
    public function a_zero_window_fails_open(): void
    {
        config(['reporting.limit' => 2, 'reporting.window' => 0]);
        $this->captureReports();

        $this->reportTimes(RuntimeException::class, 4);

        $this->assertCount(4, $this->reported);
    }
}
