<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph\Analyzer;

use ByteKitsune\MagoArchitectureGraph\Policy;
use Mago\Sdk\Analyzer\AfterFileAnalysisContext;
use Mago\Sdk\Analyzer\AfterFileAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;

/** Optional literal class dependency coverage, including references without imports. */
final class ClassReferenceHook implements AfterFileAnalysisHook
{
    public function __construct(private readonly Policy $policy) {}

    public function getRequirements(): array { return []; }

    public function afterFileAnalysis(AfterFileAnalysisContext $context): void
    {
        $path = $this->policy->relativePath($context->analysis->file);
        if ($path === null || !$this->policy->possibleModulePath($path)) return;
        $source = $context->analysis->getSourceFile();
        try {
            $references = ClassReferenceCollector::collect($source->contents);
        } catch (\PhpParser\Error | \LengthException $error) {
            $context->report(Level::Error, 'architecture-reference-incomplete', Issue::at('Literal class reference coverage is incomplete: ' . $error->getMessage(), new SourceLocation($source->path, new Span(0, min(1, strlen($source->contents))))));
            return;
        }
        $names = [];
        foreach ($references as $reference) {
            if ($this->policy->classify($path, $reference['namespace']) !== null) $names[strtolower($reference['name'])] = $reference['name'];
        }
        $declarations = [];
        foreach (array_chunk($names, 256, true) as $batch) {
            $context->cancellation->throwIfCancelled();
            $metadata = $context->codebase->getMultipleClassLikes(array_values($batch));
            foreach (array_keys($batch) as $index => $key) $declarations[$key] = $metadata[$index] ?? null;
        }
        $seen = [];
        foreach ($references as $reference) {
            $context->cancellation->throwIfCancelled();
            $module = $this->policy->classify($path, $reference['namespace']);
            $target = $reference['name'];
            $key = $reference['namespace'] . ':' . strtolower($target);
            if ($module === null || isset($seen[$key])) continue;
            $declaration = $declarations[strtolower($target)] ?? null;
            if ($declaration !== null) $target = $declaration->originalName;
            $targetPath = $declaration === null ? $this->policy->targetPath($target) : $this->policy->relativePath($declaration->location->file);
            if ($targetPath === null) continue;
            // A class may return/construct its own type; this is not an incoming
            // architecture dependency. Imports of this file's own declarations
            // are likewise aliases, while references from another class are checked.
            if ($reference['source_class'] !== null && strcasecmp($reference['source_class'], $target) === 0 || $reference['import'] && $targetPath === $path) continue;
            $seen[$key] = true;
            $namespace = str_contains($target, '\\') ? substr($target, 0, strrpos($target, '\\')) : '';
            $targetModule = $this->policy->classify($targetPath, $namespace);
            if ($targetModule === null) continue;
            $code = $this->policy->violation($module, $targetModule, $path, $targetPath);
            if ($code === null) continue;
            $context->report(Level::Error, $code, Issue::at(sprintf('Reference to %s crosses the configured %s boundary (%s -> %s).', $target, $code, $module['id'], $targetModule['id']), new SourceLocation($source->path, new Span($reference['start'], $reference['end'])))->withNote('Literal class dependency resolved from the analyzed source snapshot; no PHP was executed.'));
        }
    }
}
