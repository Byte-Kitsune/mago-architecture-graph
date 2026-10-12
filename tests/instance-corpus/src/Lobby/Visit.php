<?php

declare(strict_types=1);

namespace App\Lobby;

use App\Workshop\Amber\Gate\EntryGate as AmberGate;
use App\Workshop\Cedar\Gate\EntryGate as CedarGate;

final class Visit
{
    public static function run(): void { AmberGate::open(); CedarGate::open(); }
}
