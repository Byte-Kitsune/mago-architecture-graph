<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph;

use InvalidArgumentException;

/** Explicit call reachability policy; unknown runtime dispatch stays incomplete. */
final class GraphPolicy
{
    public readonly bool $enabled;
    public readonly string $mode;
    public readonly int $maxDepth;
    public readonly string $sourceRoot;
    public readonly string $namespaceRoot;
    /** @var list<array<string, mixed>> */
    private readonly array $scopes;
    /** @var list<array<string, mixed>> */
    private readonly array $permissions;
    /** @var list<string> */
    private readonly array $exclusions;

    /** Inspection has no namespace/source-root filter and never enforces permissions.
     * @param list<string> $entrypointPaths
     */
    public static function inspection(array $entrypointPaths): self
    {
        return new self(null, $entrypointPaths);
    }

    public function __construct(mixed $value, ?array $inspectionPaths = null)
    {
        if ($inspectionPaths !== null) {
            if (!array_is_list($inspectionPaths) || count($inspectionPaths) > 128) throw new InvalidArgumentException('Invalid inspection entry paths.');
            $scopes = [];
            foreach (array_values(array_unique($inspectionPaths)) as $index => $path) {
                if (!self::path($path)) throw new InvalidArgumentException('Unsafe inspection entry path.');
                $scopes[] = ['id' => 'entry-' . $index, 'path_prefix' => str_ends_with($path, '.php') ? $path : rtrim($path, '/') . '/', 'namespace_prefix' => ''];
            }
            $this->enabled = true;
            $this->mode = 'transitive';
            $this->maxDepth = 64;
            $this->sourceRoot = $this->namespaceRoot = '';
            $this->scopes = $scopes;
            $this->permissions = $this->exclusions = [];
            return;
        }
        if (!is_array($value) || array_is_list($value) || array_diff(array_keys($value), ['version', 'enabled', 'mode', 'max_depth', 'source_root', 'namespace_root', 'scopes', 'method_permissions', 'unlisted_method_policy', 'exclusions']) !== [] || ($value['version'] ?? null) !== '1' || !is_bool($value['enabled'] ?? null) || !in_array($value['mode'] ?? null, ['direct', 'transitive'], true) || !is_int($value['max_depth'] ?? null) || $value['max_depth'] < 1 || $value['max_depth'] > 64 || !self::path($value['source_root'] ?? null) || !self::namespace($value['namespace_root'] ?? null) || !in_array($value['unlisted_method_policy'] ?? 'deny', ['allow', 'deny'], true)) throw new InvalidArgumentException('Invalid scope graph policy.');
        foreach (['scopes', 'method_permissions', 'exclusions'] as $key) if (isset($value[$key]) && (!is_array($value[$key]) || !array_is_list($value[$key]))) throw new InvalidArgumentException('Invalid scope graph list.');
        $scopes = $value['scopes'] ?? [];
        $permissions = $value['method_permissions'] ?? [];
        $exclusions = $value['exclusions'] ?? [];
        if (count($scopes) < 1 || count($scopes) > 128 || count($permissions) < 1 || count($permissions) > 512 || count($exclusions) > 128) throw new InvalidArgumentException('Scope graph policy exceeds configured bounds.');
        $ids = [];
        foreach ($scopes as $scope) {
            if (!is_array($scope) || array_is_list($scope) || array_diff(array_keys($scope), ['id', 'path_prefix', 'path_pattern', 'path_captures', 'namespace_prefix']) !== [] || !self::identifier($scope['id'] ?? null) || isset($ids[$scope['id']]) || !self::scopePath($scope) || !self::namespace($scope['namespace_prefix'] ?? null) || !self::within($scope['namespace_prefix'], $value['namespace_root'])) throw new InvalidArgumentException('Invalid or duplicate scope root.');
            $ids[$scope['id']] = $scope['path_captures'] ?? [];
        }
        foreach ($permissions as $permission) {
            if (!is_array($permission) || array_is_list($permission) || array_diff(array_keys($permission), ['scope_id', 'target_type', 'target_type_pattern', 'method', 'decision', 'policy_id', 'rationale']) !== [] || !isset($ids[$permission['scope_id'] ?? '']) || !self::targetSelector($permission, $ids[$permission['scope_id']] ?? []) || isset($permission['method']) && !self::identifier($permission['method']) || !in_array($permission['decision'] ?? null, ['allow', 'deny'], true) || !self::identifier($permission['policy_id'] ?? null) || !is_string($permission['rationale'] ?? null) || $permission['rationale'] === '' || strlen($permission['rationale']) > 256) throw new InvalidArgumentException('Invalid method permission.');
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
        foreach ($this->scopes as $scope) if (self::scopeMatch($scope, $path) !== null) return true;
        return false;
    }

    /** @return array<string, mixed>|null */
    public function scopeFor(string $path, string $class): ?array
    {
        foreach ($this->scopes as $scope) {
            $captures = self::scopeMatch($scope, $path);
            if ($captures !== null && self::within($class, $scope['namespace_prefix'])) return $captures === [] ? $scope : [...$scope, 'captures' => $captures];
        }
        return null;
    }

    /** Bind target selectors to the original entry path, never to a traversed target.
     * @param array<string, string> $captures
     * @return array<string, mixed>|null
     */
    public function permission(string $scope, string $symbol, array $captures = []): ?array
    {
        [$class, $method] = explode('::', $symbol, 2) + ['', ''];
        $best = null;
        $bestRank = null;
        foreach ($this->permissions as $candidate) {
            if ($candidate['scope_id'] !== $scope || isset($candidate['method']) && strcasecmp($candidate['method'], $method) !== 0) continue;
            $rank = self::targetMatch($candidate, $class, $captures);
            if ($rank === null) continue;
            // Preserve method specificity; then prefer literal segments over bound
            // captures over wildcards. A deny wins equal specificity in any order.
            $rank = [(int) isset($candidate['method']), ...$rank];
            if ($best === null || $rank > $bestRank || $rank === $bestRank && $candidate['decision'] === 'deny') {
                $best = $candidate;
                $bestRank = $rank;
            }
        }
        return $best === null || $captures === [] ? $best : [...$best, 'scope_captures' => $captures];
    }

    /** Literal prefixes retain their existing semantics. Patterns use whole segments. */
    private static function scopePath(array $scope): bool
    {
        if (isset($scope['path_prefix']) === isset($scope['path_pattern'])) return false;
        if (isset($scope['path_prefix'])) return !isset($scope['path_captures']) && self::path($scope['path_prefix']);
        $pattern = $scope['path_pattern'];
        if (!is_string($pattern) || strlen($pattern) > 2048 || preg_match('/[\x00-\x1f\x7f]/', $pattern) || !self::path(str_replace('*', 'segment', $pattern))) return false;
        $segments = explode('/', rtrim($pattern, '/'));
        foreach ($segments as $segment) if (str_contains($segment, '*') && $segment !== '*') return false;
        $count = count(array_filter($segments, static fn ($part) => $part === '*'));
        $names = $scope['path_captures'] ?? [];
        return $count <= 16 && is_array($names) && array_is_list($names) && count($names) <= 16 && ($names === [] || count($names) === $count) && count(array_unique($names, SORT_REGULAR)) === count($names) && array_filter($names, static fn ($name) => !self::identifier($name)) === [];
    }

    /** @return array<string, string>|null */
    private static function scopeMatch(array $scope, string $path): ?array
    {
        if (!self::path($path)) return null;
        if (isset($scope['path_prefix'])) return str_starts_with($path, $scope['path_prefix']) ? [] : null;
        $pattern = $scope['path_pattern'];
        $regex = str_replace('\\*', '([^/]+)', preg_quote($pattern, '~'));
        if (preg_match('~^' . $regex . (str_ends_with($pattern, '/') ? '' : '$') . '~D', $path, $matches) !== 1) return null;
        $result = [];
        foreach ($scope['path_captures'] ?? [] as $index => $name) $result[$name] = $matches[$index + 1];
        return $result;
    }

    private static function targetSelector(array $permission, array $captures): bool
    {
        if (isset($permission['target_type']) === isset($permission['target_type_pattern'])) return false;
        if (isset($permission['target_type'])) return self::namespace($permission['target_type']);
        $pattern = $permission['target_type_pattern'];
        if (!is_string($pattern) || strlen($pattern) > 2048 || !str_contains($pattern, '*') && !str_contains($pattern, '{')) return false;
        foreach (explode('\\', $pattern) as $part) {
            if ($part === '*') continue;
            if (preg_match('/^\{([A-Za-z_][A-Za-z0-9_-]*)\}$/D', $part, $match) === 1 && in_array($match[1], $captures, true)) continue;
            if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $part) !== 1) return false;
        }
        return true;
    }

    /** @return array{int, int}|null */
    private static function targetMatch(array $permission, string $class, array $captures): ?array
    {
        if (isset($permission['target_type'])) return strcasecmp($permission['target_type'], $class) === 0 ? [count(explode('\\', $class)), 0] : null;
        $pattern = explode('\\', $permission['target_type_pattern']);
        $actual = explode('\\', $class);
        if (count($pattern) !== count($actual)) return null;
        $literals = $bound = 0;
        foreach ($pattern as $index => $part) {
            if ($part === '*') {
                if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $actual[$index]) !== 1) return null;
                continue;
            }
            if (str_starts_with($part, '{')) {
                $value = $captures[substr($part, 1, -1)] ?? null;
                if (!is_string($value) || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/D', $value) !== 1 || strcasecmp($value, $actual[$index]) !== 0) return null;
                ++$bound;
            } else {
                if (strcasecmp($part, $actual[$index]) !== 0) return null;
                ++$literals;
            }
        }
        return [$literals, $bound];
    }

    public function withinRoot(string $class): bool { return self::within($class, $this->namespaceRoot); }

    private static function within(string $name, string $root): bool
    {
        $root = rtrim($root, '\\');
        return $root === '' || $name === $root || str_starts_with($name, $root . '\\');
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
