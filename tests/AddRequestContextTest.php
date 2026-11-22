<?php

declare(strict_types=1);

namespace Logging\Tests;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Logging\AddRequestContext;
use Logging\RedactLogs;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;

class AddRequestContextTest extends TestCase
{
    private TestHandler $file;

    protected function setUp(): void
    {
        // Read once per application, so it is set before the app is built. Octane's workers say false too.
        putenv('APP_RUNNING_IN_CONSOLE=' . ($this->name() === 'a_console_run_adds_nothing' ? 'true' : 'false'));

        parent::setUp();

        $this->app->instance('request', Request::create(
            'https://exe.test/reset-password/RESET123?token=abc',
            'POST',
            ['password' => 'hunter2'],
            server: ['REMOTE_ADDR' => '203.0.113.9', 'HTTP_USER_AGENT' => 'Mozilla/5.0'],
        ));
    }

    protected function tearDown(): void
    {
        putenv('APP_RUNNING_IN_CONSOLE');

        parent::tearDown();
    }

    protected function defineEnvironment($app): void
    {
        $this->file = new TestHandler(Level::Debug);

        $app['config']->set('reporting.redact', ['ip']);

        foreach ([
            'file'          => [AddRequestContext::class],
            'context-first' => [AddRequestContext::class, RedactLogs::class],
            'redact-first'  => [RedactLogs::class, AddRequestContext::class],
        ] as $channel => $taps) {
            $app['config']->set("logging.channels.{$channel}", [
                'driver' => 'custom',
                'via'    => fn (): Monolog => new Monolog($channel, [$this->file]),
                'tap'    => $taps,
            ]);
        }
    }

    /** @return array<string, mixed> */
    private function requestContext(string $channel): array
    {
        Log::channel($channel)->info('saved');

        return $this->file->getRecords()[0]->extra['request'] ?? [];
    }

    #[Test]
    public function a_line_carries_the_request_without_its_query_or_body(): void
    {
        $this->assertSame([
            'url'        => 'https://exe.test/reset-password/RESET123',
            'method'     => 'POST',
            'ip'         => '203.0.113.9',
            'user_agent' => 'Mozilla/5.0',
        ], $this->requestContext('file'));
    }

    #[Test]
    public function a_console_run_adds_nothing(): void
    {
        $this->assertSame([], $this->requestContext('file'));
    }

    /** @return array<string, list<string>> */
    public static function redactedChannels(): array
    {
        return ['context tap first' => ['context-first'], 'redaction tap first' => ['redact-first']];
    }

    #[Test]
    #[DataProvider('redactedChannels')]
    public function a_redacted_channel_redacts_it_whichever_tap_comes_first(string $channel): void
    {
        $request = $this->requestContext($channel);

        $this->assertSame('[redacted]', $request['ip']);
        $this->assertSame('https://exe.test/reset-password/[redacted]', $request['url']);
    }
}
