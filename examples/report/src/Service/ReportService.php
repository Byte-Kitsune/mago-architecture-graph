<?php

declare(strict_types=1);

namespace App\Service;

final class ReportService
{
    public static function run(): void
    {
        Gateway::expensive();
    }
}
