# laravel-flood-control

Rate-limits reported exceptions and repeated log lines in Laravel, so one failing dependency cannot
flood Sentry, Slack or your logs. Laravel Pulse and your own counters still see every exception.

## Install

```bash
composer require ismailnakkar/laravel-flood-control
php artisan vendor:publish --tag=flood-control-config   # optional
```

Requires PHP 8.2+, Laravel 12.21+ or 13, and Monolog 3. `laravel/pulse` 1.x is optional. The provider
is auto-discovered and wires itself into the exception handler: no `bootstrap/app.php` edit.

Throttle state lives in the `cache.limiter` store (the default store unless set). Use one every worker
and server shares, such as Redis or the database, or each process or server throttles on its own.

## Configuration

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `enabled` | `FLOOD_CONTROL_ENABLED` | `true` | Exception throttling, per-call limits and the [channel tap](#per-channel) included. Counting and `LogThrottle::once()`/`times()` ignore it. |
| `pulse` | `FLOOD_CONTROL_PULSE` | `true` | Pulse counts in front of the gate; `false` counts survivors only. |
| `counters` | | `[]` | Your own counters, see [Counting](#counting). |
| `limit` | `FLOOD_CONTROL_LIMIT` | `10` | Reports per exception class per window. |
| `window` | `FLOOD_CONTROL_WINDOW` | `300` | Window in seconds. |
| `classes` | | `[]` | Per-class budgets, below. |

Anywhere in this package, a limit or window below 1 means no limit, not never. To silence a class, use
`$exceptions->dontReport()`.

### Per-class budgets

```php
'classes' => [
    \Illuminate\Database\QueryException::class => ['limit' => 1, 'window' => 3600],
    \Throwable::class                          => ['limit' => 20],   // catch-all: last
],
```

- An entry covers its subtypes (class or interface) and the most specific one wins. Unrelated entries
  are tried in order, so **put a catch-all last**.
- An entry sets the budget, not the bucket: each concrete class gets its own, so a catch-all of 20
  gives every subclass 20.
- Absent keys fall back to `limit` and `window`. A `FloodControl\Budget` works too
  (`Budget::perHour(3)`); a `Limit` does not survive `config:cache`.
- An unknown class or a malformed entry throws at boot, so `config:cache` fails on it. Guard an
  optional package's exception:

```php
...class_exists(\Aws\Exception\AwsException::class) ? [\Aws\Exception\AwsException::class => ['limit' => 1]] : [],
```

## Exceptions

Nothing to call: `report($e)` and uncaught exceptions are throttled per class. The gate runs in
`shouldntReport()`, ahead of the log write and every `reportable` (Sentry, Flare, …). It fails open:
while the `cache.limiter` store is down every exception is reported, so a budget on the store's own
exception (`RedisException`) caps a blip, not an outage.

### Per call

```php
use FloodControl\Report;
use Illuminate\Cache\RateLimiting\Limit;

Report::exception($e, ['analyzer' => $analyzer::class]);
Report::exception($e, limit: Limit::perHour(1));
Report::exception($e, limit: Limit::perMinute(5)->by("tenant:{$tenant->id}"));
```

- Context is scoped to that report (`Context::scope()`).
- A `limit` caps that one report, also when `$exceptions->map()` wraps the exception as `previous`.
- Without `by()`, a class's per-call limits share one bucket, apart from its plain reports, and the
  first hit sets its window. `by()` names the bucket (per tenant, or across classes).

### Messages

```php
use FloodControl\Budget;
use FloodControl\Report;

Report::error('Stripe webhook signature verification failed', ['gateway' => 'stripe']);
Report::error('Feed fetch failed', ['feed' => $id], previous: $e);
Report::error('Payment circuit breaker opened', budget: Budget::perDay(2));
```

- Reports the message as a `FloodControl\OperationalError`, log line included.
- Bucketed per **call site**, so one noisy caller cannot spend another's budget. `previous` is chained
  (the sink keeps its trace) and does not change the bucket.
- `budget` sets that call site's pace. Without it, the budget for `OperationalError` applies: the
  `classes` entry that matches it, a catch-all included, or the default.
- Subclass `OperationalError` to give a subsystem its own name in the sink, and report it with
  `Report::exception()`: it is then bucketed per class.

## Log lines

```php
use FloodControl\Budget;
use FloodControl\LogThrottle;

LogThrottle::once('origin-denied', 60)->warning('Origin not allowed', ['origin' => $origin]);
LogThrottle::once('feed-stale', 900, ['single', 'slack'])->warning('Feed is stale');
LogThrottle::times('shadow-source-failed', Budget::of(3, 300))->warning('Shadow source failed');
```

- `once()` returns the real logger **exactly once** per window, even across workers (one
  `Cache::add()`). `times()` allows **about** N: workers racing its counter can let an extra one
  through. Prefer `once()` unless you want several samples.
- Past the budget they return a logger that discards: every level, `withContext()` and array messages
  work, and no `MessageLogged` fires (nothing reaches Telescope or Sentry breadcrumbs).
- The last argument picks the logger: a channel name or enum, an array of channels (an on-demand
  stack), a `LoggerInterface`, or `null` for the default channel.
- A `null` window never throttles, a cache failure lets the line through, and no `flood-control`
  config applies.
- Keep keys code-owned: a key built from request data is unbounded cache cardinality.

### Per channel

Plain log lines a channel sends to a sink never pass the exception gate. Tap the channel to
rate-limit what it passes on:

```php
// config/logging.php
'sentry' => [/* … */, 'tap' => [\FloodControl\LogThrottle::class]],            // `limit` per `window`
'slack'  => [/* … */, 'tap' => [\FloodControl\LogThrottle::class . ':3,600']],  // its own: 3 per 600s
```

- Each level and call site (file and line) gets its own bucket, not each message: placeholders are
  filled before any handler runs, so one site's messages all differ. Two tapped channels whose
  handlers share a class (two Slack webhooks) share those buckets.
- Malformed tap arguments throw, and Laravel then writes to its emergency log instead of that channel.
- Lines below the channel's level cost nothing. Records with an `exception` pass uncounted: every
  reported exception is logged that way, and the gate already limited it.
- A dropped line still reaches the other channels of a stack, and still fires `MessageLogged`, so it
  still leaves a Sentry breadcrumb. A line `LogThrottle::once()` lets through is counted again here.
- Laravel's `info('…')` and `logger('…')` helpers are one call site each, whoever calls them; `Log::`
  calls are not.
- `enabled` switches it off, and a cache outage fails open.

## Counting

A `reportable` that counts sees only survivors. Count in front of the gate instead:

- **Pulse**, when installed: the package binds Pulse's exceptions recorder to one that counts in front
  of the gate and delegates recording, so Pulse's settings, off switches and `Pulse::report()` work
  as before. `php artisan about` shows which way it counts.
- **Your counters**: invokable classes in `counters`, resolved from the container and called with
  every reported exception, throttled or not.

```php
'counters' => [\App\Reporting\CountExceptions::class],
```

```php
class CountExceptions
{
    public function __invoke(Throwable $e): void
    {
        Metrics::increment('exceptions', $e::class);
    }
}
```

A counter's throws are swallowed, a report made inside a counter is not counted again, and an unknown
or non-invokable class throws at boot. Counting runs on `dontReportWhen()`, ahead of the throttle
chain, so an `$exceptions->throttle()` of your own that returns a `Limit` replaces the gate without
hiding anything from counters.

## Sinks

Sentry, Flare, Bugsnag and Rollbar register `reportable`s, so they already sit behind the gate. A
sink's **log channel** in `LOG_STACK` also takes every plain log line at its level (`debug` up without
a `level`), and wired beside the reportable it gets each exception twice. Prefer the reportable; to
keep the channel for plain log lines, give it a level, keep it off exceptions and
[tap it](#per-channel):

```php
'sentry' => [
    'driver'            => 'sentry',
    'level'             => env('SENTRY_CHANNEL_LEVEL', 'error'),
    'report_exceptions' => false,
    'tap'               => [\FloodControl\LogThrottle::class],
],
```

`report_exceptions => false` drops any record with an `exception` key, so `Log::error($msg,
['exception' => $e])` stops reaching the sink: use `Report::exception()` or `Report::error()`. Their
context is Laravel `Context`, which the sink sends with the event (Sentry: `contexts.laravel`), so
your scrubber should cover it.

## API

All in the `FloodControl` namespace.

```php
Report::exception(Throwable $e, array $context = [], ?Limit $limit = null): void
Report::error(string $message, array $context = [], ?Throwable $previous = null, ?Budget $budget = null): void

LogThrottle::once(string $key, ?int $seconds, LoggerInterface|UnitEnum|array|string|null $channel = null): LoggerInterface
LogThrottle::times(string $key, Budget $budget, LoggerInterface|UnitEnum|array|string|null $channel = null): LoggerInterface
LogThrottle::class               // a channel tap; LogThrottle::class . ':3,600' sets its own budget

Budget::of(int $times, int $seconds)
Budget::perMinute(int $times = 1)   Budget::perHour(int $times = 1)   Budget::perDay(int $times = 1)
Budget::unlimited()
$budget->times   $budget->seconds   $budget->isUnlimited()   $budget->toLimit(?string $key = null): Limit

OperationalError                 // extends RuntimeException; what Report::error() reports
```

## Testing

The gate also runs under `Exceptions::fake()`: a test that reports one class more than its budget
sees only the first N. Set `flood-control.enabled` to `false` in that test.

A bare `Log::spy()` works with `LogThrottle`:

```php
Log::spy();

LogThrottle::once('origin-denied', 60)->warning('Origin not allowed');

Log::shouldHaveReceived('warning')->with('Origin not allowed')->once();
```

`Log::spy()` never resolves a channel's taps: test the [channel tap](#per-channel) on a real channel.

## Upgrading

### From 2.3

- Require `"ismailnakkar/laravel-flood-control": "^3.0"`.
- `Budget::fromConfig()` is gone: it parsed a `classes` entry for the package. Build the budget yourself:
  `Budget::fromConfig(['limit' => 3], $default)` → `Budget::of(3, $default->seconds)`.
- `LogThrottle::once()` and `times()` keep their gates under new cache keys (`flood-control:once:…`,
  `flood-control:times:…`), so one key used with both no longer clobbers the other. On deploy, each
  `once()` window still open logs one more line, and each `times()` window starts its count over.

## Licence

MIT.
