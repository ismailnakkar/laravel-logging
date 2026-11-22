<?php

declare(strict_types=1);

namespace Logging;

use Illuminate\Container\Container;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Foundation\Console\AboutCommand;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;
use Laravel\Pulse\Events\ExceptionReported;
use Laravel\Pulse\Recorders\Exceptions as PulseExceptions;
use Logging\Redaction\Driver;
use Logging\Redaction\Redactor;
use Logging\Redaction\SentryDriver;
use ReflectionObject;
use Throwable;
use WeakMap;

/** @internal */
final class LoggingServiceProvider extends ServiceProvider
{
    /** How many handlers unwrap() checks, the outermost included, before it gives up. */
    private const MAX_HANDLER_DEPTH = 8;

    private const BUDGET_KEYS = ['limit', 'window'];

    /**
     * One per error tracker; each wires itself only when its tracker is installed.
     *
     * @var list<class-string<Driver>>
     */
    private const DRIVERS = [SentryDriver::class];

    /** The redaction lists: each takes non-empty strings only. */
    private const REDACTION_KEYS = ['redact', 'redact_paths'];

    private bool $wrapsPulse = false;

    private bool $countersRunning = false;

    /** @var WeakMap<object, true> */
    private WeakMap $wired;

    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/reporting.php', 'reporting');

        $this->wired = new WeakMap;

        $this->app->singleton(ThrottleConfig::class);

        // Must land before Pulse boots and resolves its recorders, or Pulse keeps its stock one.
        // No config check: `reporting.pulse` is read per exception.
        $this->wrapsPulse = class_exists(PulseExceptions::class) && ! $this->app->isBooted();

        if ($this->wrapsPulse) {
            $this->app->bind(PulseExceptions::class, static function (Container $app): PulseExceptionRecorder {
                // build(), not make(): make() would resolve this binding again.
                return new PulseExceptionRecorder($app->build(PulseExceptions::class));
            });
        }

