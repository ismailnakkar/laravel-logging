# laravel-logging

What a Laravel app reports and logs, kept in check:

- **Throttling**: reported exceptions and repeated log lines are rate-limited, so one failing
  dependency cannot flood Sentry, Slack or your logs. Laravel Pulse still sees every exception.
- **Request context**: a tap that adds the HTTP request to each log line.
- **Redaction**: one list of secrets, applied by drivers to what leaves the server: the log channels
  you tap (Slack) and Sentry (automatically, when installed).
- **Queue waits**: with Horizon, a queue backed up past its threshold is logged as an error, once per
  interval, when you opt in.

## Install

```bash
composer require ismailnakkar/laravel-logging
php artisan vendor:publish --tag=reporting-config   # optional
```

Requires PHP 8.2+, Laravel 12.21+ or 13, and Monolog 3. `laravel/pulse` 1.x,
`sentry/sentry-laravel` 4.x and `laravel/horizon` 5.x are optional. The provider is auto-discovered
and wires itself into the exception handler, the Sentry client and Horizon's events: no
`bootstrap/app.php` or `config/sentry.php` edit.

The config file is `config/reporting.php` (key `reporting`), since `config/logging.php` is Laravel's own.

Throttle state lives in the `cache.limiter` store (the default store unless set). Use one every worker
and server shares, such as Redis or the database, or each process or server throttles on its own.

## Configuration

