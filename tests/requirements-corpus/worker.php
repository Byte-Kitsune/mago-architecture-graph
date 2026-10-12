<?php

declare(strict_types=1);
use ByteKitsune\MagoArchitectureGraph\ArchitectureGraphExtension;
use Mago\Sdk\Worker;
require getenv('NEUTRAL_AUTOLOAD') ?: dirname(__DIR__) . '/source-autoload.php';
(new Worker(ArchitectureGraphExtension::create(__DIR__, getenv('NEUTRAL_POLICY_PATH') ?: __DIR__ . '/policy.json')))->run();
