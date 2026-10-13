<?php

declare(strict_types=1);
namespace ByteKitsune\MagoArchitectureGraph\Analyzer;
use PhpParser\Node;

/** Follow literal eager callbacks; deferred/dynamic dispatch keeps explicit gaps. */
final class CallCollector
{
    /** @param list<Node\Stmt> $statements @return array{list<Node\Expr\StaticCall>,list<Node\Expr\MethodCall>,list<Node\Expr\FuncCall>,list<Node\Expr\New_>,list<Node>} */
    public static function collect(array $statements): array
    {
        $static = $instance = $functions = $constructors = $unknown = [];
        $stack = array_reverse($statements);
        while ($stack !== []) {
            $node = array_pop($stack);
            if (!$node instanceof Node) continue;
            if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\Class_) {
                $unknown[] = $node; continue;
            }
            if ($node instanceof Node\Expr\FuncCall && !$node->isFirstClassCallable()) {
                if ($node->name instanceof Node\Expr\Closure || $node->name instanceof Node\Expr\ArrowFunction) {
                    self::pushCallback($node->name, $stack, $unknown);
                    foreach (array_reverse($node->args) as $arg) $stack[] = $arg;
                    continue;
                }
                $callbacks = self::eagerCallbacks($node);
                if ($callbacks !== null) {
                    foreach ($callbacks as $callback) self::pushCallback($callback, $stack, $unknown);
                    // Arguments still have creation/receiver side effects. Skip only the literal callback itself.
                    foreach (array_reverse($node->args) as $arg) {
                        if (in_array($arg->value, $callbacks, true)) continue;
                        if ($arg->value instanceof Node\Expr\Array_ && $node->name instanceof Node\Name && strtolower($node->name->toString()) === 'preg_replace_callback_array') {
                            $covered = true;
                            foreach ($arg->value->items as $item) if ($item === null || !in_array($item->value, $callbacks, true)) $covered = false;
                            if ($covered) continue;
                        }
                        $stack[] = $arg;
                    }
                    continue;
                }
            }
            if ($node instanceof Node\Expr\StaticCall) {
                if ($node->isFirstClassCallable()) $unknown[] = $node; else $static[] = $node;
            } elseif ($node instanceof Node\Expr\MethodCall) {
                if ($node->isFirstClassCallable()) $unknown[] = $node; else $instance[] = $node;
            } elseif ($node instanceof Node\Expr\FuncCall) {
                if ($node->isFirstClassCallable()) $unknown[] = $node; else $functions[] = $node;
            } elseif ($node instanceof Node\Expr\New_) $constructors[] = $node;
            elseif ($node instanceof Node\Expr\NullsafeMethodCall) $unknown[] = $node;
            foreach (array_reverse($node->getSubNodeNames()) as $field) {
                $value = $node->$field;
                if ($value instanceof Node) $stack[] = $value;
                elseif (is_array($value)) foreach (array_reverse($value) as $child) if ($child instanceof Node) $stack[] = $child;
            }
        }
        return [$static, $instance, $functions, $constructors, $unknown];
    }
    private static function eagerCallbacks(Node\Expr\FuncCall $call): ?array
    {
        if (!$call->name instanceof Node\Name\FullyQualified) return null;
        $name = strtolower($call->name->toString());
        if (in_array($name, ['array_udiff', 'array_udiff_assoc', 'array_diff_uassoc', 'array_diff_ukey', 'array_intersect_uassoc', 'array_intersect_ukey', 'array_uintersect', 'array_uintersect_assoc', 'array_udiff_uassoc', 'array_uintersect_uassoc'], true)) {
            foreach ($call->args as $arg) if (!$arg instanceof Node\Arg || $arg->unpack || $arg->name !== null) return null;
            $count = in_array($name, ['array_udiff_uassoc', 'array_uintersect_uassoc'], true) ? 2 : 1;
            if (count($call->args) < $count + 2) return null;
            return array_map(static fn(Node\Arg $arg): Node\Expr => $arg->value, array_slice($call->args, -$count));
        }
        if ($name === 'preg_replace_callback_array') {
            $mapping = null;
            foreach ($call->args as $index => $arg) {
                if (!$arg instanceof Node\Arg || $arg->unpack) return null;
                if ($arg->name?->toString() === 'pattern' || $arg->name === null && $index === 0) $mapping = $arg->value;
            }
            if (!$mapping instanceof Node\Expr\Array_) return null;
            $callbacks = [];
            foreach ($mapping->items as $item) {
                if ($item === null || $item->unpack || !$item->key instanceof Node\Scalar\String_) return null;
                $callbacks[] = $item->value;
            }
            return $callbacks;
        }
        $position = match ($name) {
            'array_map', 'call_user_func', 'call_user_func_array' => 0,
            'array_filter', 'array_walk', 'array_walk_recursive', 'array_reduce', 'array_any', 'array_all', 'array_find', 'array_find_key', 'usort', 'uasort', 'uksort', 'preg_replace_callback', 'iterator_apply' => 1,
            default => null,
        };
        if ($position === null) return null;
        $parameter = 'callback';
        $target = null; $array = null;
        foreach ($call->args as $index => $arg) {
            if (!$arg instanceof Node\Arg || $arg->unpack) return null;
            if ($arg->name?->toString() === $parameter || $arg->name === null && $index === $position) $target = $arg->value;
            $arrayIndex = $name === 'array_map' ? 1 : 0;
            if ($arg->name?->toString() === 'array' || $arg->name === null && $index === $arrayIndex) $array = $arg->value;
        }
        if ($target === null) return $name === 'array_filter' ? [] : null;
        if (in_array($name, ['array_map', 'array_filter', 'array_walk', 'array_walk_recursive', 'array_reduce', 'array_any', 'array_all', 'array_find', 'array_find_key', 'usort', 'uasort', 'uksort'], true)
            && $array instanceof Node\Expr\Array_ && $array->items === [] && ($name !== 'array_map' || count($call->args) === 2)) return [];
        if ($name === 'array_map' && $target instanceof Node\Expr\ConstFetch && strtolower($target->name->toString()) === 'null') return [];
        return [$target];
    }
    private static function pushCallback(Node\Expr $callback, array &$stack, array &$unknown): void
    {
        if (($callback instanceof Node\Expr\ArrowFunction || $callback instanceof Node\Expr\Closure) && self::hasYield($callback instanceof Node\Expr\ArrowFunction ? [$callback->expr] : $callback->stmts)) { $unknown[] = $callback; return; }
        if ($callback instanceof Node\Expr\ArrowFunction) { $stack[] = $callback->expr; return; }
        if ($callback instanceof Node\Expr\Closure) {
            foreach ($callback->uses as $use) if ($use->byRef) $unknown[] = $callback;
            foreach (array_reverse($callback->stmts) as $stmt) $stack[] = $stmt;
            return;
        }
        if ($callback instanceof Node\Expr\StaticCall && $callback->isFirstClassCallable()) {
            $stack[] = new Node\Expr\StaticCall($callback->class, $callback->name, [], array_merge($callback->getAttributes(), ['eagerCallback' => true])); return;
        }
        if ($callback instanceof Node\Expr\MethodCall && $callback->isFirstClassCallable()) {
            $stack[] = new Node\Expr\MethodCall($callback->var, $callback->name, [], array_merge($callback->getAttributes(), ['eagerCallback' => true])); return;
        }
        if ($callback instanceof Node\Expr\FuncCall && $callback->isFirstClassCallable()) {
            $stack[] = new Node\Expr\FuncCall($callback->name, [], array_merge($callback->getAttributes(), ['eagerCallback' => true])); return;
        }
        if ($callback instanceof Node\Expr\New_) { $stack[] = new Node\Expr\MethodCall($callback, new Node\Identifier('__invoke'), [], array_merge($callback->getAttributes(), ['eagerCallback' => true])); return; }
        if ($callback instanceof Node\Scalar\String_ && !str_contains($callback->value, '::') && self::symbol($callback->value)) {
            $stack[] = new Node\Expr\FuncCall(new Node\Name\FullyQualified(ltrim($callback->value, '\\')), [], array_merge($callback->getAttributes(), ['eagerCallback' => true])); return;
        }
        if ($callback instanceof Node\Scalar\String_ && str_contains($callback->value, '::')) {
            $parts = explode('::', $callback->value);
            if (count($parts) === 2 && self::symbol($parts[0]) && preg_match('/\A[A-Za-z_][A-Za-z0-9_]*\z/', $parts[1])) {
                $stack[] = new Node\Expr\StaticCall(new Node\Name\FullyQualified(ltrim($parts[0], '\\')), new Node\Identifier($parts[1]), [], array_merge($callback->getAttributes(), ['eagerCallback' => true])); return;
            }
        }
        if ($callback instanceof Node\Expr\Array_ && count($callback->items) === 2) {
            $owner = $callback->items[0]?->value; $method = $callback->items[1]?->value;
            if ($callback->items[0]?->unpack || $callback->items[1]?->unpack || $callback->items[0]?->key !== null || $callback->items[1]?->key !== null || !$method instanceof Node\Scalar\String_) { $unknown[] = $callback; return; }
            if ($owner instanceof Node\Expr\ClassConstFetch && $owner->class instanceof Node\Name && $owner->name instanceof Node\Identifier && strtolower($owner->name->toString()) === 'class') {
                $stack[] = new Node\Expr\StaticCall($owner->class, new Node\Identifier($method->value), [], array_merge($callback->getAttributes(), ['eagerCallback' => true])); return;
            }
            if ($owner instanceof Node\Scalar\String_ && self::symbol($owner->value)) { $stack[] = new Node\Expr\StaticCall(new Node\Name\FullyQualified(ltrim($owner->value, '\\')), new Node\Identifier($method->value), [], array_merge($callback->getAttributes(), ['eagerCallback' => true])); return; }
            if ($owner instanceof Node\Expr) { $stack[] = new Node\Expr\MethodCall($owner, new Node\Identifier($method->value), [], array_merge($callback->getAttributes(), ['eagerCallback' => true])); return; }
        }
        // A callback expression may itself construct objects or call a factory.
        $unknown[] = $callback;
        if (!$callback instanceof Node\Expr\Variable) $stack[] = $callback;
    }
    private static function hasYield(array $nodes): bool
    {
        while ($nodes !== []) {
            $node = array_pop($nodes);
            if (!$node instanceof Node) continue;
            if ($node instanceof Node\Expr\Yield_ || $node instanceof Node\Expr\YieldFrom) return true;
            if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Stmt\Function_) continue;
            foreach ($node->getSubNodeNames() as $name) {
                $value = $node->$name;
                if ($value instanceof Node) $nodes[] = $value;
                elseif (is_array($value)) foreach ($value as $child) if ($child instanceof Node) $nodes[] = $child;
            }
        }
        return false;
    }
    private static function symbol(string $value): bool { return (bool) preg_match('/\A\\\\?[A-Za-z_][A-Za-z0-9_]*(?:\\\\[A-Za-z_][A-Za-z0-9_]*)*\z/', $value); }
}
