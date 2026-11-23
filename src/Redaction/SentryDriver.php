<?php

declare(strict_types=1);

namespace Logging\Redaction;

use Closure;
use Illuminate\Contracts\Foundation\Application;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\EventHint;
use Sentry\Laravel\ServiceProvider as SentryServiceProvider;

/**
 * Redacts every event and transaction Sentry sends. It wraps the client's `before_send` and
 * `before_send_transaction` rather than taking their place in config/sentry.php, so an app's own
 * callbacks still run, first, and whatever they return is redacted last. It also sets three options
 * sentry-laravel's own config leaves at the SDK's defaults, under the app's, and turns a
 * traces_sample_rate of 0 or below off.
 */
final class SentryDriver implements Driver
{
    /** The SDK attaches request bodies (passwords, tokens, 2FA codes) whatever send_default_pii says. */
    private const MAX_REQUEST_BODY_SIZE = 'never';

    /**
     * Seconds a send may take. Sends are synchronous, on the request path: the SDK's 5 would hold a
     * request that long.
     */
    private const HTTP_TIMEOUT = 2;

    /** Seconds to connect, for the same reason: the SDK's is 2. */
    private const HTTP_CONNECT_TIMEOUT = 1;

    /** Options sentry-laravel's own config/sentry.php does not set. */
    private const CONFIG_DEFAULTS = [
        'max_request_body_size' => self::MAX_REQUEST_BODY_SIZE,
        'http_timeout'          => self::HTTP_TIMEOUT,
        'http_connect_timeout'  => self::HTTP_CONNECT_TIMEOUT,
    ];

    public function register(Application $app): void
    {
        if (! class_exists(SentryServiceProvider::class)) {
            return;
        }

        // Under the app's config/sentry.php, as sentry-laravel merges its own file: a key the app sets
        // wins, and as that file sets none of these, the two providers may register in either order.
        $config = $app->make('config');
        $config->set('sentry', [...self::CONFIG_DEFAULTS, ...(array)$config->get('sentry', [])]);

        // The builder is bound, not shared: each client the container builds is wrapped once.
        $app->extend(ClientBuilder::class, self::wrap(...));
    }

    private static function wrap(ClientBuilder $builder): ClientBuilder
    {
        $options = $builder->getOptions();

        // sentry-laravel's config sets the rate from SENTRY_TRACES_SAMPLE_RATE, and at 0 (or below) the
        // SDK still continues an incoming sentry-trace header marked sampled: any client could force a
        // sent transaction. At null the SDK samples none: the request, queue and scheduler
        // transactions all start through the hub, which reads these options, not config.
        $rate = $options->getTracesSampleRate();

        if ($rate !== null && $rate <= 0.0) {
            $options->setTracesSampleRate(null);
        }

        // Sentry calls before_send for errors and messages only, and before_send_transaction for
        // transactions only, which carry the same request and breadcrumbs plus their spans.
        $options->setBeforeSendCallback(self::after($options->getBeforeSendCallback()));
        $options->setBeforeSendTransactionCallback(self::after($options->getBeforeSendTransactionCallback()));

        return $builder;
    }

    /** @param  callable(Event, ?EventHint): ?Event  $callback */
    private static function after(callable $callback): Closure
    {
        return static function (Event $event, ?EventHint $hint = null) use ($callback): ?Event {
            $event = $callback($event, $hint);

            return $event === null ? null : self::redact($event, Redactor::fromConfig());
        };
    }

    private static function redact(Event $event, Redactor $redactor): Event
    {
        $event->setRequest(self::request($event->getRequest(), $redactor));

        // Extra holds a log line's context (`log_context`) and Laravel Context; contexts hold
        // Laravel Context again (`laravel`) and a transaction's root span (`trace`).
        $event->setExtra($redactor->values($event->getExtra()));

        foreach ($event->getContexts() as $name => $context) {
            $event->setContext((string)$name, $redactor->values($context));
        }

        $event->setBreadcrumb(array_map(
            static fn (Breadcrumb $breadcrumb): Breadcrumb => self::breadcrumb($breadcrumb, $redactor),
            $event->getBreadcrumbs(),
        ));

        $message = $event->getMessage();

        if ($message !== null) {
            $formatted = $event->getMessageFormatted();

            $event->setMessage(
                $redactor->text($message),
                $redactor->values($event->getMessageParams()),
                $formatted === null ? null : $redactor->text($formatted),
            );
        }

        // An HTTP client's exception names the URL it called, query and all.
        foreach ($event->getExceptions() as $exception) {
            $exception->setValue($redactor->text($exception->getValue()));
        }

        // HTTP client spans keep the query apart (`http.query`), and the description holds the URL.
        foreach ($event->getSpans() as $span) {
            $span->setData($redactor->values($span->getData()));

            $description = $span->getDescription();

            if ($description !== null) {
                $span->setDescription($redactor->text($description));
            }
        }

        return $event;
    }

    /**
     * Sentry sends the URL, the query string and the headers whatever send_default_pii says.
     *
     * @param  array<string, mixed>  $request
     * @return array<string, mixed>
     */
    private static function request(array $request, Redactor $redactor): array
    {
        // Cookies come only with send_default_pii, and each value is a session or a visitor's
        // token: the names stay, the values never.
        if (is_array($request['cookies'] ?? null)) {
            $request['cookies'] = array_fill_keys(array_keys($request['cookies']), Redactor::MARKER);
        }

        // Headers by name, and each value as text: the Referer carries the previous page's query.
        return $redactor->values($request);
    }

    /** A log breadcrumb carries the line's context, an HTTP one the URL and its query apart. */
    private static function breadcrumb(Breadcrumb $breadcrumb, Redactor $redactor): Breadcrumb
    {
        foreach ($redactor->values($breadcrumb->getMetadata()) as $name => $value) {
            $breadcrumb = $breadcrumb->withMetadata((string)$name, $value);
        }

        $message = $breadcrumb->getMessage();

        return $message === null ? $breadcrumb : $breadcrumb->withMessage($redactor->text($message));
    }
}
