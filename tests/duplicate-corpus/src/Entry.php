<?php

namespace App;

final class Entry
{
    public static function direct(): void { Gateway::run(); }
    public static function throughStage(): void { Stage::run(); }
    public static function throughFunction(): void { duplicateHelper(); }
}
