<?php

declare(strict_types=1);

use ByteKitsune\MagoArchitectureGraph\ArchitectureGraphExtension;
use Mago\Sdk\Worker;

require dirname(__DIR__) . '/source-autoload.php';

$output = getenv('MAGO_GRAPH_SNAPSHOT');
if ($output === false || $output === '') throw new RuntimeException('A test snapshot destination is required.');
(new Worker(ArchitectureGraphExtension::inspect(
    __DIR__,
    ['app/Http'],
    static function (array $snapshot) use ($output): void {
        file_put_contents($output, json_encode($snapshot, JSON_THROW_ON_ERROR));
    },
)))->run();
