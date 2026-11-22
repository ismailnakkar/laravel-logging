<?php

declare(strict_types=1);

namespace Logging;

use Illuminate\Log\Logger;
use InvalidArgumentException;
use Logging\Redaction\Redactor;
use LogicException;
use Monolog\Formatter\NormalizerFormatter;
use Monolog\Handler\FormattableHandlerInterface;
use Monolog\Handler\HandlerInterface;
use Monolog\Handler\ProcessableHandlerInterface;
use Monolog\LogRecord;
use UnexpectedValueException;

/**
 * The log-channel driver: as a channel's tap, it redacts each line its sinks write (a file, Slack,
 * any Monolog handler) by the `reporting.redact` and `reporting.redact_paths` rules.
 */
final class RedactLogs
{
    public function __invoke(Logger $logger): void
    {
        // On each handler, not the logger: a handler's processors run after every logger processor,
        // Laravel Context's and a stack's other channels' included, so nothing is added after it.
        foreach ($logger->getHandlers() as $handler) {
            self::processable($handler)->pushProcessor(
                static fn (LogRecord $record): LogRecord => self::redact($record, self::formatter($handler)),
            );
        }
    }

    private static function redact(LogRecord $record, NormalizerFormatter $formatter): LogRecord
    {
        $redactor = Redactor::fromConfig();

        return $record->with(
            message: $redactor->text($record->message),
            context: $redactor->values(self::normalise($record->context, $formatter)),
            extra: $redactor->values(self::normalise($record->extra, $formatter)),
        );
    }

    /**
     * The values as the handler's formatter writes them, so the rules read what the sink gets: an
     * exception becomes the formatter's text of it (LineFormatter's summary, previous exceptions and
     * stack trace), whose URLs text() redacts, and an object becomes its keyed values. What a formatter
     * leaves for json_encode (JsonFormatter keeps JsonSerializable objects) takes Monolog's base
     * normalisation, at the formatter's own limits.
     *
     * @param  array<mixed>  $values
     * @return array<mixed>
     */
    private static function normalise(array $values, NormalizerFormatter $formatter): array
    {
        $base = (new NormalizerFormatter)
            ->setMaxNormalizeDepth($formatter->getMaxNormalizeDepth())
            ->setMaxNormalizeItemCount($formatter->getMaxNormalizeItemCount());

        return (array)$base->normalizeValue($formatter->normalizeValue($values));
    }

    /** The formatter the handler writes with, read per line, so a later setFormatter() counts. */
    private static function formatter(HandlerInterface $handler): NormalizerFormatter
    {
        try {
            $formatter = $handler instanceof FormattableHandlerInterface ? $handler->getFormatter() : null;
        } catch (LogicException|UnexpectedValueException) {
            // A wrapper or buffer whose inner handler takes no formatter.
            $formatter = null;
        }

        return $formatter instanceof NormalizerFormatter ? $formatter : new NormalizerFormatter;
    }

    /** A sink this tap cannot reach would write unredacted: refused, so Laravel uses its emergency log. */
    private static function processable(HandlerInterface $handler): ProcessableHandlerInterface
    {
        if (! $handler instanceof ProcessableHandlerInterface) {
            throw new InvalidArgumentException(sprintf(
                'RedactLogs tap: the %s handler takes no processors, so its lines cannot be redacted.',
                $handler::class,
            ));
        }

        return $handler;
    }
}
