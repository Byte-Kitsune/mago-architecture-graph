<?php

namespace App\Shared;

final class Bridge
{
    public static function forward(): void
    {
        \App\Api\Gateway::expensive();
        \App\Shared\Missing::run();
    }

    public static function unrelated(): void
    {
        $target = self::class;
        $target::forward();
    }
}
