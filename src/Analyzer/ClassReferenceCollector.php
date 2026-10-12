<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph\Analyzer;

use PhpParser\Node;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use PhpParser\NodeVisitorAbstract;
use PhpParser\ParserFactory;

/** Collect literal PHP class references from a source snapshot without executing it. */
final class ClassReferenceCollector
{
    /** @return list<array{name: string, namespace: string, source_class: ?string, import: bool, start: int, end: int}> */
    public static function collect(string $source): array
    {
        if (strlen($source) > 8 * 1024 * 1024) throw new \LengthException('Class reference source exceeds 8 MiB.');
        $collector = new class extends NodeVisitorAbstract {
            /** @var list<array{name: string, namespace: string, source_class: ?string, import: bool, start: int, end: int}> */
            public array $references = [];
            private string $namespace = '';
            /** @var list<?string> */
            private array $classes = [];

            public function enterNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\Namespace_) $this->namespace = $node->name?->toString() ?? '';
                if ($node instanceof Node\Stmt\ClassLike) $this->classes[] = $node->namespacedName?->toString();
                if (!$node instanceof Node\Name) return null;
                $parent = $node->getAttribute('parent');
                $name = $node->toString();
                if ($parent instanceof Node\UseItem) {
                    $use = $parent->getAttribute('parent');
                    $type = $parent->type === Node\Stmt\Use_::TYPE_UNKNOWN ? $use->type : $parent->type;
                    if ($type !== Node\Stmt\Use_::TYPE_NORMAL) return null;
                    if ($use instanceof Node\Stmt\GroupUse) $name = $use->prefix->toString() . '\\' . $name;
                } elseif (!self::classPosition($node, $parent)) return null;
                if (in_array(strtolower($name), ['self', 'parent', 'static'], true)) return null;
                if (count($this->references) >= 100000) throw new \LengthException('Class reference source exceeds 100000 literal references.');
                $this->references[] = ['name' => ltrim($name, '\\'), 'namespace' => $this->namespace, 'source_class' => $this->classes === [] ? null : $this->classes[array_key_last($this->classes)], 'import' => $parent instanceof Node\UseItem, 'start' => $node->getStartFilePos(), 'end' => $node->getEndFilePos() + 1];
                return null;
            }

            public function leaveNode(Node $node): null
            {
                if ($node instanceof Node\Stmt\ClassLike) array_pop($this->classes);
                if ($node instanceof Node\Stmt\Namespace_) $this->namespace = '';
                return null;
            }

            private static function classPosition(Node\Name $node, mixed $parent): bool
            {
                return ($parent instanceof Node\Expr\New_ || $parent instanceof Node\Expr\StaticCall || $parent instanceof Node\Expr\ClassConstFetch || $parent instanceof Node\Expr\StaticPropertyFetch || $parent instanceof Node\Expr\Instanceof_) && $parent->class === $node
                    || $parent instanceof Node\Attribute && $parent->name === $node
                    || $parent instanceof Node\Param && $parent->type === $node
                    || $parent instanceof Node\Stmt\Property && $parent->type === $node
                    || $parent instanceof Node\FunctionLike && $parent->getReturnType() === $node
                    || $parent instanceof Node\NullableType || $parent instanceof Node\UnionType || $parent instanceof Node\IntersectionType
                    || $parent instanceof Node\Stmt\Class_ && ($parent->extends === $node || in_array($node, $parent->implements, true))
                    || $parent instanceof Node\Stmt\Interface_ && in_array($node, $parent->extends, true)
                    || $parent instanceof Node\Stmt\Enum_ && in_array($node, $parent->implements, true)
                    || $parent instanceof Node\Stmt\TraitUse && in_array($node, $parent->traits, true)
                    || $parent instanceof Node\Stmt\TraitUseAdaptation
                    || $parent instanceof Node\Stmt\Catch_ && in_array($node, $parent->types, true);
            }
        };
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        (new NodeTraverser(new NameResolver(), new ParentConnectingVisitor(), $collector))->traverse($parser->parse($source) ?? []);
        return $collector->references;
    }
}
