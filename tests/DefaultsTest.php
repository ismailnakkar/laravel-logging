<?php

declare(strict_types=1);

namespace Logging\Tests;

use Illuminate\Support\Facades\File;
use Logging\Budget;
use Logging\LoggingServiceProvider;
use Logging\Redaction\Redactor;
use Logging\ThrottleConfig;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

/** Sets no config, like an app that never published it. */
class DefaultsTest extends TestCase
{
    #[Test]
    public function the_shipped_defaults_apply_without_publishing_the_config(): void
    {
        $this->captureReports();

        foreach (range(1, 15) as $i) {
            report(new RuntimeException("boom {$i}"));
        }

        $this->assertCount(10, $this->reported);
    }

    #[Test]
    public function the_shipped_window_is_read_from_the_package_config(): void
    {
        $this->assertEquals(
            Budget::of(10, 300),
            app(ThrottleConfig::class)->for(new RuntimeException('boom')),
        );
    }

    #[Test]
    public function an_absent_config_key_falls_back_to_the_shipped_budget(): void
    {
        // A config cache from before install skips mergeConfigFrom(), so every key is absent.
        config(['reporting' => null]);

        $this->assertEquals(
            Budget::of(10, 300),
            app(ThrottleConfig::class)->for(new RuntimeException('boom')),
        );

        $this->assertTrue(app(ThrottleConfig::class)->throttles());
    }

    #[Test]
    public function an_absent_redaction_key_still_redacts_by_the_shipped_rules(): void
    {
        config(['reporting' => null]);

        $this->assertSame(
            'https://example.test/reset-password/[redacted]?email=[redacted]',
            Redactor::fromConfig()->text('https://example.test/reset-password/abc?email=member%40example.com'),
        );
    }

    #[Test]
    public function the_config_file_ships_the_default_rules(): void
    {
        $this->assertSame(Redactor::DEFAULT_KEYS, config('reporting.redact'));
        $this->assertSame(Redactor::DEFAULT_PATHS, config('reporting.redact_paths'));
    }

    #[Test]
    public function artisan_about_reports_the_budget_that_is_actually_in_force(): void
    {
        config(['reporting' => null]);

        $output = $this->aboutOutput();

        $this->assertStringContainsString('10 per 300s', $output);
        $this->assertStringContainsString('ENABLED', $output);
        $this->assertMatchesRegularExpression('/Redacted keys\W+' . count(Redactor::DEFAULT_KEYS) . '/', $output);
    }

    #[Test]
    public function artisan_about_calls_a_sub_one_budget_unlimited(): void
    {
        // An empty REPORTING_LIMIT reads as 0, which reports everything.
        config(['reporting.limit' => 0]);

        $this->assertStringContainsString('unlimited', $this->aboutOutput());
    }

    #[Test]
    public function auto_discovery_points_at_a_provider_that_exists(): void
    {
        // Testbench registers the provider by hand, so discovery is checked here.
        $composer = File::json(__DIR__ . '/../composer.json', JSON_THROW_ON_ERROR);

        $this->assertSame(
            [LoggingServiceProvider::class],
            $composer['extra']['laravel']['providers'] ?? null,
        );
    }
}
