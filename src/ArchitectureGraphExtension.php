<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph;

use ByteKitsune\MagoArchitectureGraph\Analyzer\ArchitecturePlugin;
use Mago\Sdk\Extension;

final class ArchitectureGraphExtension
{
    public const VERSION = '1.4.0-RC1';
    /** Name-resolved hook candidates from the exact snapshot; declarations do not prove activation. */
    public static function inspectFrameworkHooks(\Mago\Sdk\Analyzer\ProjectAnalysis $analysis): array
    {
        if (!class_exists(\ByteKitsune\MagoNative\Engine::class, false)) require_once __DIR__ . '/NativeEngine.php';
        if (!class_exists(\ByteKitsune\MagoNative\Parser::class, false)) require_once __DIR__ . '/NativeParser.php';
        if (!class_exists(\ByteKitsune\MagoNative\FrameworkHooks::class, false)) require_once __DIR__ . '/FrameworkHooks.php';
        $sources = [];
        foreach ($analysis->files as $file) if (str_ends_with($file->file, '.php')) $sources[$file->file] = $file->getSourceFile()->contents;
        return \ByteKitsune\MagoNative\FrameworkHooks::discover($sources);
    }

    /**
     * The policy and literal service bindings are trusted operator inputs, never selected by analyzed PHP.
     * @param \Closure(array<string, mixed>): void|null $graphObserver Optional complete source graph export; independent of policy findings.
     * @param array<string, string> $classBindings Type or "Type $target" to concrete class.
     * @param array<string, string> $serviceClassBindings Exact service ID to declared class.
     * @param list<array{marker: string, severity: string}>|null $commentMarkers Null uses defaults, [] disables declaration metadata.
     * @param array<string, array<int, ?string>> $serviceConstructorBindings Service ID to positional target service IDs; null is explicitly unresolved.
     */
    public static function create(string $projectRoot, string $policyPath, array $classBindings = [], bool $serviceConfigurationComplete = true, array $serviceClassBindings = [], array $serviceConstructorBindings = [], ?\Closure $graphObserver = null, ?array $commentMarkers = null): Extension
    {
        return self::build(new Policy($projectRoot, $policyPath), $classBindings, $serviceConfigurationComplete, $serviceClassBindings, $serviceConstructorBindings, $graphObserver, $commentMarkers);
    }

    /** Export the complete configured Mago source set, without enforcing a policy.
     * @param list<string> $entrypointPaths Relative entry directories or PHP files.
     * @param \Closure(array<string, mixed>): void $graphObserver
     * @param array<string, string> $classBindings
     * @param array<string, string> $serviceClassBindings
     * @param list<array{marker: string, severity: string}>|null $commentMarkers Null uses defaults, [] disables declaration metadata.
     * @param array<string, array<int, ?string>> $serviceConstructorBindings
     */
    public static function inspect(string $projectRoot, array $entrypointPaths, \Closure $graphObserver, array $classBindings = [], bool $serviceConfigurationComplete = true, array $serviceClassBindings = [], array $serviceConstructorBindings = [], ?array $commentMarkers = null): Extension
    {
        return self::build(Policy::inspection($projectRoot, $entrypointPaths), $classBindings, $serviceConfigurationComplete, $serviceClassBindings, $serviceConstructorBindings, $graphObserver, $commentMarkers);
    }

    private static function build(Policy $policy, array $classBindings, bool $serviceConfigurationComplete, array $serviceClassBindings, array $serviceConstructorBindings, ?\Closure $graphObserver, ?array $commentMarkers): Extension
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
        if (count($serviceClassBindings) > 32768) throw new \InvalidArgumentException('Architecture service bindings exceed 32768 entries.');
        foreach ($serviceClassBindings as $id => $class) {
            $idText = (string) $id;
            if ($idText === '' || strlen($idText) > 512 || preg_match('/[\x00-\x1f\x7f]/', $idText)
                || !is_string($class) || !preg_match('/^\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*$/D', $class)) {
                throw new \InvalidArgumentException('Invalid architecture service binding.');
            }
            $serviceClassBindings[$id] = ltrim($class, '\\');
        }
        if (count($serviceConstructorBindings) > 32768) throw new \InvalidArgumentException('Architecture constructor bindings exceed 32768 services.');
        $positionsTotal = 0;
        foreach ($serviceConstructorBindings as $id => $positions) {
            if (!is_string($id) || !isset($serviceClassBindings[$id]) || !is_array($positions) || count($positions) > 128) throw new \InvalidArgumentException('Invalid architecture constructor service.');
            foreach ($positions as $position => $target) {
                if (!is_int($position) || $position < 0 || $position >= 128 || ($target !== null && (!is_string($target) || !isset($serviceClassBindings[$target])))) throw new \InvalidArgumentException('Invalid architecture constructor target.');
                if (++$positionsTotal > 262144) throw new \InvalidArgumentException('Architecture constructor bindings exceed work limit.');
            }
            ksort($serviceConstructorBindings[$id], SORT_NUMERIC);
        }
        return new Extension(
            identifier: 'byte-kitsune/architecture-graph',
            name: 'Path-aware architecture graph',
            version: self::VERSION,
            analyzerPlugins: [new ArchitecturePlugin($policy, $typeBindings, $serviceConfigurationComplete, $serviceClassBindings, $serviceConstructorBindings, $graphObserver, \ByteKitsune\MagoArchitectureGraph\Analyzer\CommentMetadata::validateMarkers($commentMarkers))],
        );
    }
}
