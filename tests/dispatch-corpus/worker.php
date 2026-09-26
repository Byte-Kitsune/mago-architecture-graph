<?php

declare(strict_types=1);

use ByteKitsune\MagoArchitectureGraph\ArchitectureGraphExtension;
use Mago\Sdk\Worker;

require dirname(__DIR__, 2) . '/vendor/autoload.php';

$policy = getenv('ARCHITECTURE_COMPLETE') === '1' ? 'complete-policy.json' : 'policy.json';

(new Worker(ArchitectureGraphExtension::create(__DIR__, __DIR__ . '/' . $policy, [
    'App\\Port' => 'App\\Gateway',
], serviceConfigurationComplete: getenv('ARCHITECTURE_SERVICE_INCOMPLETE') !== '1', serviceClassBindings: [
    'gateway.service' => 'App\\Gateway',
    'gateway.alias' => 'App\\Gateway',
    'App\\Gateway' => 'App\\Gateway',
])))->run();
