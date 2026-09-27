<?php

declare(strict_types=1);

namespace App\Controller;

use App\Service\Internal\Hidden;
use App\Service\ReportService;

final class ReportController
{
    public function show(): void
    {
        ReportService::run();
    }
}
