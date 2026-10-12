<?php

declare(strict_types=1);

namespace App\Workshop\Amber\Steps;

final class RelayStep
{
    public static function forward(): void { \App\Workshop\Cedar\Tools\EntryTool::ping(); }
}
