<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph;

use InvalidArgumentException;

/** Explicit static-call reachability policy; no inferred runtime dispatch. */
final class GraphPolicy
{
    public readonly bool $enabled;
    public readonly string $mode;
    public readonly int $maxDepth;
    public readonly string $sourceRoot;
    public readonly string $namespaceRoot;
    /** @var list<array{id: string, path_prefix: string, namespace_prefix: string}> */
    private readonly array $scopes;
    /** @var list<array{scope_id: string, target_type: string, method?: string, decision: string, policy_id: string, rationale: string}> */
    private readonly array $permissions;
    /** @var list<string> */
    private readonly array $exclusions;

    public function __construct(mixed $value)
    {
        if (!is_array($value) || array_is_list($value) || array_diff(array_keys($value), ['version', 'enabled', 'mode', 'max_depth', 'source_root', 'namespace_root', 'scopes', 'method_permissions', 'unlisted_method_policy', 'exclusions']) !== [] || ($value['version'] ?? null) !== '1' || !is_bool($value['enabled'] ?? null) || !in_array($value['mode'] ?? null, ['direct', 'transitive'], true) || !is_int($value['max_depth'] ?? null) || $value['max_depth'] < 1 || $value['max_depth'] > 64 || !self::path($value['source_root'] ?? null) || !self::namespace($value['namespace_root'] ?? null) || !in_array($value['unlisted_method_policy'] ?? 'deny', ['allow', 'deny'], true)) throw new InvalidArgumentException('Invalid scope graph policy.');
        foreach (['scopes', 'method_permissions', 'exclusions'] as $key) if (isset($value[$key]) && (!is_array($value[$key]) || !array_is_list($value[$key]))) throw new InvalidArgumentException('Invalid scope graph list.');
        $scopes = $value['scopes'] ?? [];
        $permissions = $value['method_permissions'] ?? [];
        $exclusions = $value['exclusions'] ?? [];
        if (count($scopes) < 1 || count($scopes) > 128 || count($permissions) < 1 || count($permissions) > 512 || count($exclusions) > 128) throw new InvalidArgumentException('Scope graph policy exceeds configured bounds.');
        $ids = [];
        foreach ($scopes as $scope) {
            if (!is_array($scope) || array_is_list($scope) || array_diff(array_keys($scope), ['id', 'path_prefix', 'namespace_prefix']) !== [] || !self::identifier($scope['id'] ?? null) || isset($ids[$scope['id']]) || !self::path($scope['path_prefix'] ?? null) || !self::namespace($scope['namespace_prefix'] ?? null) || !self::within($scope['namespace_prefix'], $value['namespace_root'])) throw new InvalidArgumentException('Invalid or duplicate scope root.');
            $ids[$scope['id']] = true;
        }
        foreach ($permissions as $permission) {
            if (!is_array($permission) || array_is_list($permission) || array_diff(array_keys($permission), ['scope_id', 'target_type', 'method', 'decision', 'policy_id', 'rationale']) !== [] || !isset($ids[$permission['scope_id'] ?? '']) || !self::namespace($permission['target_type'] ?? null) || isset($permission['method']) && !self::identifier($permission['method']) || !in_array($permission['decision'] ?? null, ['allow', 'deny'], true) || !self::identifier($permission['policy_id'] ?? null) || !is_string($permission['rationale'] ?? null) || $permission['rationale'] === '' || strlen($permission['rationale']) > 256) throw new InvalidArgumentException('Invalid method permission.');
        }
        foreach ($exclusions as $path) if (!self::path($path)) throw new InvalidArgumentException('Invalid graph exclusion.');
        $this->enabled = $value['enabled'];
        $this->mode = $value['mode'];
        $this->maxDepth = $value['max_depth'];
        $this->sourceRoot = rtrim($value['source_root'], '/');
        $this->namespaceRoot = rtrim($value['namespace_root'], '\\');
        $this->scopes = $scopes;
        $this->permissions = $permissions;
        $this->exclusions = $exclusions;
    }

    public function excluded(string $path): bool
    {
        foreach ($this->exclusions as $prefix) if (str_starts_with($path, $prefix)) return true;
        return false;
    }

    public function possibleScopePath(string $path): bool
    {
        foreach ($this->scopes as $scope) if (str_starts_with($path, $scope['path_prefix'])) return true;
        return false;
    }

    /** @return array{id: string, path_prefix: string, namespace_prefix: string}|null */
    public function scopeFor(string $path, string $class): ?array
    {
        foreach ($this->scopes as $scope) if (str_starts_with($path, $scope['path_prefix']) && self::within($class, $scope['namespace_prefix'])) return $scope;
        return null;
    }

    /** @return array{scope_id: string, target_type: string, method?: string, decision: string, policy_id: string, rationale: string}|null */
    public function permission(string $scope, string $symbol): ?array
    {
        [$class, $method] = explode('::', $symbol, 2) + ['', ''];
        $best = null;
        foreach ($this->permissions as $candidate) {
            if ($candidate['scope_id'] !== $scope || strcasecmp($candidate['target_type'], $class) !== 0 || isset($candidate['method']) && strcasecmp($candidate['method'], $method) !== 0) continue;
            if ($best === null || (int) isset($candidate['method']) > (int) isset($best['method']) || isset($candidate['method']) === isset($best['method']) && $candidate['decision'] === 'deny') $best = $candidate;
        }
        return $best;
    }

    public function withinRoot(string $class): bool { return self::within($class, $this->namespaceRoot); }

    private static function within(string $name, string $root): bool
    {
        $root = rtrim($root, '\\');
        return $name === $root || str_starts_with($name, $root . '\\');
    }

    private static function path(mixed $path): bool
    {
        return is_string($path) && $path !== '' && !str_starts_with($path, '/') && !str_contains($path, '\\') && !str_contains($path, '*') && !str_contains($path, "\0") && array_filter(explode('/', rtrim($path, '/')), fn ($part) => $part === '' || $part === '.' || $part === '..') === [];
    }

    private static function namespace(mixed $name): bool
    {
        return is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\\\\?$/D', $name) === 1;
    }

    private static function identifier(mixed $name): bool
    {
        return is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_-]*$/D', $name) === 1;
    }
}
