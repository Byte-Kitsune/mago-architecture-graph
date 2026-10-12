<?php

declare(strict_types=1);

namespace Sample\Flow;

final class AllowedTask
{
    public function __construct(public PlainValue $value) {}
}
