<?php

declare(strict_types=1);

namespace Sample\Cells\Amber\Service;

use Sample\Cells\Cedar\Api\Value\{Record as R, function Record as label, const Record as MARKER};

final class PeerGrouped
{
    public static function read(): string { return R::class; }
}
