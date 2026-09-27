<?php

namespace App;

final class Gateway implements Port
{
    public function expensive(): void {}

    public function expensiveWith(string $text): void {}

    public function accept(Gateway $other): void {}

    public static function staticExpensive(): void {}
}