| Key | Env | Default | Meaning |
| --- | --- | --- | --- |
| `throttle` | `REPORTING_THROTTLE` | `true` | Exception throttling, per-call limits and the [channel tap](#per-channel) included. [Pulse](#pulse) and `LogThrottle::once()` ignore it. |
| `limit` | `REPORTING_LIMIT` | `10` | Reports per exception class per window. |
| `window` | `REPORTING_WINDOW` | `300` | Window in seconds. |
| `classes` | | `[]` | Per-class budgets, below. |
| `long_wait` | | `null` | Seconds between [long-wait alerts](#horizon-long-waits) per queue; `null` is off. |
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

### Outage budgets

`Budget::outages()` holds budgets for what a failing dependency throws on every request or job.
Spread it first, then add your own entries or override one of its by class:

```php
use Logging\Budget;

'classes' => [
    ...Budget::outages(),
    \Captcha\CaptchaUnavailable::class        => ['limit' => 3, 'window' => 600],
    \Illuminate\Database\QueryException::class => Budget::perHour(1),   // replaces its 3 per 600s
],
```

| Class | Budget | Why |
| --- | --- | --- |
| `Illuminate\Database\QueryException` | 3 per 600s | The database being down means every request throws. |
| `RedisException` | 3 per 600s | phpredis, which also backs `cache.limiter`: a blip is capped, a full outage is not (the gate fails open). |
| `Illuminate\Http\Client\ConnectionException` | 5 per 600s | `Http::`'s transfer failures. |
| `Psr\Http\Client\NetworkExceptionInterface` | 5 per 600s | A client called directly: an SDK or Socialite on Guzzle 7 or 8, any PSR-18 client. |
| `Symfony\Component\Mailer\Exception\TransportExceptionInterface` | 3 per 900s | Every mail transport. |
| `League\Flysystem\FilesystemException` | 5 per 600s | Every disk. |
| `Illuminate\Queue\MaxAttemptsExceededException` | 5 per 900s | One poison job retried across every worker reports on each attempt. |

A class that is not loaded (phpredis not installed) is left out, as `classes` refuses one.

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
use Logging\LogThrottle;

LogThrottle::once('origin-denied', 60)->warning('Origin not allowed', ['origin' => $origin]);
LogThrottle::once('feed-stale', 900, ['single', 'slack'])->warning('Feed is stale');
```

- `once()` returns the real logger **exactly once** per window, even across workers (one
  `Cache::add()`). For several lines per window, log to a [tapped channel](#per-channel).
- After that line, until the window ends, it returns a logger that discards: every level,
  `withContext()` and array messages work, and no `MessageLogged` fires (nothing reaches Telescope or
  Sentry breadcrumbs).
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

## Horizon long waits

Horizon fires `LongWaitDetected` each minute a queue waits longer than its threshold (`waits` in
`config/horizon.php`, 60 seconds by default), and tells only a mail, Slack or SMS route it is given.
Set `long_wait` to log it instead, so it reaches your default channel's sinks (Sentry):

```php
// config/reporting.php
'long_wait' => 3600,   // one error per queue per hour while it stays backed up
```

- The line is `error('Queue wait is above its Horizon threshold', ['connection' => …, 'queue' => …,
  'seconds' => …])` through [`LogThrottle::once()`](#log-lines), keyed
  `horizon:long-wait:{connection}:{queue}`: each queue alerts on its own, exactly once per window.
- `null`, the default, logs nothing. Below 1 logs every check. Anything but `null` or an int throws
  at boot.
- Read per event, so a test or a deploy can change it at runtime. Without Horizon nothing is wired.

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

Not covered: Sentry Logs (`before_send_log`), check-ins and the user context. Leave
`send_default_pii` off, Sentry's own default: it adds the user context.

The driver also sets three options sentry-laravel's own `config/sentry.php` leaves at the SDK's
defaults. It sets them in config, under yours, so a key you set in `config/sentry.php` wins:

| Key | Set to | Why |
| --- | --- | --- |
| `max_request_body_size` | `'never'` | The SDK attaches request bodies (passwords, tokens) whatever `send_default_pii` says. What Sentry never collects needs no redaction. |
| `http_timeout` | `2` | Sends are synchronous, on the request path: the SDK's 5 seconds would hold a request that long. |
| `http_connect_timeout` | `1` | Likewise; the SDK's is 2. |

A `traces_sample_rate` of 0 or below is off (`null`) on the client, whatever the config says: at 0 the
SDK still continues an incoming `sentry-trace` header marked sampled, so any client could force a sent
transaction. Sentry's config reads the rate from `SENTRY_TRACES_SAMPLE_RATE`, so a `0` or blank value
there is off too, and `config('sentry.traces_sample_rate')` still reads `0.0`.

### Another tracker

Hook the tracker's client from a service provider's `register()`, before the client is resolved, and
map its payload onto the same rules: `Redactor::fromConfig()->values($array)` for keyed values and
`->text($string)` for free text. Build the redactor per event: it reads the lists when built.

App code can use the same rules: `Redactor::fromConfig()->text($e->getMessage())`, though a tapped
channel already redacts what it writes.

## Pulse

Pulse's exceptions recorder counts from a `reportable`, which sees only survivors. With `laravel/pulse`
installed, the package binds it to one that counts every reported exception in front of the gate and
delegates recording, so the card shows the real rate and Pulse's settings, off switches and
`Pulse::report()` work as before. `php artisan about` shows it.

Counting runs on `dontReportWhen()`, ahead of the throttle chain, so an `$exceptions->throttle()` of
your own that returns a `Limit` replaces the gate without hiding anything from Pulse.

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
LogThrottle::class               // a channel tap; LogThrottle::class . ':3,600' sets its own budget

Budget::of(int $times, int $seconds)
Budget::perMinute(int $times = 1)   Budget::perHour(int $times = 1)   Budget::perDay(int $times = 1)
Budget::unlimited()
Budget::outages(): array<class-string<Throwable>, Budget>   // spread into `classes`
$budget->times   $budget->seconds   $budget->isUnlimited()   $budget->toLimit(?string $key = null): Limit

OperationalError                 // extends RuntimeException; what Report::error() reports

AddRequestContext::class         // a channel tap: extra.request
RedactLogs::class                // a channel tap: the log-channel driver

Redaction\Redactor::fromConfig(): Redactor
Redaction\Redactor::DEFAULT_KEYS   Redaction\Redactor::DEFAULT_PATHS   Redaction\Redactor::MARKER
$redactor->values(array $values): array   $redactor->text(string $text): string   $redactor->isSecret(int|string $name): bool
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

### From 4.2

5.0 removes two config keys, a method and an interface. An app that uses none of them only requires
`"ismailnakkar/laravel-logging": "^5.0"`; each item is what an app that does changes:

- `reporting.pulse` and `REPORTING_PULSE` are gone: with Pulse installed, every reported exception is
  counted in front of the gate, as the default was. Delete the key from a published
  `config/reporting.php` and the variable from your `.env` files. An app that set it to `false` now
  counts every exception too.
- `reporting.counters` is gone, with its boot check. Delete the key. A counter of yours becomes an
  `$exceptions->dontReportWhen()` callback in `bootstrap/app.php` that returns `false`: it runs ahead
  of the gate too, but a throw there breaks `report()` and a report made there re-enters it, both of
  which the package guarded against.
- `LogThrottle::times()` is gone. Call `LogThrottle::once()` for one line per window, or log to a
  channel [tapped](#per-channel) with `LogThrottle::class . ':3,600'` for several.
- `Logging\Redaction\Driver` is gone, and `SentryDriver` is internal: Sentry redaction is unchanged. A
  class of yours that implemented `Driver` drops `implements Driver` and keeps the `register()` call
  in your service provider.

### From 4.1

With `sentry/sentry-laravel` installed, the driver now sets [three options](#sentry) where
`config/sentry.php` has no such key, and turns a `traces_sample_rate` of 0 or below off. An app that
sets none of them changes in three ways:

- Request bodies are no longer sent (`max_request_body_size` is `never`).
- A send gives up after 2 seconds, or after 1 when it cannot connect (the SDK's are 5 and 2).
- A rate of 0, or a blank `SENTRY_TRACES_SAMPLE_RATE`, no longer continues an incoming `sentry-trace`
  header marked sampled: no transaction is sent.

Require `"ismailnakkar/laravel-logging": "^4.2"` to drop from `config/sentry.php` the three keys
where they hold the values above, a guard that turns a rate of 0 into `null`, and their comments.
Drop `'send_default_pii' => false` too where `SENTRY_SEND_DEFAULT_PII` is unset: Sentry's own config
reads that variable, and its default is false. Delete the file when nothing else is left in it.

A test that reads `traces_sample_rate` from config to check that guard goes with it: `config()` keeps
Sentry's `0.0`, and the package's suite covers the client. A `config('sentry.…')` assertion on the
three keys still passes, as the driver sets them in config.

### From 4.0

Nothing changes until an app opts in: `long_wait` is `null` (a published file without it reads
`null`) and `classes` keeps its `[]` default. Require `"ismailnakkar/laravel-logging": "^4.1"` to use
either step below.

**Long-wait alert.** Set `'long_wait' => 3600` in `config/reporting.php`, and delete a
`LongWaitDetected` listener of your own in the same deploy, with its registration and its tests, or
both log. One that logged through `LogThrottle::once("horizon:long-wait:{connection}:{queue}", …)`
hands over the windows it opened: a deploy during a backlog does not alert again.

**Outage budgets.** Put `...Budget::outages()` first in `classes` and your own entries after it.
Drop each of yours that sets the same budget for one of its classes or a subtype; keep one only to
override it. A class it holds that had no entry of its own now gets its budget instead of `limit` and
`window` or a broader entry's, such as a catch-all's: the most specific entry wins.

### From laravel-flood-control 3

The package is renamed. Each step is what an app changes:

- Composer: replace `"ismailnakkar/laravel-flood-control": "^3.0"` with
  `"ismailnakkar/laravel-logging": "^4.0"`. Packagist lists it under the new name only, and the old
  name no longer resolves.
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
  instead, before the scrubber goes, and the helper's tests go with the scrubber. `text()` hides
  [listed](#redaction) parameters and the segment after each `redact_paths` prefix: a provider that
  puts its key in the path needs that prefix in `redact_paths`.
- Request context: replace your own `AddRequestContext` tap with `Logging\AddRequestContext` and
  delete it. It no longer raises the file formatter's normalised item count (Monolog's 1,000 applies),
  and in a stack it now adds `extra.request` to the other channels' lines too.
- Log channels: add `Logging\RedactLogs::class` to the taps of each channel that leaves the server
  (Slack), never to `daily` or `single` (a file on your own server stays complete for diagnosis) nor
  to the `sentry` channel. An untapped channel is written as before.

## Licence

MIT.
