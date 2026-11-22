<?php

declare(strict_types=1);

namespace Logging\Redaction;

use Illuminate\Contracts\Foundation\Application;

/**
 * How the redaction rules reach an error tracker: one driver per tracker, each mapping the tracker's
 * payload onto the Redactor. Log channels need none: the RedactLogs tap covers every Monolog sink.
 */
interface Driver
{
    /**
     * Hooks the tracker, from the provider's register() so it lands before the tracker resolves its
     * client, on every boot. Returns without wiring anything when the tracker is not installed.
     */
    public function register(Application $app): void;
}