        // No config check: the rules are read per event.
        foreach (self::DRIVERS as $driver) {
            (new $driver)->register($this->app);
        }
    }

    public function boot(Dispatcher $events): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../config/reporting.php' => config_path('reporting.php'),
            ], 'reporting-config');

            $this->registerAboutCommandIntegration();
        }

        // Skipped once cached: config:cache boots the app uncached, so the checks already ran there.
        if (! $this->app->configurationIsCached()) {
            $this->validateClassBudgets();
            $this->validateCounters();
            $this->validateRedaction();
        }

        if ($this->wrapsPulse) {
            $events->listen(ExceptionReported::class, PulseReportListener::class);
        }

        // An app's withExceptions() callbacks are added during bootstrap, so they run ahead of these.
        $this->callAfterResolving(ExceptionHandler::class, $this->wire(...));
    }

    private function wire(ExceptionHandler $resolved): void
    {
        $handler = self::unwrap($resolved);
        $target = $handler ?? $resolved;

        // A decorated binding unwraps to the same handler; wiring it twice counts everything twice.
        if (isset($this->wired[$target])) {
            return;
        }

        $this->wired[$target] = true;

        if ($handler === null) {
            $this->countPulseFromAReportable($resolved);

            return;
        }

        // Counted on dontReportWhen(), ahead of the throttle chain: Handler::throttle() stops at the
        // first non-null return, so an app's own throttle callback would starve counters there.
        // These return null, which suppresses nothing.
        if ($this->wrapsPulse) {
            $handler->dontReportWhen(PulseExceptionRecorder::count(...));
            $handler->reportable(PulseExceptionRecorder::countSurvivor(...));
        }

        // Always: the list is read per exception, so counters configured later still run.
        $handler->dontReportWhen($this->runCounters(...));

        $handler->throttleUsing(ExceptionThrottle::for(...));
    }

    private function runCounters(Throwable $e): null
    {
        // A counter that reports re-enters here, and the gate runs too late to stop it.
        if ($this->countersRunning) {
            return null;
        }

        $this->countersRunning = true;

        try {
            foreach ($this->counters() as $counter) {
                try {
                    $this->app->make($counter)($e);
                } catch (Throwable) {
                    // dontReportWhen() callbacks run outside the handler's rescue(): a throw breaks report().
                }
            }
        } finally {
            $this->countersRunning = false;
        }

        return null;
    }

    /** @return list<mixed> */
    private function counters(): array
    {
        return array_values((array)$this->app->make('config')->get('reporting.counters', []));
    }

    /**
     * throttleUsing() is not on the contract, so a decorator hides it (Collision wraps the binding on
     * every console run). Walk to the handler that has it.
     */
    private static function unwrap(?object $handler): ?object
    {
        for ($depth = 0; $depth < self::MAX_HANDLER_DEPTH; $depth++) {
            if ($handler === null || method_exists($handler, 'throttleUsing')) {
                return $handler;
            }

            $handler = self::inner($handler);
        }

        return null;
    }

    private static function inner(object $handler): ?object
    {
        foreach ((new ReflectionObject($handler))->getProperties() as $property) {
            try {
                if ($property->isStatic() || ! $property->isInitialized($handler)) {
                    continue;
                }

                $value = $property->getValue($handler);
            } catch (Throwable) {
                // Reading a hooked or lazily-initialised property runs app code.
                continue;
            }

            if ($value instanceof ExceptionHandler && $value !== $handler) {
                return $value;
            }
        }

        return null;
    }

    /** Nothing is gated, so count where Pulse would: in a reportable. */
    private function countPulseFromAReportable(object $handler): void
    {
        if ($this->wrapsPulse && method_exists($handler, 'reportable')) {
            $handler->reportable(PulseExceptionRecorder::countAlways(...));
        }
    }

    /**
     * A typo'd class or key would otherwise fall back to the default budget silently. Unshared: the
     * singleton resolved at boot would keep the boot-time config past Octane's per-request copy.
     */
    private function validateClassBudgets(): void
    {
        foreach ((new ThrottleConfig($this->app->make('config')))->classes() as $class => $budget) {
            if (! self::isClassOrInterface($class)) {
                throw new InvalidArgumentException(
                    "reporting.classes: [{$class}] is not a class or interface that exists.",
                );
            }

            if ($budget instanceof Budget) {
                continue;
            }

            if (! is_array($budget) || $budget === []) {
                throw new InvalidArgumentException(
                    "reporting.classes.[{$class}]: expected ['limit' => ?int, 'window' => ?int].",
                );
            }

            foreach (self::BUDGET_KEYS as $key) {
                if (array_key_exists($key, $budget) && ! is_int($budget[$key])) {
                    throw new InvalidArgumentException(
                        "reporting.classes.[{$class}]: '{$key}' must be an int.",
                    );
                }
            }

            if ($unknown = array_diff(array_keys($budget), self::BUDGET_KEYS)) {
                throw new InvalidArgumentException(
                    "reporting.classes.[{$class}]: unknown key(s) " . implode(', ', $unknown) . '.',
                );
            }
        }
    }

    /** A typo'd counter would otherwise silently never run. */
    private function validateCounters(): void
    {
        foreach ($this->counters() as $counter) {
            // Interfaces too: a counter can be a contract the app binds.
            if (! self::isClassOrInterface($counter)) {
                $name = is_string($counter) ? $counter : get_debug_type($counter);

                throw new InvalidArgumentException("reporting.counters: [{$name}] is not a class or interface that exists.");
            }

            if (! method_exists($counter, '__invoke')) {
                throw new InvalidArgumentException("reporting.counters: [{$counter}] has no __invoke(Throwable \$e).");
            }
        }
    }

    /** A malformed entry would otherwise be skipped silently, leaving its secret in the logs. */
    private function validateRedaction(): void
    {
        $config = $this->app->make('config');

        foreach (self::REDACTION_KEYS as $key) {
            $list = $config->get("reporting.{$key}", []);

            if (! is_array($list)) {
                throw new InvalidArgumentException("reporting.{$key}: expected a list of names.");
            }

            foreach ($list as $entry) {
                if (! is_string($entry) || trim($entry, '/ ') === '') {
                    $name = is_string($entry) ? "'{$entry}'" : get_debug_type($entry);

                    throw new InvalidArgumentException("reporting.{$key}: [{$name}] is not a non-empty name.");
                }
            }
        }
    }

    /** @phpstan-assert-if-true class-string $name */
    private static function isClassOrInterface(mixed $name): bool
    {
        return is_string($name) && (class_exists($name) || interface_exists($name));
    }

    private function registerAboutCommandIntegration(): void
    {
        AboutCommand::add('Reporting', function (): array {
            $config = $this->app->make(ThrottleConfig::class);
            $budget = $config->default();

            return [
                'Exception budget'  => $budget->isUnlimited() ? 'unlimited' : sprintf('%d per %ds', $budget->times, $budget->seconds),
                'Per class budgets' => (string)count($config->classes()),
                'Throttle'          => AboutCommand::format(
                    $config->throttles(),
                    console: fn (bool $on): string => $on ? '<fg=yellow;options=bold>ENABLED</>' : 'OFF',
                ),
                'Redacted keys' => (string)count((array)$this->app->make('config')->get('reporting.redact', Redactor::DEFAULT_KEYS)),
                'Pulse counter' => AboutCommand::format(
                    $this->wrapsPulse && PulseExceptionRecorder::countsInFront(),
                    console: fn (bool $on): string => $on ? '<fg=yellow;options=bold>ON</> (counts in front of the gate)' : 'OFF',
                ),
            ];
        });
    }
}
