<?php

namespace App\Area\Alpha\Controller;

use App\Area\Alpha\Service\OrderService;
use App\Area\Alpha\Service\Internal\HiddenRepository;
use App\Area\Beta\Service\OtherService;

final class OrderController
{
    public function __construct(OrderService $service) {}
}
