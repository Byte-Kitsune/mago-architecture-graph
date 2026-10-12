<?php

declare(strict_types=1);

$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';

// Exercise this checkout, even when Composer also has a published beta installed.
spl_autoload_register(static function (string $class) use ($root): void {
    $prefix = 'ByteKitsune\\MagoArchitectureGraph\\';
    if (!str_starts_with($class, $prefix)) return;
    $path = $root . '/src/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
    if (is_file($path)) require $path;
}, true, true);

if (!class_exists(\ByteKitsune\MagoNative\Engine::class, false)) require_once $root . '/src/NativeEngine.php';
