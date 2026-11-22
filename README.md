# laravel-logging

What a Laravel app reports and logs, kept in check:

- **Throttling**: reported exceptions and repeated log lines are rate-limited, so one failing
  dependency cannot flood Sentry, Slack or your logs. Laravel Pulse and your own counters still see
  every exception.
- **Request context**: a tap that adds the HTTP request to each log line.
- **Redaction**: one list of secrets, applied by drivers to what leaves the server: the log channels
  you tap (Slack) and Sentry (automatically, when installed).

## Install

```bash
composer require ismailnakkar/laravel-logging
php artisan vendor:publish --tag=reporting-config   # optional
```

Requires PHP 8.2+, Laravel 12.21+ or 13, and Monolog 3. `laravel/pulse` 1.x and
`sentry/sentry-laravel` 4.x are optional. The provider is auto-discovered and wires itself into the
exception handler and the Sentry client: no `bootstrap/app.php` or `config/sentry.php` edit.

The config file is `config/reporting.php` (key `reporting`), since `config/logging.php` is Laravel's own.

Throttle state lives in the `cache.limiter` store (the default store unless set). Use one every worker
and server shares, such as Redis or the database, or each process or server throttles on its own.

## Configuration

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `throttle` | `REPORTING_THROTTLE` | `true` | Exception throttling, per-call limits and the [channel tap](#per-channel) included. Counting and `LogThrottle::once()`/`times()` ignore it. |
| `pulse` | `REPORTING_PULSE` | `true` | Pulse counts in front of the gate; `false` counts survivors only. |
| `counters` | | `[]` | Your own counters, see [Counting](#counting). |
| `limit` | `REPORTING_LIMIT` | `10` | Reports per exception class per window. |
| `window` | `REPORTING_WINDOW` | `300` | Window in seconds. |
| `classes` | | `[]` | Per-class budgets, below. |
| `redact` | | `Redactor::DEFAULT_KEYS` | Names whose values are hidden, see [Redaction](#redaction). |
| `redact_paths` | | `['reset-password']` | Path prefixes whose next segment is a credential. |

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
- Absent keys fall back to `limit` and `window`. A `Logging\Budget` works too
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
use Logging\Report;
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
use Logging\Budget;
use Logging\Report;

Report::error('Stripe webhook signature verification failed', ['gateway' => 'stripe']);
Report::error('Feed fetch failed', ['feed' => $id], previous: $e);
Report::error('Payment circuit breaker opened', budget: Budget::perDay(2));
```

- Reports the message as a `Logging\OperationalError`, log line included.
- Bucketed per **call site**, so one noisy caller cannot spend another's budget. `previous` is chained
  (the sink keeps its trace) and does not change the bucket.
- `budget` sets that call site's pace. Without it, the budget for `OperationalError` applies: the
  `classes` entry that matches it, a catch-all included, or the default.
- Subclass `OperationalError` to give a subsystem its own name in the sink, and report it with
  `Report::exception()`: it is then bucketed per class.

## Log lines

```php
use Logging\Budget;
use Logging\LogThrottle;

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
- A `null` window never throttles, a cache failure lets the line through, and no `reporting` config
  applies.
- Keep keys code-owned: a key built from request data is unbounded cache cardinality.

### Per channel

Plain log lines a channel sends to a sink never pass the exception gate. Tap the channel to
rate-limit what it passes on:

```php
// config/logging.php
'sentry' => [/* … */, 'tap' => [\Logging\LogThrottle::class]],            // `limit` per `window`
'slack'  => [/* … */, 'tap' => [\Logging\LogThrottle::class . ':3,600']],  // its own: 3 per 600s
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
- `throttle` switches it off, and a cache outage fails open.

## Request context

```php
// config/logging.php
'daily' => [/* … */, 'tap' => [\Logging\AddRequestContext::class]],
```

Each line written during an HTTP request gets `extra.request`: `url` (no query string), `method`, `ip`
and `user_agent`. Never the body (webhook payloads, 2FA codes) nor the query (API tokens, signed
links' signatures). Console runs add nothing; Octane's workers are not console runs. It runs on the
channel's logger, so in a stack Laravel adds it to the other channels' lines as well.

## Redaction

One set of rules, applied by a driver per sink:

- **`redact`**: names whose values become `[redacted]`. A name matches an array key at any depth, a
  query or fragment parameter in any URL or text (`?token=…`, a bare `token=…&page=2`, a URL in an
  exception message), and a request header. Matched without case, `-` or `_`: `api_key` also covers
  `api-key` and `apiKey`. A listed key hides its whole value, an array included.
- **`redact_paths`**: path prefixes whose next segment is a credential, anywhere in a URL or text:
  `/fr/reset-password/abc` becomes `/fr/reset-password/[redacted]`.

The defaults are `Redactor::DEFAULT_KEYS` (`password`, `password_confirmation`, `current_password`,
`token`, `access_token`, `refresh_token`, `api_token`, `api_key`, `secret`, `client_secret`,
`signature`, `authorization`, `cookie`, `email`, `ip`) and `['reset-password']`. A published list
replaces them, so add yours to the defaults or list only your own:

```php
// config/reporting.php
use Logging\Redaction\Redactor;

'redact'       => [...Redactor::DEFAULT_KEYS, 'license_key', 'x-tenant-key'],
'redact_paths' => [...Redactor::DEFAULT_PATHS, 'invitations'],
```

Both lists are read per line and per event, take non-empty strings only (anything else throws at
boot, so `config:cache` fails on it), and fall back to the defaults when absent.

### Log channels

Tap each channel whose lines leave the server (Slack, a hosted log service), never `daily` or
`single`: a file on your own server stays complete for diagnosis.

```php
// config/logging.php
'slack' => [/* … */, 'tap' => [\Logging\RedactLogs::class]],
'daily' => [/* … */, 'tap' => [\Logging\AddRequestContext::class]],   // no RedactLogs: stays complete
```

- Redacts each line the channel's handlers write (a file, Slack, any Monolog sink): the message's
  URLs, and the context and extra (Laravel `Context` and `extra.request` included) as the handler's
  formatter writes them. A reported exception (`['exception' => $e]`) has its message, each previous
  one's and its stack trace redacted as text, in the formatter's usual shape; an object (a model, any
  `JsonSerializable`) has its keyed values redacted.
- It runs on each handler, after every processor, so tap order does not matter, and in a stack only
  the tapped channel's sinks are redacted.
- The message is free text: a value written into it, by a `{placeholder}` or string interpolation, is
  not known to be secret. Pass secrets as context.
- A handler that takes no processors is refused, and Laravel then writes that channel to its emergency
  log rather than to an unredacted sink.
- Untapped channels are written as they are: in a stack of the two above, the daily file keeps the IP
  your request context adds and Slack gets `[redacted]`.
- Leave a tracker's own channel (`sentry`) untapped: its handler would get the formatter's text of an
  exception rather than the exception, and record a message. The tracker's [driver](#sentry) redacts it.

### Sentry

With `sentry/sentry-laravel` installed, the driver wraps the client's `before_send` and
`before_send_transaction`: your own callbacks in `config/sentry.php` still run, first, and what they
return is redacted last (`null` still drops the event). It covers:

- the request: `url`, `query_string`, headers by name and each value as text (the `Referer` carries the
  previous page's query), `data`, and every cookie value (names are kept),
- `extra` (a log line's `log_context` and Laravel `Context`) and every context (`laravel`, a
  transaction's `trace`),
- breadcrumbs (metadata and message), the message, each exception's message,
- a transaction's spans (data, such as `http.query`, and description).

Not covered: Sentry Logs (`before_send_log`), check-ins and the user context. Keep `send_default_pii`
off and `max_request_body_size` at `never`: what Sentry never collects needs no redaction.

### Another tracker

A tracker is a driver: a `Logging\Redaction\Driver` whose `register(Application $app)` hooks the
tracker's client (returning early when it is not installed) and maps its payload onto
`Redactor::fromConfig()->values()` and `->text()`. The package's own are listed in its provider; one of
your own you call from a service provider's `register()`:

```php
(new \App\Reporting\BugsnagRedaction)->register($this->app);
```

App code can use the same rules: `Redactor::fromConfig()->text($e->getMessage())`, though a tapped
channel already redacts what it writes.

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
    'tap'               => [\Logging\LogThrottle::class],
],
```

`report_exceptions => false` drops any record with an `exception` key, so `Log::error($msg,
['exception' => $e])` stops reaching the sink: use `Report::exception()` or `Report::error()`. Their
context is Laravel `Context`, which the sink sends with the event (Sentry: `contexts.laravel`), where
the [Sentry driver](#sentry) redacts it.

## API

All in the `Logging` namespace.

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

AddRequestContext::class         // a channel tap: extra.request
RedactLogs::class                // a channel tap: the log-channel driver

Redaction\Redactor::fromConfig(): Redactor
Redaction\Redactor::DEFAULT_KEYS   Redaction\Redactor::DEFAULT_PATHS   Redaction\Redactor::MARKER
$redactor->values(array $values): array   $redactor->text(string $text): string   $redactor->isSecret(int|string $name): bool
Redaction\Driver                // register(Application $app): void
Redaction\SentryDriver
```

## Testing

The gate also runs under `Exceptions::fake()`: a test that reports one class more than its budget
sees only the first N. Set `reporting.throttle` to `false` in that test.

A bare `Log::spy()` works with `LogThrottle`:

```php
Log::spy();

LogThrottle::once('origin-denied', 60)->warning('Origin not allowed');

Log::shouldHaveReceived('warning')->with('Origin not allowed')->once();
```

`Log::spy()` never resolves a channel's taps: test the [channel tap](#per-channel), `RedactLogs` and
`AddRequestContext` on a real channel.

## Upgrading

### From laravel-flood-control 3

The package is renamed. Each step is what an app changes:

- Composer: replace `"ismailnakkar/laravel-flood-control": "^3.0"` with
  `"ismailnakkar/laravel-logging": "^4.0"`, and point its `repositories` entry at the renamed repository.
- Namespace: `FloodControl\` becomes `Logging\` (`Report`, `Budget`, `LogThrottle`, `OperationalError`).
- Config: rename `config/flood-control.php` to `config/reporting.php`, and its `enabled` key to
  `throttle`. Env: `FLOOD_CONTROL_ENABLED` becomes `REPORTING_THROTTLE`, and `FLOOD_CONTROL_PULSE`,
  `_LIMIT` and `_WINDOW` become `REPORTING_PULSE`, `_LIMIT` and `_WINDOW`. Tests:
  `config(['flood-control.enabled' => false])` becomes `config(['reporting.throttle' => false])`. The
  publish tag is `reporting-config`, and `php artisan about --only=reporting` shows the panel. A
  published file without `redact` and `redact_paths` gets the defaults.
- Cache keys move from `flood-control:` to `reporting:`: on deploy every open window starts over, so
  each `once()` window logs one more line and every other bucket counts from zero.
- Sentry: the driver installs itself. Remove the `before_send` and `before_send_transaction` entries
  that point at your scrubber (and a `before_breadcrumb` one: breadcrumbs are redacted on the event),
  delete the scrubber, and move its names into `redact` and its path patterns into `redact_paths`. A
  callback that does more than scrub can stay: it runs first.
- App code that calls the scrubber's own helper calls `Logging\Redaction\Redactor::fromConfig()->text()`
  instead, before the scrubber goes: vhit's `CredentialRedactor::redact($e->getMessage())` in
  `app/Services/IpIntel/CircuitBreaker.php` becomes `Redactor::fromConfig()->text($e->getMessage())`,
  and `tests/Unit/CredentialRedactorTest.php` goes with the scrubber. `text()` hides listed parameters
  (`apiKey`, `api_key`, `token`) and the segment after each `redact_paths` prefix, not every long path
  segment as `redact()` did: a provider that puts its key in the path needs that prefix in
  `redact_paths` (IPGeolocation sends `apiKey` in the query, Spur a `Token` header).
- Request context: replace your own `AddRequestContext` tap with `Logging\AddRequestContext` and
  delete it. It no longer raises the file formatter's normalised item count (Monolog's 1,000 applies),
  and in a stack it now adds `extra.request` to the other channels' lines too.
- Log channels: add `Logging\RedactLogs::class` to the taps of each channel that leaves the server
  (Slack), never to `daily` or `single` (a file on your own server stays complete for diagnosis) nor
  to the `sentry` channel. An untapped channel is written as before.
- Redaction lists: vhit sets `'redact' => array_diff(Redactor::DEFAULT_KEYS, ['ip'])`, as IPs are its
  core data. centratag, new to the package, adopts it with the defaults plus its visitor ids:
  `[...Redactor::DEFAULT_KEYS, 'visitor', 'vhit_visitor']`.

## Licence

MIT.
