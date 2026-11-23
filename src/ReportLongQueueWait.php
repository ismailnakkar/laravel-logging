<?php

declare(strict_types=1);

namespace Logging;

use Illuminate\Contracts\Config\Repository;
use Laravel\Horizon\Events\LongWaitDetected;

/**
 * @internal
 *
 * Horizon notifies only a mail, Slack or SMS route it is given; this logs a long wait as an error
 * instead, so it reaches the default channel's sinks (Sentry). Off while `reporting.long_wait` is null.
 */
final readonly class ReportLongQueueWait
{
    public function __construct(private Repository $config) {}

    public function handle(LongWaitDetected $event): void
    {
        $interval = $this->config->get('reporting.long_wait');

        // Not passed on: once() never throttles a null window, so null would alert on every check.
        if ($interval === null) {
            return;
        }

        // The key the apps' own listeners used, so adopting this keeps the windows they opened.
        LogThrottle::once("horizon:long-wait:{$event->connection}:{$event->queue}", (int)$interval)
            ->error('Queue wait is above its Horizon threshold', [
                'connection' => $event->connection,
                'queue'      => $event->queue,
                'seconds'    => $event->seconds,
            ]);
    }
}
