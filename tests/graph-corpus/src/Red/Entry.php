<?php

namespace App\Red;

use App\Shared\Bridge;

final class Entry
{
    public static function run(): void
    {
        Bridge::forward();
    }
}
