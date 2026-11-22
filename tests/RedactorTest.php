<?php

declare(strict_types=1);

namespace Logging\Tests;

use Logging\Redaction\Redactor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase as PHPUnitTestCase;
use stdClass;

class RedactorTest extends PHPUnitTestCase
{
    private static function redactor(): Redactor
    {
        return new Redactor(['api_key', 'token', 'signature', 'email', 'secret'], ['reset-password', 'report/request']);
    }

    /** @return array<string, array{string, string}> */
    public static function texts(): array
    {
        return [
            'a URL query' => [
                'https://exe.test/api?api_key=6f1d2c0a&url=https%3A%2F%2Fexample.com&alias=promo',
                'https://exe.test/api?api_key=[redacted]&url=https%3A%2F%2Fexample.com&alias=promo',
            ],
            // Sentry's `http.query` has no leading `?`, so a credential in first position must still match.
            'a raw query string' => ['token=abc&folder=7', 'token=[redacted]&folder=7'],
            'a fragment'         => ['https://exe.test/cb#token=abc&state=1', 'https://exe.test/cb#token=[redacted]&state=1'],
            'an encoded name'    => ['https://exe.test/?api%5Fkey=abc', 'https://exe.test/?api%5Fkey=[redacted]'],
            'an empty value'     => ['https://exe.test/?token=&a=1', 'https://exe.test/?token=[redacted]&a=1'],
            // A round trip through parse_str() would rewrite this unencoded &.
            'an unencoded URL in a value' => [
                'https://cuty.test/quick?url=https://example.com/?ref=1&token=abc',
                'https://cuty.test/quick?url=https://example.com/?ref=1&token=[redacted]',
            ],
            'a URL inside a value has its own query scanned' => [
                'https://cuty.test/go?next=https://example.com/?token=abc',
                'https://cuty.test/go?next=https://example.com/?token=[redacted]',
            ],
            'an = in the path is no parameter' => [
                'https://exe.test/a=b/c?token=abc',
                'https://exe.test/a=b/c?token=[redacted]',
            ],
            'a URL in a message' => [
                'cURL error 28: Operation timed out for https://api.test/v3/lookup?apiKey=abc123&ip=1.2.3.4',
                'cURL error 28: Operation timed out for https://api.test/v3/lookup?apiKey=[redacted]&ip=1.2.3.4',
            ],
            'a bearer path' => [
                'https://exe.test/fr/reset-password/RESETTOKEN123?email=member%40example.com',
                'https://exe.test/fr/reset-password/[redacted]?email=[redacted]',
            ],
            'a nested bearer path' => [
                '/report/request/a1B2c3D4/documents',
                '/report/request/[redacted]/documents',
            ],
            'a signed link keeps its route' => [
                'https://exe.test/email/verify/5/1a2b3c?expires=1790000000&signature=deadbeef',
                'https://exe.test/email/verify/5/1a2b3c?expires=1790000000&signature=[redacted]',
            ],
            'an unlisted parameter' => ['https://exe.test/?ip=1.2.3.4', 'https://exe.test/?ip=1.2.3.4'],
            'a lookalike path'      => ['/reset-passwords/abc', '/reset-passwords/abc'],
            'free text'             => ['where token = ? and email = ?', 'where token = ? and email = ?'],
        ];
    }

    #[Test]
    #[DataProvider('texts')]
    public function it_redacts_listed_parameters_and_bearer_paths_in_text(string $text, string $expected): void
    {
        $this->assertSame($expected, self::redactor()->text($text));
    }

    #[Test]
    public function redacted_text_comes_out_the_same_a_second_time(): void
    {
        $once = self::redactor()->text('https://exe.test/reset-password/abc?token=def');

        $this->assertSame($once, self::redactor()->text($once));
    }

    /** @return array<string, array{string, bool}> */
    public static function names(): array
    {
        return [
            'as listed'      => ['api_key', true],
            'upper case'     => ['API_KEY', true],
            'dashed'         => ['api-key', true],
            'camel case'     => ['apiKey', true],
            'run together'   => ['apikey', true],
            'a header'       => ['Signature', true],
            'a longer name'  => ['X-Token', false],
            'a shorter name' => ['api', false],
        ];
    }

    #[Test]
    #[DataProvider('names')]
    public function names_match_without_case_dashes_or_underscores(string $name, bool $secret): void
    {
        $this->assertSame($secret, self::redactor()->isSecret($name));
    }

    #[Test]
    public function values_hide_listed_keys_at_any_depth_and_scan_every_string(): void
    {
        $object = new stdClass;

        $this->assertSame(
            [
                'Email'   => '[redacted]',
                'user'    => ['id' => 5, 'email' => '[redacted]', 'tags' => ['a', 'b']],
                'url'     => 'https://exe.test/?token=[redacted]&page=2',
                'headers' => ['Referer' => ['https://exe.test/reset-password/[redacted]']],
                'secret'  => '[redacted]',
                'object'  => $object,
                'count'   => 3,
            ],
            self::redactor()->values([
                'Email'   => 'member@example.com',
                'user'    => ['id' => 5, 'email' => 'member@example.com', 'tags' => ['a', 'b']],
                'url'     => 'https://exe.test/?token=abc&page=2',
                'headers' => ['Referer' => ['https://exe.test/reset-password/abc']],
                // A listed key hides its whole value, an array or an object included.
                'secret' => ['nested' => 'value'],
                'object' => $object,
                'count'  => 3,
            ]),
        );
    }

    #[Test]
    public function no_rules_change_nothing(): void
    {
        $redactor = new Redactor([], []);
        $text = 'https://exe.test/reset-password/abc?token=def';

        $this->assertSame($text, $redactor->text($text));
        $this->assertSame(['token' => 'abc'], $redactor->values(['token' => 'abc']));
    }
}
