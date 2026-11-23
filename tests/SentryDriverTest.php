<?php

declare(strict_types=1);

namespace Logging\Tests;

use Logging\Tests\Fixtures\AppSentryCallbacks;
use Logging\Tests\Fixtures\RecordingTransport;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use RuntimeException;
use Sentry\Breadcrumb;
use Sentry\ClientBuilder;
use Sentry\Event;
use Sentry\EventType;
use Sentry\ExceptionDataBag;
use Sentry\Laravel\ServiceProvider as SentryServiceProvider;
use Sentry\Options;
use Sentry\State\HubInterface;
use Sentry\Tracing\Span;
use Sentry\Tracing\Transaction;

use function Sentry\continueTrace;

/** Auto-discovery registers this package's provider before Sentry's: names sort it first. */
class SentryDriverTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (! self::hasSentry()) {
            $this->markTestSkipped('sentry/sentry-laravel is not installed.');
        }
    }

    protected function getPackageProviders($app): array
    {
        return self::hasSentry() ? [...parent::getPackageProviders($app), SentryServiceProvider::class] : parent::getPackageProviders($app);
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('reporting.redact', ['api', 'api_key', 'token', 'signature', 'email', 'ip', 'x-tenant-key']);
        $app['config']->set('reporting.redact_paths', ['reset-password']);
    }

    /** The options of the client the container built. */
    private function clientOptions(): Options
    {
        $options = $this->app->make(HubInterface::class)->getClient()?->getOptions();
        $this->assertNotNull($options);

        return $options;
    }

    /** Through the client the container built, as Sentry runs it before a send. */
    private function send(Event $event): ?Event
    {
        $options = $this->clientOptions();

        $callback = $event->getType() === EventType::transaction()
            ? $options->getBeforeSendTransactionCallback()
            : $options->getBeforeSendCallback();

        return $callback($event, null);
    }

    /** @param  array<string, mixed>  $sentry */
    private function rebuildClient(array $sentry): void
    {
        config(['sentry' => [...(array)config('sentry'), ...$sentry]]);

        $this->app->forgetInstance(HubInterface::class);
    }

    private static function event(): Event
    {
        return Event::createEvent();
    }

    /** As the tracing middleware starts one for a request whose client sent a sentry-trace header marked sampled. */
    private function headerSampledTransaction(): Transaction
    {
        return $this->app->make(HubInterface::class)->startTransaction(
            continueTrace('566e3688a61d4bc888951642d6f14a19-566e3688a61d4bc8-1', ''),
        );
    }

    #[Test]
    public function the_client_gets_the_safe_defaults_where_the_app_sets_none(): void
    {
        $options = $this->clientOptions();

        $this->assertSame('never', $options->getMaxRequestBodySize());
        $this->assertSame(2.0, $options->getHttpTimeout());
        $this->assertSame(1.0, $options->getHttpConnectTimeout());

        // In config too, so an app's own config('sentry.…') checks read them once its keys are gone.
        $this->assertSame('never', config('sentry.max_request_body_size'));
        $this->assertSame(2, config('sentry.http_timeout'));
        $this->assertSame(1, config('sentry.http_connect_timeout'));
    }

    #[Test]
    #[TestWith([0.0])]
    #[TestWith([-1.0])]
    public function a_traces_sample_rate_of_zero_or_below_is_off_and_a_sampled_header_cannot_force_a_transaction(float $rate): void
    {
        $this->rebuildClient(['traces_sample_rate' => $rate]);

        $this->assertNull($this->clientOptions()->getTracesSampleRate());
        $this->assertFalse($this->headerSampledTransaction()->getSampled());
    }

    #[Test]
    public function a_traces_sample_rate_above_zero_is_kept(): void
    {
        $this->rebuildClient(['traces_sample_rate' => 0.25]);

        $this->assertSame(0.25, $this->clientOptions()->getTracesSampleRate());
        // With tracing on, the client's sampled header decides: what a rate of 0 would let through.
        $this->assertTrue($this->headerSampledTransaction()->getSampled());
    }

    #[Test]
    public function credentials_in_the_url_and_query_string_never_reach_sentry(): void
    {
        $event = self::event()->setRequest([
            'url'          => 'https://exe.test/api?api=6f1d2c0a&url=https%3A%2F%2Fexample.com&alias=promo',
            'query_string' => 'api=6f1d2c0a&url=https%3A%2F%2Fexample.com&alias=promo',
        ]);

        $request = $this->send($event)?->getRequest();

        $this->assertSame('https://exe.test/api?api=[redacted]&url=https%3A%2F%2Fexample.com&alias=promo', $request['url'] ?? null);
        $this->assertSame('api=[redacted]&url=https%3A%2F%2Fexample.com&alias=promo', $request['query_string'] ?? null);
    }

    #[Test]
    public function a_mailed_bearer_link_comes_out_unusable(): void
    {
        $event = self::event()->setRequest([
            'url'          => 'https://exe.test/reset-password/RESET123?email=member%40example.com',
            'query_string' => 'email=member%40example.com',
        ]);

        $this->assertSame(
            'https://exe.test/reset-password/[redacted]?email=[redacted]',
            $this->send($event)?->getRequest()['url'] ?? null,
        );
    }

    #[Test]
    public function headers_lose_listed_names_and_the_referers_query(): void
    {
        $event = self::event()->setRequest([
            'url'     => 'https://exe.test/livewire/update',
            'headers' => [
                // PSR-7 header values are lists.
                'Referer'      => ['https://exe.test/dashboard?token=abc&tab=2'],
                'User-Agent'   => ['Mozilla/5.0'],
                'X-Tenant-Key' => ['9f2b7c1a'],
            ],
            'cookies' => ['laravel_session' => 'eyJpdiI6', 'theme' => 'dark'],
        ]);

        $request = $this->send($event)?->getRequest() ?? [];

        $this->assertSame([
            'Referer'      => ['https://exe.test/dashboard?token=[redacted]&tab=2'],
            'User-Agent'   => ['Mozilla/5.0'],
            'X-Tenant-Key' => '[redacted]',
        ], $request['headers'] ?? null);

        // Every cookie value is a session or a visitor's token, listed or not.
        $this->assertSame(['laravel_session' => '[redacted]', 'theme' => '[redacted]'], $request['cookies'] ?? null);
    }

    #[Test]
    public function a_log_lines_context_and_laravel_context_lose_their_secrets(): void
    {
        $event = self::event()
            ->setExtra([
                'log_context' => ['email' => 'member@example.com', 'reason' => 'bounced'],
                'IP'          => '203.0.113.9',
                'trace'       => 'a1b2',
            ])
            ->setContext('laravel', ['user' => ['email' => 'member@example.com', 'id' => 5]]);

        $event = $this->send($event);

        $this->assertSame([
            'log_context' => ['email' => '[redacted]', 'reason' => 'bounced'],
            'IP'          => '[redacted]',
            'trace'       => 'a1b2',
        ], $event?->getExtra());

        $this->assertSame(['user' => ['email' => '[redacted]', 'id' => 5]], $event->getContexts()['laravel'] ?? null);
    }

    #[Test]
    public function breadcrumbs_lose_their_secrets(): void
    {
        $event = self::event()->setBreadcrumb([
            new Breadcrumb(Breadcrumb::LEVEL_WARNING, Breadcrumb::TYPE_DEFAULT, 'log.warning', 'Mail to member failed', [
                'email'  => 'member@example.com',
                'reason' => 'bounced',
            ]),
            // HttpClientIntegration keeps the query apart from the URL.
            new Breadcrumb(Breadcrumb::LEVEL_INFO, Breadcrumb::TYPE_HTTP, 'http', null, [
                'url'        => 'https://api.test/v1/invoices',
                'http.query' => 'amount=4.99&api_key=SECRET',
            ]),
        ]);

        [$log, $http] = $this->send($event)?->getBreadcrumbs() ?? [];

        $this->assertSame(['email' => '[redacted]', 'reason' => 'bounced'], $log->getMetadata());
        $this->assertSame('Mail to member failed', $log->getMessage());
        $this->assertSame(['url' => 'https://api.test/v1/invoices', 'http.query' => 'amount=4.99&api_key=[redacted]'], $http->getMetadata());
    }

    #[Test]
    public function an_exception_message_naming_a_url_loses_its_credentials(): void
    {
        $event = self::event();
        $event->setExceptions([new ExceptionDataBag(new RuntimeException('cURL error 28 for https://api.test/?api_key=SECRET&ip=1.2.3.4'))]);
        $event->setMessage('Fetch failed: https://api.test/?token=SECRET');

        $event = $this->send($event);

        $this->assertSame('cURL error 28 for https://api.test/?api_key=[redacted]&ip=[redacted]', $event?->getExceptions()[0]->getValue());
        $this->assertSame('Fetch failed: https://api.test/?token=[redacted]', $event->getMessage());
    }

    #[Test]
    public function a_transaction_loses_its_secrets_too(): void
    {
        // before_send never sees a transaction, which carries the request, breadcrumbs and spans.
        $span = new Span;
        $span->setData(['http.query' => 'amount=4.99&api_key=SECRET', 'http.request.method' => 'GET']);
        $span->setDescription('GET https://api.test/lookup?token=SECRET');

        $transaction = Event::createTransaction()
            ->setSpans([$span])
            ->setRequest(['query_string' => 'api=6f1d2c0a&alias=promo'])
            ->setContext('trace', ['op' => 'http.server', 'data' => ['url' => '/reset-password/abc123']]);

        $transaction = $this->send($transaction);

        $this->assertSame(['http.query' => 'amount=4.99&api_key=[redacted]', 'http.request.method' => 'GET'], $span->getData());
        $this->assertSame('GET https://api.test/lookup?token=[redacted]', $span->getDescription());
        $this->assertSame('api=[redacted]&alias=promo', $transaction?->getRequest()['query_string'] ?? null);
        $this->assertSame(
            ['op' => 'http.server', 'data' => ['url' => '/reset-password/[redacted]']],
            $transaction->getContexts()['trace'] ?? null,
        );
    }

    #[Test]
    public function an_event_with_nothing_to_redact_passes_through(): void
    {
        $event = self::event();
        $event->setMessage('subscriptions:renew failed');

        $this->assertSame($event, $this->send($event));
        $this->assertSame([], $event->getRequest());
        $this->assertSame('subscriptions:renew failed', $event->getMessage());
    }

    #[Test]
    public function the_rules_are_read_per_event(): void
    {
        $this->app->make(HubInterface::class);
        config(['reporting.redact' => ['reason']]);

        $event = $this->send(self::event()->setExtra(['email' => 'member@example.com', 'reason' => 'bounced']));

        $this->assertSame(['email' => 'member@example.com', 'reason' => '[redacted]'], $event?->getExtra());
    }

    #[Test]
    public function the_apps_own_before_send_runs_first_and_what_it_adds_is_redacted(): void
    {
        $this->rebuildClient(['before_send' => [AppSentryCallbacks::class, 'annotate']]);

        $event = $this->send(self::event());

        $this->assertSame(['added_by_app' => 'https://api.test/?token=[redacted]'], $event?->getExtra());
    }

    #[Test]
    public function an_event_the_app_drops_stays_dropped(): void
    {
        $this->rebuildClient([
            'before_send'             => [AppSentryCallbacks::class, 'drop'],
            'before_send_transaction' => [AppSentryCallbacks::class, 'drop'],
        ]);

        $this->assertNull($this->send(self::event()));
        $this->assertNull($this->send(Event::createTransaction()));
    }

    #[Test]
    public function what_leaves_through_the_transport_is_redacted(): void
    {
        $transport = new RecordingTransport;
        $this->app->extend(ClientBuilder::class, static fn (ClientBuilder $builder): ClientBuilder => $builder->setTransport($transport));
        $this->app->forgetInstance(HubInterface::class);

        $this->app->make(HubInterface::class)->captureException(new RuntimeException('Refused https://api.test/?token=SECRET'));

        $this->assertCount(1, $transport->events);
        $this->assertSame('Refused https://api.test/?token=[redacted]', $transport->events[0]->getExceptions()[0]->getValue());
    }
}
