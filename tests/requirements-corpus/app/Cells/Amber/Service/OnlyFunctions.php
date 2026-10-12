<?php

declare(strict_types=1);

namespace Sample\Cells\Amber\Service;

use function Sample\Cells\Cedar\Api\Value\Record;
use const Sample\Cells\Cedar\Api\Value\Record;

final class OnlyFunctions
{
    public static function read(): string { return Record() . Record; }
}
