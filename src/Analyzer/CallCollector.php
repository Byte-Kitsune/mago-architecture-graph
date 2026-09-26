<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph\Analyzer;

use PhpParser\Node;

/** Visits only the method body executed by a call; nested declarations stay unknown. */
final class CallCollector
{
    /** @param list<Node\Stmt> $statements @return array{list<Node\Expr\StaticCall>, list<Node\Expr\MethodCall>, list<Node\Expr\FuncCall>, list<Node\Expr\New_>, list<Node>} */
    public static function collect(array $statements): array
    {
        $static = $instance = $functions = $constructors = $unknown = [];
        $stack = array_reverse($statements);
        while ($stack !== []) {
            $node = array_pop($stack);
            if (!$node instanceof Node) continue;
            if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction || $node instanceof Node\Stmt\Function_ || $node instanceof Node\Stmt\Class_) {
                $unknown[] = $node;
                continue;
            }
            if ($node instanceof Node\Expr\StaticCall) {
                if ($node->isFirstClassCallable()) $unknown[] = $node;
                else $static[] = $node;
            } elseif ($node instanceof Node\Expr\MethodCall) {
                if ($node->isFirstClassCallable()) $unknown[] = $node;
                else $instance[] = $node;
            } elseif ($node instanceof Node\Expr\FuncCall) $functions[] = $node;
            elseif ($node instanceof Node\Expr\New_) $constructors[] = $node;
            elseif ($node instanceof Node\Expr\NullsafeMethodCall) $unknown[] = $node;
            foreach (array_reverse($node->getSubNodeNames()) as $field) {
                $value = $node->$field;
                if ($value instanceof Node) $stack[] = $value;
                elseif (is_array($value)) foreach (array_reverse($value) as $child) if ($child instanceof Node) $stack[] = $child;
            }
        }
        return [$static, $instance, $functions, $constructors, $unknown];
    }
}
