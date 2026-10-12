<?php

declare(strict_types=1);

namespace App\Visitors;

use App\Workshop\Amber\Gate\EntryGate;

final class Uninvited
{
    public static function run(): void { EntryGate::open(); }
}
