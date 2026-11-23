<?php

declare(strict_types=1);

use Logging\Redaction\Redactor;

return [

    /*
     * Exception throttling on or off, per-call Report::exception() limits and the LogThrottle channel
     * tap included. Counting and LogThrottle::once()/times() are unaffected.
     */
    'throttle' => env('REPORTING_THROTTLE', true),

    /*
     * With laravel/pulse installed, count every reported exception in front of the gate, so the
     * exceptions card shows the real rate. Off, Pulse counts survivors only. Read per exception.
     */
    'pulse' => env('REPORTING_PULSE', true),

    /*
     * Invokable classes called with every reported exception, throttled or not. Resolved from the
     * container per exception; throws are swallowed, and a report made from a counter is not
     * counted again.
     *
     *   \App\Reporting\CountExceptions::class,
     */
    'counters' => [],

    /*
     * The default budget: this many reports per exception class per window, in seconds.
     */
    'limit'  => env('REPORTING_LIMIT', 10),
    'window' => env('REPORTING_WINDOW', 300),

    /*
     * Per-class budgets. An entry covers its subtypes (class or interface) and the most specific
     * wins; unrelated entries are tried in order, so put a catch-all last. Absent keys fall back to
     * the defaults above, and each concrete class still gets its own bucket.
     *
     *   \Illuminate\Database\QueryException::class => ['limit' => 1, 'window' => 3600],
     *   \Throwable::class                          => ['limit' => 20],
     *
     * Logging\Budget::outages() holds budgets for a failing database, Redis, HTTP, mail or disk:
     * spread it first, `[...Budget::outages(), …]`, and override or add entries after it.
     *
     * Arrays or a Logging\Budget, not a Limit: a Limit fails `config:cache`. A limit or window
     * below 1 means no limit; to silence a class, use `$exceptions->dontReport()`.
     */
    'classes' => [
        //
    ],

    /*
     * With laravel/horizon, log a queue whose wait passes its Horizon threshold (`waits` in
     * config/horizon.php) as an error, at most once per this many seconds per queue: 3600 is one an
     * hour. Horizon checks every minute, so below 1 logs each check. Null, off, leaves the alert to
     * Horizon's own mail, Slack or SMS route. Read per event.
     */
    'long_wait' => null,

    /*
     * Names whose values are hidden as [redacted]: keys at any depth of a log line's context and
     * extra, and of a Sentry event's extra, contexts, breadcrumbs and spans; query and fragment
     * parameters in any URL or text; request headers. Matched without case, `-` or `_`: api_key
     * also covers api-key and apiKey. Add yours to the defaults, or list only your own:
     *
     *   [...Redactor::DEFAULT_KEYS, 'license_key', 'x-tenant-key'],
     */
    'redact' => Redactor::DEFAULT_KEYS,

    /*
     * Path prefixes whose next segment is a credential, hidden in any URL or text:
     * /reset-password/{token} becomes /reset-password/[redacted].
     */
    'redact_paths' => Redactor::DEFAULT_PATHS,

];
