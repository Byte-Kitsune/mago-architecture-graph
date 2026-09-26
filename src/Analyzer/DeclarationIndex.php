<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph\Analyzer;

use ByteKitsune\MagoArchitectureGraph\Policy;
use Mago\Sdk\Analyzer\CodebaseScanContext;
use Mago\Sdk\Analyzer\CodebaseScanHook;
use Mago\Sdk\Syntax\NodeKind;

/** Names only: detect ambiguous class and function declarations without reparsing project files. */
final class DeclarationIndex implements CodebaseScanHook
{
    /** @var array<string, true> */
    private array $seen = [];
    /** @var array<string, true> */
    private array $seenFunctions = [];
    /** @var array<string, array{string, int, string}> */
    private array $duplicates = [];
    /** @var array<string, array{string, int, string}> */
    private array $duplicateFunctions = [];
    /** @var list<array{string, int, string}> */
    private array $unknown = [];
    private bool $complete = false;

    public function __construct(private readonly Policy $policy) {}

    public function getTargets(): array
    {
        $root = $this->policy->graph->sourceRoot;
        return [$root . '/*.php', $root . '/**/*.php'];
    }

    public function scan(CodebaseScanContext $context): void
    {
        if ($context->firstBatch) {
            $this->seen = $this->seenFunctions = $this->duplicates = $this->duplicateFunctions = $this->unknown = [];
            $this->complete = false;
        }
        foreach ($context->files as $source) {
            $path = $this->policy->relativePath($source->path);
            if ($path === null || $this->policy->graph->excluded($path)) continue;
            foreach ([NodeKind::Error, NodeKind::MissingTerminator, NodeKind::ClassLikeMemberMissingSelector, NodeKind::ClassLikeConstantMissingSelector] as $errorKind) {
                foreach ($source->getNodes($errorKind) as $error) $this->unknown[] = [$source->path, $error->span->start, 'syntax error'];
            }
            foreach ([NodeKind::Class_, NodeKind::Interface, NodeKind::Trait, NodeKind::Enum] as $kind) {
                foreach ($source->getNodes($kind) as $declaration) {
                    $name = null;
                    foreach ($source->getChildren($declaration) as $child) {
                        if ($child->kind === NodeKind::LocalIdentifier) {
                            $name = $source->getResolvedName($child)?->name;
                            break;
                        }
                    }
                    $location = [$source->path, $declaration->span->start, $name ?? 'unknown'];
                    if ($name === null) {
                        $this->unknown[] = $location;
                        continue;
                    }
                    $key = strtolower($name);
                    if (isset($this->seen[$key])) $this->duplicates[$key] ??= $location;
                    else $this->seen[$key] = true;
                }
            }
            foreach ($source->getNodes(NodeKind::Function) as $declaration) {
                $name = null;
                foreach ($source->getChildren($declaration) as $child) {
                    if ($child->kind === NodeKind::LocalIdentifier) {
                        $name = $source->getResolvedName($child)?->name;
                        break;
                    }
                }
                $location = [$source->path, $declaration->span->start, $name ?? 'unknown function'];
                if ($name === null) {
                    $this->unknown[] = $location;
                    continue;
                }
                $key = strtolower($name);
                if (isset($this->seenFunctions[$key])) $this->duplicateFunctions[$key] ??= $location;
                else $this->seenFunctions[$key] = true;
            }
        }
        $this->complete = $context->lastBatch;
    }

    public function isDuplicate(string $name): bool { return isset($this->duplicates[strtolower($name)]); }

    public function isDuplicateFunction(string $name): bool { return isset($this->duplicateFunctions[strtolower($name)]); }

    /** @return list<array{string, int, string}> */
    public function problems(): array
    {
        $problems = array_values($this->duplicates);
        foreach ($this->duplicateFunctions as $duplicate) $problems[] = $duplicate;
        foreach ($this->unknown as $unknown) $problems[] = $unknown;
        usort($problems, static fn (array $a, array $b): int => [$a[0], $a[1], $a[2]] <=> [$b[0], $b[1], $b[2]]);
        return $problems;
    }

    public function complete(): bool { return $this->complete; }
}
