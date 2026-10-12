<?php

declare(strict_types=1);

namespace App\Workshop\Cedar\Steps;

use App\Workshop\Cedar\Tools\EntryTool;

final class EntryStep
{
    public static function same(): void { EntryTool::ping(); }
}
