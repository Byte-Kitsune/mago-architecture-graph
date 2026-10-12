<?php

declare(strict_types=1);

namespace Sample\Screens\Amber\Entry;

use Sample\Cells\Indigo\Api\Portal as Ref0;
use Sample\Cells\Indigo\Api\Value\Record as Ref1;
use Sample\Cells\Indigo\Api\Value\Flag as Ref2;
use Sample\Cells\Indigo\Api\Value\Problem as Ref3;
use Sample\Cells\Indigo\Api\Value\Token as Ref4;
use Sample\Cells\Indigo\Api\Value\Envelope as Ref5;
use Sample\Cells\Indigo\Api\Value\Contract as Ref6;

final class UsePublic
{
    public static function run(): string { return Ref0::class . ':' . Ref1::class . ':' . Ref2::class . ':' . Ref3::class . ':' . Ref4::class . ':' . Ref5::class . ':' . Ref6::class; }
}
