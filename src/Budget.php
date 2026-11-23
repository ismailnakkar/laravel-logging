<?php

declare(strict_types=1);

namespace Logging;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Queue\MaxAttemptsExceededException;
use League\Flysystem\FilesystemException;
use Psr\Http\Client\NetworkExceptionInterface;
use RedisException;
use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Throwable;

/** $times per $seconds. Below 1 on either side means no limit, not never: an empty env var reads as 0. */
final readonly class Budget
{
    private function __construct(public int $times, public int $seconds) {}

    public static function of(int $times, int $seconds): self
    {
        return new self($times, $seconds);
    }

    public static function perMinute(int $times = 1): self
    {
        return new self($times, 60);
    }

    public static function perHour(int $times = 1): self
    {
        return new self($times, 3600);
    }

    public static function perDay(int $times = 1): self
    {
        return new self($times, 86400);
    }

    public static function unlimited(): self
    {
        return new self(0, 0);
    }

    /**
     * Budgets for what a failing dependency throws on every request or job, keyed by class, for the
     * `classes` list: spread them first and add or override entries after. A class whose package or
     * extension is missing (phpredis) is left out, as `classes` refuses one.
     *
     * @return array<class-string<Throwable>, self>
     */
    public static function outages(): array
    {
        $budgets = [
            // The database being down means every request throws.
            QueryException::class => self::of(3, 600),

            // Redis also backs cache.limiter: this caps a blip, but in a full outage the gate cannot
            // count and reports every one.
            RedisException::class => self::of(3, 600),

            // Outbound HTTP: Http:: wraps a transfer failure as ConnectionException; a client called
            // directly (an SDK or Socialite on Guzzle 7 or 8, any PSR-18 client) throws the interface.
            ConnectionException::class       => self::of(5, 600),
            NetworkExceptionInterface::class => self::of(5, 600),

            // Every Symfony mailer transport.
            TransportExceptionInterface::class => self::of(3, 900),

            // Every Flysystem disk.
            FilesystemException::class => self::of(5, 600),

            // One poison job retried across every worker reports on each attempt.
            MaxAttemptsExceededException::class => self::of(5, 900),
        ];

        return array_filter(
            $budgets,
            static fn (string $class): bool => class_exists($class) || interface_exists($class),
            ARRAY_FILTER_USE_KEY,
        );
    }

    /**
     * Lets a Budget in config survive `config:cache`, which var_export()s it.
     *
     * @param  array{times: int, seconds: int}  $state
     */
    public static function __set_state(array $state): self
    {
        return new self($state['times'], $state['seconds']);
    }

    public function isUnlimited(): bool
    {
        return $this->times < 1 || $this->seconds < 1;
    }

    /** @param  string|null  $key  The bucket; null leaves it to the handler, one per concrete class. */
    public function toLimit(?string $key = null): Limit
    {
        return $this->isUnlimited()
            ? Limit::none()
            : new Limit(key: $key ?? '', maxAttempts: $this->times, decaySeconds: $this->seconds);
    }
}
