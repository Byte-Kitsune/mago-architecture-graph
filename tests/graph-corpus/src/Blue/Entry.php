<?php

namespace App\Blue;

final class Entry
{
    public static function run(): void
    {
        \App\Shared\Bridge::forward();
    }
}
