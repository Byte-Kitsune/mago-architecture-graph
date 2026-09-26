<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph;

use InvalidArgumentException;

/** Literal, bounded module policy. Source path and parsed namespace must both match. */
final class Policy
{
    /** @var list<array<string, mixed>> */
    private readonly array $modules;
    private readonly string $sourceRoot;
    private readonly string $namespaceRoot;
    public readonly string $projectRoot;

    public function __construct(string $projectRoot, string $policyPath)
    {
        $root = realpath($projectRoot);
        if ($root === false || !is_dir($root)) throw new InvalidArgumentException('Project root is unavailable.');
        $this->projectRoot = $root;
        if (!is_file($policyPath) || is_link($policyPath) || filesize($policyPath) > 1024 * 1024) throw new InvalidArgumentException('Architecture policy must be a regular file of at most 1 MiB.');
        $bytes = file_get_contents($policyPath);
        if ($bytes === false) throw new InvalidArgumentException('Cannot read architecture policy.');
        $data = json_decode($bytes, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($data) || array_is_list($data) || array_diff(array_keys($data), ['version', 'source_root', 'namespace_root', 'modules']) !== [] || ($data['version'] ?? null) !== '1' || !self::safePath($data['source_root'] ?? null) || !self::namespace($data['namespace_root'] ?? null)) throw new InvalidArgumentException('Invalid architecture policy header.');
        if (!isset($data['modules']) || !is_array($data['modules']) || !array_is_list($data['modules']) || count($data['modules']) < 1 || count($data['modules']) > 128) throw new InvalidArgumentException('Architecture policy requires 1..128 ordered modules.');
        $ids = [];
        foreach ($data['modules'] as $module) {
            if (!is_array($module) || array_is_list($module) || array_diff(array_keys($module), ['id', 'path_pattern', 'namespace_prefix', 'layer', 'allowed_target_layers', 'entrypoint_suffix', 'entrypoint_path_pattern', 'forbid_peer_entrypoints', 'module_family', 'module_family_captures', 'allowed_family_bindings', 'excluded']) !== []) throw new InvalidArgumentException('Invalid architecture module shape.');
            if (!self::identifier($module['id'] ?? null) || isset($ids[$module['id']]) || !self::safePath($module['path_pattern'] ?? null, true) || !self::namespace($module['namespace_prefix'] ?? null) || !self::identifier($module['layer'] ?? null) || !self::identifier($module['entrypoint_suffix'] ?? null, true)) throw new InvalidArgumentException('Invalid or duplicate architecture module.');
            if (!($module['excluded'] ?? false) && !str_starts_with(rtrim($module['namespace_prefix'], '\\') . '\\', rtrim($data['namespace_root'], '\\') . '\\')) throw new InvalidArgumentException('Module namespace must be within the configured root.');
            if (!isset($module['allowed_target_layers']) || !is_array($module['allowed_target_layers']) || !array_is_list($module['allowed_target_layers']) || count($module['allowed_target_layers']) > 128 || array_filter($module['allowed_target_layers'], fn ($v) => !self::identifier($v)) !== []) throw new InvalidArgumentException('Invalid allowed target layers.');
            if (isset($module['entrypoint_path_pattern']) && !self::safePath($module['entrypoint_path_pattern'], true)) throw new InvalidArgumentException('Invalid entrypoint path pattern.');
            foreach (['forbid_peer_entrypoints', 'excluded'] as $flag) if (isset($module[$flag]) && !is_bool($module[$flag])) throw new InvalidArgumentException('Invalid module flag.');
            $family = $module['module_family'] ?? null;
            $wildcards = count(array_filter(explode('/', trim($module['path_pattern'], '/')), fn ($segment) => $segment === '*'));
            $captures = $module['module_family_captures'] ?? ($family === null ? [] : ['instance']);
            if ($family !== null && (!self::identifier($family) || !$wildcards || !is_array($captures) || !array_is_list($captures) || count($captures) !== $wildcards || count(array_unique($captures)) !== count($captures) || array_filter($captures, fn ($v) => !self::identifier($v)) !== [])) throw new InvalidArgumentException('Invalid module-family captures.');
            if ($family === null && ($captures !== [] || !empty($module['allowed_family_bindings']))) throw new InvalidArgumentException('Module-family bindings require a family.');
            if (isset($module['allowed_family_bindings']) && (!is_array($module['allowed_family_bindings']) || !array_is_list($module['allowed_family_bindings']) || count($module['allowed_family_bindings']) > 64)) throw new InvalidArgumentException('Invalid module-family binding list.');
            foreach ($module['allowed_family_bindings'] ?? [] as $binding) {
                if (!is_array($binding) || array_diff(array_keys($binding), ['target_family', 'target_layer', 'source_capture', 'target_capture']) !== [] || !self::identifier($binding['target_family'] ?? null) || !self::identifier($binding['target_layer'] ?? null) || !in_array($binding['source_capture'] ?? null, $captures, true) || !self::identifier($binding['target_capture'] ?? null)) throw new InvalidArgumentException('Invalid module-family binding.');
            }
            $ids[$module['id']] = true;
        }
        $layers = array_unique(array_column($data['modules'], 'layer'));
        foreach ($data['modules'] as $module) {
            foreach ($module['allowed_target_layers'] as $targetLayer) if (!in_array($targetLayer, $layers, true)) throw new InvalidArgumentException('Unknown target layer.');
            foreach ($module['allowed_family_bindings'] ?? [] as $binding) {
                $exists = false;
                foreach ($data['modules'] as $target) {
                    $targetCaptures = $target['module_family_captures'] ?? ['instance'];
                    if (($target['module_family'] ?? null) === $binding['target_family'] && $target['layer'] === $binding['target_layer'] && in_array($binding['target_capture'], $targetCaptures, true)) $exists = true;
                }
                if (!$exists) throw new InvalidArgumentException('Module-family binding has no target.');
            }
        }
        $this->sourceRoot = rtrim($data['source_root'], '/');
        $this->namespaceRoot = rtrim($data['namespace_root'], '\\');
        $this->modules = $data['modules'];
    }

