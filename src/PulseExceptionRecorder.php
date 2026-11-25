<?php

declare(strict_types=1);

namespace Logging;

use Laravel\Pulse\Pulse;
use Laravel\Pulse\Recorders\Exceptions as PulseExceptions;
use Throwable;

/**
 * @internal
 *
 * Bound in place of Pulse's exceptions recorder, which counts from a reportable behind the gate, so
 * the card counts in front of it instead. Recording is delegated, so Pulse's settings still apply.
 * With no register(), Pulse wires none of its hooks: the provider wires count() in their place.
 */
final class PulseExceptionRecorder
{
    public function __construct(private PulseExceptions $recorder) {}

    /**
     * In front of the gate as a dontReportWhen() callback, whose null suppresses nothing, and where the
     * gate decides nothing: a handler with no throttleUsing(), and Pulse::report().
     */
    public static function count(Throwable $e): null
    {
        try {
            self::registered()?->record($e);
        } catch (Throwable) {
            // Runs outside the handler's rescue(): a throw would break report().
        }

        return null;
    }

    public function record(Throwable $e): void
    {
        $this->recorder->record($e);
    }

    /** Pulse's registered list, so Pulse's own off switches still apply. */
    private static function registered(): ?self
    {
        return app(Pulse::class)->recorders()->whereInstanceOf(self::class)->first();
    }
}
