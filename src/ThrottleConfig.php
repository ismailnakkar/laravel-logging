<?php

declare(strict_types=1);

namespace FloodControl;

use Illuminate\Contracts\Config\Repository;
use Throwable;

/**
 * @internal
 *
 * Reads the throttle keys (enabled, limit, window, classes) per call, and resolves the budget for
 * one throwable.
 */
final readonly class ThrottleConfig
{
    /** Both mirror config/flood-control.php, so a stale config cache without the keys still throttles. */
    private const DEFAULT_LIMIT = 10;

    private const DEFAULT_WINDOW = 300;

    public function __construct(private Repository $config) {}

    public function enabled(): bool
    {
        return (bool)$this->config->get('flood-control.enabled', true);
    }

    /** The top-level limit and window. */
    public function default(): Budget
    {
        return Budget::of(
            (int)$this->config->get('flood-control.limit', self::DEFAULT_LIMIT),
            (int)$this->config->get('flood-control.window', self::DEFAULT_WINDOW),
        );
    }

    /** @return array<array-key, mixed> The `classes` entries, unvalidated. */
    public function classes(): array
    {
        return (array)$this->config->get('flood-control.classes', []);
    }

    /** The most specific `classes` entry $e matches, or the default. */
    public function for(Throwable $e): Budget
    {
        $classes = $this->classes();
        $match = self::mostSpecific($e, array_keys($classes));

        if ($match === null) {
            return $this->default();
        }

        $entry = $classes[$match];

        return $entry instanceof Budget ? $entry : $this->fromEntry((array)$entry);
    }

    /** @param  array<array-key, mixed>  $entry  A `classes` entry; absent keys fall back to the default. */
    private function fromEntry(array $entry): Budget
    {
        $default = $this->default();

        return Budget::of(
            (int)($entry['limit'] ?? $default->times),
            (int)($entry['window'] ?? $default->seconds),
        );
    }

    /**
     * The match no other match is a subtype of; unrelated matches tie-break on config order.
     *
     * @param  list<array-key>  $candidates
     */
    private static function mostSpecific(Throwable $e, array $candidates): ?string
    {
        $matching = [];

        foreach ($candidates as $candidate) {
            if (is_string($candidate) && $e instanceof $candidate) {
                $matching[] = $candidate;
            }
        }

        foreach ($matching as $candidate) {
            foreach ($matching as $other) {
                if ($other !== $candidate && is_a($other, $candidate, true)) {
                    continue 2;
                }
            }

            return $candidate;
        }

        return null;
    }
}
