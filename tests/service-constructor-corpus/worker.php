<?php

declare(strict_types=1);

use ByteKitsune\MagoArchitectureGraph\ArchitectureGraphExtension;
use Mago\Sdk\Worker;

require dirname(__DIR__) . '/source-autoload.php';

(new Worker(ArchitectureGraphExtension::create(
    __DIR__,
    __DIR__ . '/policy.json',
    serviceClassBindings: [
        'processor.safe' => 'App\\Processor',
        'processor.danger' => 'App\\Processor',
        'processor.alias' => 'App\\Processor',
        'safe.port' => 'App\\SafePort',
        'danger.port' => 'App\\DangerPort',
    ],
    serviceConstructorBindings: [
        'processor.safe' => [0 => 'safe.port'],
        'processor.danger' => [0 => 'danger.port'],
        'processor.alias' => [0 => 'danger.port'],
    ],
)))->run();
