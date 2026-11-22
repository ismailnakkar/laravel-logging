<?php

declare(strict_types=1);

namespace Logging;

use Illuminate\Http\Request;
use Illuminate\Log\Logger;
use Monolog\LogRecord;

/** As a channel's tap, adds the HTTP request each line was written in to its `extra.request`. */
final class AddRequestContext
{
    public function __invoke(Logger $logger): void
    {
        // On the logger, so a RedactLogs tap on the channel, which runs on its handlers, always
        // sees it, whichever order the taps are listed in.
        $logger->pushProcessor(self::add(...));
    }

    private static function add(LogRecord $record): LogRecord
    {
        $app = app();

        // A console run's request is a stand-in. Octane's workers are not console runs.
        if ($app->runningInConsole() || ! $app->bound('request')) {
            return $record;
        }

        $request = $app->make(Request::class);

        // Never the body: it would copy every webhook payload, 2FA code and address change into the
        // logs. Nor the query: an API token or a signed link's signature rides it.
        $record->extra['request'] = [
            'url'        => $request->url(),
            'method'     => $request->method(),
            'ip'         => $request->ip(),
            'user_agent' => $request->userAgent(),
        ];

        return $record;
    }
}
