<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph\Analyzer;

use ByteKitsune\MagoArchitectureGraph\Policy;
use Mago\Sdk\Analyzer\NodeAnalysisContext;
use Mago\Sdk\Analyzer\NodeAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Syntax\Node;
use Mago\Sdk\Syntax\NodeKind;
use Mago\Sdk\Syntax\SourceFile;

/** Reports literal class imports using Mago's already-parsed syntax snapshot. */
final class BoundaryHook implements NodeAnalysisHook
{
    /** @var \WeakMap<\Mago\Sdk\Analyzer\FileAnalysis, array<string, Node>> */
    private readonly \WeakMap $itemsByAnalysis;

    public function __construct(private readonly Policy $policy)
    {
        $this->itemsByAnalysis = new \WeakMap();
    }

    public function getTargets(): array
    {
        return [NodeKind::UseItem];
    }

    public function getRequirements(): array
    {
        return [];
    }

    public function analyze(NodeAnalysisContext $context): void
    {
        $relative = $this->policy->relativePath($context->source->path);
        if ($relative === null || !$this->policy->possibleModulePath($relative)) return;
        $source = $context->analysis->getSourceFile();
        if (!isset($this->itemsByAnalysis[$context->analysis])) {
            $items = [];
            foreach ($source->getNodes(NodeKind::UseItem) as $candidate) {
                $items[$candidate->span->start . ':' . $candidate->span->end] = $candidate;
            }
            $this->itemsByAnalysis[$context->analysis] = $items;
        }
        $item = $this->itemsByAnalysis[$context->analysis][$context->node->span->start . ':' . $context->node->span->end] ?? null;
        if ($item === null) throw new \LogicException('Mago targeted import does not match the full analyzed syntax snapshot.');
        if ($this->isTypedImport($source, $item)) return;
        $namespace = $this->namespaceFor($source, $item);
        $module = $this->policy->classify($relative, $namespace);
        if ($module === null) return;
        $target = $source->getResolvedName($item)?->name;
        if ($target === null) throw new \LogicException('Mago could not resolve a class import in the full analyzed syntax snapshot.');
        $this->check($context, $source->path, $relative, $module, $target, $item);
    }

    private function isTypedImport(SourceFile $source, Node $item): bool
    {
        $parent = $source->getParent($item);
        if ($parent === null) return true;
        return $source->getFirstDescendant($parent, NodeKind::UseType) !== null;
    }

    private function namespaceFor(SourceFile $source, Node $item): string
    {
        foreach ($source->getAncestors($item) as $ancestor) {
            if ($ancestor->kind !== NodeKind::Namespace) continue;
            foreach ($source->getChildren($ancestor) as $child) {
                if ($child->kind === NodeKind::Identifier) return $source->getResolvedName($child)?->name ?? $source->getText($child);
            }
            return '';
        }
        return '';
    }

    /** @param array<string, mixed> $sourceModule */
    private function check(NodeAnalysisContext $context, string $file, string $path, array $sourceModule, string $target, Node $item): void
    {
        $targetPath = $this->policy->targetPath($target);
        if ($targetPath === null) return;
        $namespace = str_contains($target, '\\') ? substr($target, 0, strrpos($target, '\\')) : '';
        $targetModule = $this->policy->classify($targetPath, $namespace);
        if ($targetModule === null) return;
        $code = $this->policy->violation($sourceModule, $targetModule, $path, $targetPath);
        if ($code === null) return;
        $issue = Issue::at(
            sprintf('Import of %s crosses the configured %s boundary (%s -> %s).', $target, $code, $sourceModule['id'], $targetModule['id']),
            new SourceLocation($file, $item->span),
        )->withNote('Path and namespace were both matched; imports do not prove method calls or runtime dispatch.');
        $context->report(Level::Error, $code, $issue);
    }
}
