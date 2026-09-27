<?php

declare(strict_types=1);

use ByteKitsune\MagoArchitectureGraph\ArchitectureGraphExtension;
use Mago\Sdk\Worker;

require dirname(__DIR__) . '/source-autoload.php';

(new Worker(ArchitectureGraphExtension::create(__DIR__, __DIR__ . (getenv('ARCHITECTURE_SAFE') === '1' ? '/safe-policy.json' : '/policy.json'))))->run();
