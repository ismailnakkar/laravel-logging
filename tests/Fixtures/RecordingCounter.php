<?php

declare(strict_types=1);

namespace Logging\Tests\Fixtures;

use Throwable;

class RecordingCounter
{
    /** @var list<string> */
    public array $seen = [];

    public function __invoke(Throwable $e): void
    {
        $this->seen[] = $e->getMessage();
    }
}
