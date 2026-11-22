<?php

declare(strict_types=1);

namespace Logging\Tests;

use Illuminate\Log\Logger;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use JsonSerializable;
use Logging\LogThrottle;
use Logging\RedactLogs;
use Monolog\Formatter\JsonFormatter;
use Monolog\Handler\NullHandler;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger as Monolog;
use Monolog\LogRecord;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class RedactLogsTest extends TestCase
{
    /** The redacted channel's handler, like Slack or a file. */
    private TestHandler $sink;

    /** An untapped channel's handler. */
    private TestHandler $raw;

    protected function defineEnvironment($app): void
    {
        $this->sink = new TestHandler(Level::Debug);
        $this->raw = new TestHandler(Level::Debug);

        $app['config']->set('reporting.redact', ['token', 'email', 'api_key']);
        $app['config']->set('reporting.redact_paths', ['reset-password']);
        $app['config']->set('logging.channels.sink', [
            'driver' => 'custom',
            'via'    => fn (): Monolog => new Monolog('sink', [$this->sink]),
            'tap'    => [RedactLogs::class],
        ]);
        $app['config']->set('logging.channels.raw', [
            'driver' => 'custom',
            'via'    => fn (): Monolog => new Monolog('raw', [$this->raw]),
        ]);
        $app['config']->set('logging.channels.sink-first', ['driver' => 'stack', 'channels' => ['sink', 'raw']]);
        $app['config']->set('logging.channels.throttled-sink', [
            'driver' => 'custom',
            'via'    => fn (): Monolog => new Monolog('throttled-sink', [$this->sink]),
            'tap'    => [LogThrottle::class, RedactLogs::class],
        ]);
        // Laravel gives these its own formatter, as on a daily or Slack channel, or the one named.
        $app['config']->set('logging.channels.file', [
            'driver'  => 'monolog',
            'handler' => TestHandler::class,
            'tap'     => [RedactLogs::class],
        ]);
        $app['config']->set('logging.channels.json-file', [
            'driver'    => 'monolog',
            'handler'   => TestHandler::class,
            'formatter' => JsonFormatter::class,
            'tap'       => [RedactLogs::class],
        ]);
    }

    /** @return array<string, array{string, string}> The channel, and how its formatter writes the exception. */
    public static function formatters(): array
    {
        return [
            'Laravel\'s line formatter' => ['file', '[object] (RuntimeException(code: 0): cURL error 28: timed out for '],
            'a JSON formatter'          => ['json-file', '"class":"RuntimeException","message":"cURL error 28: timed out for '],
        ];
    }

    /** @return array<string, array{string}> */
    public static function channels(): array
    {
        return array_map(static fn (array $row): array => [$row[0]], self::formatters());
    }

    /** The one line a `monolog` channel's handler wrote. */
    private static function written(string $channel): LogRecord
    {
        $logger = Log::channel($channel);
        assert($logger instanceof Logger);

        $monolog = $logger->getLogger();
        assert($monolog instanceof Monolog);

        [$handler] = $monolog->getHandlers();
        assert($handler instanceof TestHandler);

        return self::only($handler);
    }

    private static function only(TestHandler $handler): LogRecord
    {
        [$record] = $handler->getRecords();

        return $record;
    }

    #[Test]
    public function a_line_loses_its_secrets_and_keeps_the_rest(): void
    {
        Log::channel('sink')->warning('Fetch failed for https://api.test/v1?token=abc&page=2', [
            'email'  => 'member@example.com',
            'user'   => ['id' => 5, 'email' => 'member@example.com'],
            'link'   => 'https://exe.test/reset-password/RESET123',
            'reason' => 'bounced',
        ]);

        $record = self::only($this->sink);

        $this->assertSame('Fetch failed for https://api.test/v1?token=[redacted]&page=2', $record->message);
        $this->assertSame([
            'email'  => '[redacted]',
            'user'   => ['id' => 5, 'email' => '[redacted]'],
            'link'   => 'https://exe.test/reset-password/[redacted]',
            'reason' => 'bounced',
        ], $record->context);
    }

    #[Test]
    #[DataProvider('formatters')]
    public function a_reported_exception_is_written_without_its_secrets(string $channel, string $summary): void
    {
        config(['logging.default' => $channel]);

        // Laravel logs it with ['exception' => $e]: the formatter writes its message, and each
        // previous one's, from the object.
        report(new RuntimeException(
            'cURL error 28: timed out for https://api.test/v3/security?api_key=SECRET123&ip=1.2.3.4',
            previous: new RuntimeException('Link https://exe.test/reset-password/RESET123 expired'),
        ));

        $record = self::written($channel);
        $formatted = (string)$record->formatted;
        $sent = (string)json_encode($record->context); // What Slack builds its fields from.

        $this->assertStringContainsString($summary . 'https://api.test/v3/security?api_key=[redacted]&ip=1.2.3.4', $formatted);
        $this->assertStringContainsString('https://exe.test/reset-password/[redacted] expired', $formatted);

        foreach (['SECRET123', 'RESET123'] as $secret) {
            $this->assertStringNotContainsString($secret, $formatted);
            $this->assertStringNotContainsString($secret, $sent);
        }
    }

    #[Test]
    public function laravel_writes_the_exception_as_before(): void
    {
        config(['logging.default' => 'file']);

        report(new RuntimeException('Feed fetch failed'));

        $this->assertMatchesRegularExpression(
            '~"exception":"\\[object\\] \\(RuntimeException\\(code: 0\\): Feed fetch failed at .+:\\d+\\)\\n\\[stacktrace\\]\\n#0 ~',
            (string)self::written('file')->formatted,
        );
    }

    #[Test]
    #[DataProvider('channels')]
    public function an_object_is_written_without_its_secrets(string $channel): void
    {
        Log::channel($channel)->info('Signed up', [
            'user' => new class implements JsonSerializable
            {
                /** @return array<string, mixed> */
                public function jsonSerialize(): array
                {
                    return ['id' => 5, 'email' => 'member@example.com'];
                }
            },
            'mailer' => new class
            {
                public string $host = 'smtp.test';

                public string $token = 'SECRET-TOKEN';
            },
        ]);

        $formatted = (string)self::written($channel)->formatted;

        $this->assertStringContainsString('"id":5,"email":"[redacted]"', $formatted);
        $this->assertStringContainsString('"host":"smtp.test","token":"[redacted]"', $formatted);

        foreach (['member@example.com', 'SECRET-TOKEN'] as $secret) {
            $this->assertStringNotContainsString($secret, $formatted);
        }
    }

    #[Test]
    public function laravel_context_is_redacted_in_extra(): void
    {
        Context::add(['email' => 'member@example.com', 'trace' => 'a1b2']);

        Log::channel('sink')->info('signed in');

        $this->assertSame(['email' => '[redacted]', 'trace' => 'a1b2'], self::only($this->sink)->extra);
    }

    #[Test]
    public function in_a_stack_only_the_tapped_channels_sink_is_redacted(): void
    {
        // The stack runs the raw channel's Context processor after the sink channel's processors:
        // redacting on the handler, after all of them, keeps Context from coming back raw.
        Context::add('email', 'member@example.com');

        Log::channel('sink-first')->info('Mail to {email} bounced', ['email' => 'member@example.com']);

        $this->assertSame('[redacted]', self::only($this->sink)->context['email']);
        $this->assertSame('[redacted]', self::only($this->sink)->extra['email']);
        $this->assertSame('member@example.com', self::only($this->raw)->context['email']);
        $this->assertSame('member@example.com', self::only($this->raw)->extra['email']);
    }

    #[Test]
    public function the_rules_are_read_per_line(): void
    {
        Log::channel('sink');
        config(['reporting.redact' => ['reason']]);

        Log::channel('sink')->info('bounced', ['email' => 'member@example.com', 'reason' => 'hard']);

        $this->assertSame(['email' => 'member@example.com', 'reason' => '[redacted]'], self::only($this->sink)->context);
    }

    #[Test]
    public function it_redacts_behind_the_throttle_tap(): void
    {
        Log::channel('throttled-sink')->error('failed', ['token' => 'abc']);

        $this->assertSame(['token' => '[redacted]'], self::only($this->sink)->context);
    }

    #[Test]
    public function a_handler_that_takes_no_processors_is_refused(): void
    {
        // Laravel then writes the channel to its emergency log rather than to an unredacted sink.
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage(NullHandler::class);

        (new RedactLogs)(new Logger(new Monolog('tapped', [new NullHandler])));
    }
}
