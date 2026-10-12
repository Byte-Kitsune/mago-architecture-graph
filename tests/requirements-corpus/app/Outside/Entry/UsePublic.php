<?php

declare(strict_types=1);

namespace Sample\Outside\Entry;

use Sample\Cells\Cedar\Api\Portal as Ref0;
use Sample\Cells\Cedar\Api\Value\Record as Ref1;
use Sample\Cells\Cedar\Api\Value\Flag as Ref2;
use Sample\Cells\Cedar\Api\Value\Problem as Ref3;
use Sample\Cells\Cedar\Api\Value\Token as Ref4;
use Sample\Cells\Cedar\Api\Value\Envelope as Ref5;
use Sample\Cells\Cedar\Api\Value\Contract as Ref6;

final class UsePublic
{
    public static function run(): string { return Ref0::class . ':' . Ref1::class . ':' . Ref2::class . ':' . Ref3::class . ':' . Ref4::class . ':' . Ref5::class . ':' . Ref6::class; }
}
