<?php

declare(strict_types=1);

namespace Logging\Tests\Fixtures;

use RuntimeException;

class MarkedException extends RuntimeException implements NarrowMarker {}
