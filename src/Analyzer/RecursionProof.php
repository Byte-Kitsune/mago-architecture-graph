<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph\Analyzer;

use PhpParser\Node;

/** A deliberately narrow proof that a direct recursion decreases a guarded integer. */
final class RecursionProof
{
    public static function bounded(Node\Stmt\Class_ $class, Node\Stmt\ClassMethod $method, string $className): bool
    {
        if (!$class->isFinal() || count($method->params) !== 1 || count($method->stmts ?? []) !== 2) return false;
        $parameter = $method->params[0];
        if (!$parameter->type instanceof Node\Identifier || strtolower($parameter->type->name) !== 'int' || $parameter->byRef || $parameter->variadic || !is_string($parameter->var->name)) return false;
        [$guard, $step] = $method->stmts;
        if (!$guard instanceof Node\Stmt\If_ || $guard->else !== null || $guard->elseifs !== [] || count($guard->stmts) !== 1 || !$guard->stmts[0] instanceof Node\Stmt\Return_ || $guard->stmts[0]->expr !== null) return false;
        $name = $parameter->var->name;
        $condition = $guard->cond;
        $zeroGuard = $condition instanceof Node\Expr\BinaryOp\SmallerOrEqual && self::variable($condition->left, $name) && self::integer($condition->right, 0);
        $oneGuard = $condition instanceof Node\Expr\BinaryOp\Smaller && self::variable($condition->left, $name) && self::integer($condition->right, 1);
        if (!$zeroGuard && !$oneGuard) return false;
        if (!$step instanceof Node\Stmt\Expression) return false;
        $call = $step->expr;
        $sameMethod = $call instanceof Node\Expr\StaticCall
            && $call->class instanceof Node\Name
            && in_array(strtolower(($call->class->getAttribute('resolvedName') ?? $call->class)->toString()), ['self', strtolower($className)], true)
            && $call->name instanceof Node\Identifier
            && strcasecmp($call->name->toString(), $method->name->toString()) === 0;
        $sameMethod = $sameMethod || $call instanceof Node\Expr\MethodCall
            && $call->var instanceof Node\Expr\Variable
            && $call->var->name === 'this'
            && $call->name instanceof Node\Identifier
            && strcasecmp($call->name->toString(), $method->name->toString()) === 0;
        if (!$sameMethod || count($call->args) !== 1 || $call->args[0]->name !== null || $call->args[0]->unpack || $call->args[0]->byRef) return false;
        $argument = $call->args[0]->value;
        return $argument instanceof Node\Expr\BinaryOp\Minus && self::variable($argument->left, $name)
            && $argument->right instanceof Node\Scalar\Int_ && $argument->right->value > 0;
    }

    private static function variable(Node $node, string $name): bool
    {
        return $node instanceof Node\Expr\Variable && $node->name === $name;
    }

    private static function integer(Node $node, int $value): bool
    {
        return $node instanceof Node\Scalar\Int_ && $node->value === $value;
    }
}
