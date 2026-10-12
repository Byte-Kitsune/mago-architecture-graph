<?php

declare(strict_types=1);

namespace App\Lobby;

use App\Workshop\Amber\Gate\HiddenGate;

final class Shortcut
{
    public static function run(): void { HiddenGate::open(); }
}
