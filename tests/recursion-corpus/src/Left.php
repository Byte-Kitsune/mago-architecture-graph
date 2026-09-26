<?php

namespace App;

final class Left
{
    public static function go(): void { Right::go(); }
}
