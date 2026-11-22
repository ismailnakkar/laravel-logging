<?php

declare(strict_types=1);

namespace Logging\Redaction;

/**
 * The redaction rules in `reporting.redact` and `reporting.redact_paths`, applied to the shapes a sink
 * carries: keyed values and free text, URLs and query strings included. Drivers map a sink's payload
 * onto these two calls.
 */
final readonly class Redactor
{
    public const MARKER = '[redacted]';

    /** What config/reporting.php ships: credentials Laravel and its usual packages use, and PII. */
    public const DEFAULT_KEYS = [
        'password', 'password_confirmation', 'current_password',
        'token', 'access_token', 'refresh_token', 'api_token', 'api_key',
        'secret', 'client_secret', 'signature',
        'authorization', 'cookie',
        'email', 'ip',
    ];

    /** Laravel's password reset link: /reset-password/{token}. */
    public const DEFAULT_PATHS = ['reset-password'];

    /**
     * A parameter after `?`, `&`, `#` or the start: a URL's query or fragment, or a bare query string.
     * The name stops at `/` and `:` so a URL's scheme and path never read as one; the value stops at
     * `?` so an unencoded URL inside a value still has its own query scanned.
     */
    private const PARAMETER = '~(?<=^|[?&#])([^=&?#/:\s"\'<>]+)=([^&?#\s"\'<>]*)~';

    /** What a bearer path segment runs to. */
    private const SEGMENT = '[^/?#\s"\'<>]+';

    /** @var array<string, true> Normalised names. */
    private array $names;

    private ?string $bearerPaths;

    /**
     * @param  list<string>  $keys  Names whose values are hidden: array keys, query parameters, headers.
     * @param  list<string>  $paths  Path prefixes whose next segment is a credential (`reset-password`).
     */
    public function __construct(array $keys, array $paths)
    {
        $this->names = array_fill_keys(array_map(self::normalise(...), $keys), true);

        $this->bearerPaths = $paths === [] ? null : sprintf(
            '~/(%s)/%s~',
            implode('|', array_map(static fn (string $path): string => preg_quote(trim($path, '/'), '~'), $paths)),
            self::SEGMENT,
        );
    }

    /**
     * The rules configured now: read per call, so a sink that outlives a request (Octane) follows
     * config. An absent key, as in a config cache from before install, takes the default.
     */
    public static function fromConfig(): self
    {
        return new self(
            self::strings(config('reporting.redact', self::DEFAULT_KEYS)),
            self::strings(config('reporting.redact_paths', self::DEFAULT_PATHS)),
        );
    }

    public function isSecret(int|string $name): bool
    {
        return isset($this->names[self::normalise((string)$name)]);
    }

    /**
     * Each listed key's value becomes the marker, at any depth; every other string goes through text().
     *
     * @template TKey of array-key
     *
     * @param  array<TKey, mixed>  $values
     * @return array<TKey, mixed>
     */
    public function values(array $values): array
    {
        foreach ($values as $key => $value) {
            $values[$key] = match (true) {
                $this->isSecret($key) => self::MARKER,
                is_array($value)      => $this->values($value),
                is_string($value)     => $this->text($value),
                default               => $value,
            };
        }

        return $values;
    }

    /** Hides the listed parameters' values and the segment after each bearer path. */
    public function text(string $text): string
    {
        if ($this->names !== []) {
            $text = preg_replace_callback(
                self::PARAMETER,
                fn (array $match): string => $this->isSecret(urldecode($match[1])) ? $match[1] . '=' . self::MARKER : $match[0],
                $text,
            ) ?? self::MARKER; // A regex failure hides the whole text: the safe direction.
        }

        if ($this->bearerPaths !== null) {
            $text = preg_replace($this->bearerPaths, '/$1/' . self::MARKER, $text) ?? self::MARKER;
        }

        return $text;
    }

    /** `api_key`, `api-key` and `apiKey` are one name. */
    private static function normalise(string $name): string
    {
        return strtolower(str_replace(['_', '-'], '', $name));
    }

    /** @return list<string> */
    private static function strings(mixed $list): array
    {
        return array_values(array_filter((array)$list, is_string(...)));
    }
}
