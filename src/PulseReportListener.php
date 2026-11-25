<?php

declare(strict_types=1);

namespace Logging;

use Laravel\Pulse\Events\ExceptionReported;

/**
 * @internal
 *
 * Stands in for the stock recorder's listener: Pulse::report() reaches the recorder through this
 * event, never the handler.
 */
final class PulseReportListener
{
    public function __invoke(ExceptionReported $event): void
    {
        PulseExceptionRecorder::count($event->exception);
    }
}
