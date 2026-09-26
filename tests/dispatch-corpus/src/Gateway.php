<?php

namespace App;

final class Gateway implements Port
{
    public function expensive(): void {}

    public static function staticExpensive(): void {}
}
