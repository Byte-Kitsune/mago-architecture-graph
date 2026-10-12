<?php

declare(strict_types=1);

namespace App\Lobby;

use App\Workshop\Amber\Steps\EntryStep;

final class SideDoor
{
    public static function run(): void { EntryStep::same(); }
}
