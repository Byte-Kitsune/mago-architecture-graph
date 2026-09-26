<?php

namespace App;

final class Counter
{
    public static function walk(int $remaining): void
    {
        if ($remaining <= 0) return;
        self::walk($remaining - 1);
    }
}