    public function relativePath(string $file): ?string
    {
        $prefix = $this->projectRoot . '/';
        $relative = str_starts_with($file, $prefix) ? substr($file, strlen($prefix)) : $file;
        return self::safePath($relative) ? $relative : null;
    }

    public function targetPath(string $name): ?string
    {
        $prefix = $this->namespaceRoot . '\\';
        if (!str_starts_with($name, $prefix) || !self::namespace($name)) return null;
        return $this->sourceRoot . '/' . str_replace('\\', '/', substr($name, strlen($prefix))) . '.php';
    }

    /** @return array<string, mixed>|null */
    public function classify(string $path, string $namespace): ?array
    {
        if (!self::safePath($path) || !self::namespace($namespace)) return null;
        foreach ($this->modules as $module) if (($module['excluded'] ?? false) && $this->matches($module, $path, $namespace)) return null;
        foreach ($this->modules as $module) if ($this->matches($module, $path, $namespace)) return $module;
        return null;
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $target */
    public function violation(array $source, array $target, string $sourcePath, string $targetPath): ?string
    {
        if (!$this->sameInstance($source, $target, $sourcePath, $targetPath)) return 'foreign-module-instance';
        if ($source['layer'] === $target['layer']) return ($source['forbid_peer_entrypoints'] ?? false) && $this->entrypoint($target, $targetPath) ? 'no-peer-entrypoint' : null;
        if (!in_array($target['layer'], $source['allowed_target_layers'], true)) return 'disallowed-layer';
        return $this->entrypoint($target, $targetPath) ? null : 'forbidden-internal-access';
    }

    /** @param array<string, mixed> $module */
    private function matches(array $module, string $path, string $namespace): bool
    {
        $prefix = rtrim($module['namespace_prefix'], '\\');
        return self::pathMatches($module['path_pattern'], $path) && ($namespace === $prefix || str_starts_with($namespace, $prefix . '\\'));
    }

    /** @param array<string, mixed> $module */
    private function entrypoint(array $module, string $path): bool
    {
        $suffix = $module['entrypoint_suffix'];
        return ($suffix === '' || str_ends_with(basename($path), $suffix . '.php')) && (!isset($module['entrypoint_path_pattern']) || self::pathMatches($module['entrypoint_path_pattern'], $path));
    }

    /** @param array<string, mixed> $source @param array<string, mixed> $target */
    private function sameInstance(array $source, array $target, string $sourcePath, string $targetPath): bool
    {
        $from = $source['module_family'] ?? null;
        $to = $target['module_family'] ?? null;
        if ($to === null) return true;
        if ($from === null) return false;
        $fromCaptures = self::captures($source, $sourcePath);
        $toCaptures = self::captures($target, $targetPath);
        if ($from === $to && $fromCaptures === $toCaptures) return true;
        foreach ($source['allowed_family_bindings'] ?? [] as $binding) {
            if ($binding['target_family'] === $to && $binding['target_layer'] === $target['layer'] && isset($fromCaptures[$binding['source_capture']], $toCaptures[$binding['target_capture']]) && $fromCaptures[$binding['source_capture']] === $toCaptures[$binding['target_capture']]) return true;
        }
        return false;
    }

    /** @param array<string, mixed> $module @return array<string, string> */
    private static function captures(array $module, string $path): array
    {
        $names = $module['module_family_captures'] ?? ['instance'];
        $parts = explode('/', trim($module['path_pattern'], '/'));
        $actual = explode('/', $path);
        $result = [];
        foreach ($parts as $index => $part) if ($part === '*' && isset($names[count($result)], $actual[$index])) $result[$names[count($result)]] = $actual[$index];
        return $result;
    }

    private static function pathMatches(string $pattern, string $path): bool
    {
        $regex = str_replace('\\*', '[^/]+', preg_quote($pattern, '~'));
        return preg_match('~^' . $regex . (str_ends_with($pattern, '/') ? '' : '$') . '~D', $path) === 1;
    }

    private static function safePath(mixed $value, bool $pattern = false): bool
    {
        if (!is_string($value) || $value === '' || str_starts_with($value, '/') || str_contains($value, '\\') || preg_match('/[\x00-\x1f\x7f]/', $value)) return false;
        foreach (explode('/', rtrim($value, '/')) as $part) if ($part === '' || $part === '.' || $part === '..' || (!$pattern && str_contains($part, '*')) || ($pattern && substr_count($part, '*') > 1)) return false;
        return true;
    }

    private static function namespace(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\?$/D', $value) === 1;
    }

    private static function identifier(mixed $value, bool $empty = false): bool
    {
        return is_string($value) && ($empty && $value === '' || preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $value) === 1);
    }
}
