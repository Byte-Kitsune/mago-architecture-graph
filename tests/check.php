<?php

declare(strict_types=1);

use ByteKitsune\MagoArchitectureGraph\Policy;

require dirname(__DIR__) . '/vendor/autoload.php';

$policy = new Policy(__DIR__ . '/corpus', __DIR__ . '/corpus/policy.json');
$sourcePath = 'src/Area/Alpha/Controller/OrderController.php';
$source = $policy->classify($sourcePath, 'App\Area\Alpha\Controller');
if ($source === null || $source['id'] !== 'area-controller') throw new RuntimeException('Source must match both path and namespace.');
if ($policy->classify($sourcePath, 'App\Wrong') !== null) throw new RuntimeException('Namespace mismatch was classified.');
if ($policy->relativePath('/outside/OrderController.php') !== null) throw new RuntimeException('Outside path was accepted.');
$same = $policy->classify('src/Area/Alpha/Service/OrderService.php', 'App\Area\Alpha\Service');
$hidden = $policy->classify('src/Area/Alpha/Service/Internal/HiddenRepository.php', 'App\Area\Alpha\Service\Internal');
$foreign = $policy->classify('src/Area/Beta/Service/OtherService.php', 'App\Area\Beta\Service');
if ($same === null || $hidden === null || $foreign === null) throw new RuntimeException('Target classification failed.');
if ($policy->violation($source, $same, $sourcePath, 'src/Area/Alpha/Service/OrderService.php') !== null) throw new RuntimeException('Valid entrypoint was rejected.');
if ($policy->violation($source, $hidden, $sourcePath, 'src/Area/Alpha/Service/Internal/HiddenRepository.php') !== 'forbidden-internal-access') throw new RuntimeException('Hidden service was allowed.');
if ($policy->violation($source, $foreign, $sourcePath, 'src/Area/Beta/Service/OtherService.php') !== 'foreign-module-instance') throw new RuntimeException('Foreign instance was allowed.');
if ($policy->targetPath('App\Area\Alpha\Service\OrderService') !== 'src/Area/Alpha/Service/OrderService.php') throw new RuntimeException('Target path mapping failed.');
echo "Policy checks passed\n";
