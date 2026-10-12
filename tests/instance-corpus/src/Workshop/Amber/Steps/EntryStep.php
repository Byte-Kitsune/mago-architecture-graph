<?php

declare(strict_types=1);

namespace App\Workshop\Amber\Steps;

use App\Workshop\Amber\Tools\EntryTool;
use App\Workshop\Cedar\Tools\EntryTool as ForeignTool;

final class EntryStep
{
    public static function same(): void { EntryTool::ping(); }
    public static function foreign(): void { ForeignTool::ping(); }
    public static function indirect(): void { RelayStep::forward(); }
    public static function rebinding(): void { \App\Workshop\Cedar\Steps\EntryStep::same(); }
}
