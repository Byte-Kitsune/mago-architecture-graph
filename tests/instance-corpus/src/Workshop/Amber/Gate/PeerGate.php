<?php

declare(strict_types=1);

namespace App\Workshop\Amber\Gate;

use App\Workshop\Amber\Gate\EntryGate;

final class PeerGate
{
    public static function run(): void { EntryGate::open(); }
}
