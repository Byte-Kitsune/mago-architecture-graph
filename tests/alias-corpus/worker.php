<?php

declare(strict_types=1);

use ByteKitsune\MagoArchitectureGraph\ArchitectureGraphExtension;
use Mago\Sdk\Worker;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

(new Worker(ArchitectureGraphExtension::create(__DIR__, __DIR__ . '/policy.json', [
    'App\\Port' => 'App\\Gateway',
    'App\\Port $admin' => 'App\\AdminGateway',
], getenv('ARCHITECTURE_CONFIG_INCOMPLETE') !== '1')))->run();
