<?php

namespace App\Shared;

final class Bridge
{
    public static function forward(): void
    {
        \App\Api\Gateway::expensive();
    }
}
