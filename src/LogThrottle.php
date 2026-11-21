<?php

declare(strict_types=1);

namespace FloodControl;

use BackedEnum;
use Closure;
use Illuminate\Cache\NullStore;
use Illuminate\Cache\RateLimiter;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Monolog\Handler\HandlerInterface;
use Monolog\Logger as Monolog;
use Psr\Log\LoggerInterface;
use Throwable;
use UnitEnum;

/**
 * Throttled log lines. Keep keys code-owned: a key built from request data is unbounded cache
 * cardinality.
 */
final class LogThrottle
{
    /** Separate per method: RateLimiter would read once()'s gate as a spent counter and clear it. */
    private const ONCE_PREFIX = 'flood-control:once:';

    private const TIMES_PREFIX = 'flood-control:times:';

    /**
     * The real logger once per $seconds for $key, a discarding one after; a null or sub-1 window
     * never throttles. Exactly once, even across workers: the gate is one Cache::add().
     *
     * @param  LoggerInterface|UnitEnum|array<int, string>|string|null  $channel  An array is an on-demand stack.
     */
    public static function once(
        string $key,
        ?int $seconds,
        LoggerInterface|UnitEnum|array|string|null $channel = null,
    ): LoggerInterface {
        $budget = Budget::of(1, $seconds ?? 0);

        return self::gate($channel, $budget, static function () use ($key, $budget): bool {
            $cache = app('cache')->store(config('cache.limiter'));

            // Repository::add() is always false on a NullStore, which would discard every line.
            return $cache->getStore() instanceof NullStore
                || $cache->add(self::cacheKey(self::ONCE_PREFIX, $key), true, $budget->seconds);
        });
    }

    /**
     * The real logger for the first $budget->times calls per window, a discarding one after. About
     * N: workers racing the counter can let an extra one through.
     *
     * @param  LoggerInterface|UnitEnum|array<int, string>|string|null  $channel  An array is an on-demand stack.
     */
    public static function times(
        string $key,
        Budget $budget,
        LoggerInterface|UnitEnum|array|string|null $channel = null,
    ): LoggerInterface {
        return self::gate($channel, $budget, static fn (): bool => app(RateLimiter::class)->attempt(
            self::cacheKey(self::TIMES_PREFIX, $key),
            $budget->times,
            static fn (): bool => true,
            $budget->seconds,
        ));
    }

    /**
     * As a channel's tap, rate-limits the lines it passes on, per level and call site. Laravel passes
     * `LogThrottle::class.':3,600'` as '3', '600': that channel's own budget. Without one, the
     * top-level limit and window apply, read per line.
     */
    public function __invoke(Logger $logger, string $limit = '', string $window = '', string ...$rest): void
    {
        $budget = match (true) {
            $rest === [] && $limit === '' && $window === ''             => null,
            $rest === [] && ctype_digit($limit) && ctype_digit($window) => Budget::of((int)$limit, (int)$window),
            // A typo must not fall back to the default silently, nor a third argument be dropped.
            default => throw new InvalidArgumentException(sprintf(
                "LogThrottle tap: expected ':limit,window' in whole numbers, got ':%s'.",
                implode(',', [$limit, $window, ...$rest]),
            )),
        };

        $logger->setHandlers(array_map(
            static fn (HandlerInterface $handler): HandlerInterface => new ThrottledHandler($handler, $budget),
            $logger->getHandlers(),
        ));
    }

    /**
     * @param  LoggerInterface|UnitEnum|array<int, string>|string|null  $channel
     * @param  Closure(): bool  $passes  Spends one of $budget; false once it is used up.
     */
    private static function gate(
        LoggerInterface|UnitEnum|array|string|null $channel,
        Budget $budget,
        Closure $passes,
    ): LoggerInterface {
        $logger = self::logger($channel);

        if ($budget->isUnlimited()) {
            return $logger;
        }

        try {
            return $passes() ? $logger : self::discard();
        } catch (Throwable) {
            // Fail open: an outage is when this line matters most.
            return $logger;
        }
    }

    /** @param  LoggerInterface|UnitEnum|array<int, string>|string|null  $channel */
    private static function logger(LoggerInterface|UnitEnum|array|string|null $channel): LoggerInterface
    {
        if ($channel instanceof UnitEnum) {
            // LogManager only resolves enums itself from Laravel 13.3; before that it trims them.
            $channel = $channel instanceof BackedEnum ? (string)$channel->value : $channel->name;
        }

        return match (true) {
            // The manager, not Log::driver(): a bare Log::spy() stubs driver() to return null.
            $channel === null                   => app('log'),
            $channel instanceof LoggerInterface => $channel,
            is_array($channel)                  => Log::stack($channel),
            default                             => Log::channel($channel),
        };
    }

    /**
     * A Logger, not a NullLogger, so withContext() and array messages still work. With no handlers
     * or dispatcher it writes nothing and fires no MessageLogged.
     */
    private static function discard(): LoggerInterface
    {
        return new Logger(new Monolog('flood-control'));
    }

    private static function cacheKey(string $prefix, string $key): string
    {
        // Hashed: memcached rejects long keys, and a rejected add() reads as already logged.
        return $prefix . hash('xxh128', $key);
    }
}
