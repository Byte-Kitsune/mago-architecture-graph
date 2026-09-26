<?php

namespace App;

final class Entry
{
    public static function run(): void
    {
        Loop::spin();
        Counter::walk(3);
        Left::go();
    }
}
