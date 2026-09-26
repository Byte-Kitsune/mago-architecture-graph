<?php

namespace App\Shared;

final class Incomplete
{
    public static function run(): void
    {
        \App\Shared\Missing::run();
    }
}
