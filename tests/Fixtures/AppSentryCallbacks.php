<?php

declare(strict_types=1);

namespace Logging\Tests\Fixtures;

use Sentry\Event;

/** An app's own config/sentry.php callbacks, as callable arrays so config:cache keeps them. */
final class AppSentryCallbacks
{
    /** Adds what the driver must still redact: it runs after this. */
    public static function annotate(Event $event): Event
    {
        return $event->setExtra([...$event->getExtra(), 'added_by_app' => 'https://api.test/?token=abc']);
    }

    public static function drop(): null
    {
        return null;
    }
}
