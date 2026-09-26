<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph;

use ByteKitsune\MagoArchitectureGraph\Analyzer\ArchitecturePlugin;
use Mago\Sdk\Extension;

final class ArchitectureGraphExtension
{
    /**
     * The policy and literal service bindings are trusted operator inputs, never selected by analyzed PHP.
     * @param array<string, string> $classBindings Type or "Type $target" to concrete class.
     */
    public static function create(string $projectRoot, string $policyPath, array $classBindings = [], bool $serviceConfigurationComplete = true): Extension
    {
        $typeBindings = [];
        foreach ($classBindings as $type => $class) {
            if (!is_string($type) || !is_string($class)) throw new \InvalidArgumentException('Invalid architecture class binding.');
            $type = ltrim($type, '\\');
            // Symfony maps also contain ordinary service IDs; they cannot be PHP type hints.
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*(?: \$[A-Za-z_][A-Za-z0-9_]*)?$/D', $type)) continue;
            $class = ltrim($class, '\\');
            if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $class)) throw new \InvalidArgumentException('Invalid architecture class binding.');
            $normalizedType = strtolower($type);
            if (isset($typeBindings[$normalizedType]) && strcasecmp($typeBindings[$normalizedType], $class) !== 0) throw new \InvalidArgumentException('Conflicting architecture class bindings.');
            $typeBindings[$normalizedType] = $class;
        }
        if (count($typeBindings) > 32768) throw new \InvalidArgumentException('Architecture type bindings exceed 32768 entries.');
        return new Extension(
            identifier: 'byte-kitsune/architecture-graph',
            name: 'Path-aware architecture graph',
            version: '0.1.0-beta.8',
            analyzerPlugins: [new ArchitecturePlugin(new Policy($projectRoot, $policyPath), $typeBindings, $serviceConfigurationComplete)],
        );
    }
}
