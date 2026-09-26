<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph\Analyzer;

use ByteKitsune\MagoArchitectureGraph\Policy;
use Mago\Sdk\Analyzer\AfterAnalysisContext;
use Mago\Sdk\Analyzer\AfterAnalysisHook;
use Mago\Sdk\Reporting\Issue;
use Mago\Sdk\Reporting\Level;
use Mago\Sdk\SourceLocation;
use Mago\Sdk\Span;
use PhpParser\Node;
use PhpParser\ParserFactory;

/** Reports only literal class imports under an explicit path-and-namespace policy. */
final class BoundaryHook implements AfterAnalysisHook
{
    public function __construct(private readonly Policy $policy) {}

    public function afterAnalysis(AfterAnalysisContext $context): void
    {
        $parser = (new ParserFactory())->createForNewestSupportedVersion();
        foreach ($context->analysis->files as $file) {
            $context->cancellation->throwIfCancelled();
            if (!str_ends_with($file->file, '.php')) continue;
            $source = $file->getSourceFile();
            $relative = $this->policy->relativePath($source->path);
            if ($relative === null) continue;
            try {
                $statements = $parser->parse($source->contents);
            } catch (\Throwable) {
                continue; // Mago reports invalid PHP; never infer edges from a partial parse.
            }
            foreach ($statements ?? [] as $statement) {
                if (!$statement instanceof Node\Stmt\Namespace_) continue;
                $namespace = $statement->name?->toString() ?? '';
                $module = $this->policy->classify($relative, $namespace);
                if ($module === null) continue;
                foreach ($statement->stmts as $part) {
                    if ($part instanceof Node\Stmt\Use_ && $part->type === Node\Stmt\Use_::TYPE_NORMAL) {
                        foreach ($part->uses as $item) $this->check($context, $source->path, $relative, $module, $item->name->toString(), $item);
                    } elseif ($part instanceof Node\Stmt\GroupUse && $part->type === Node\Stmt\Use_::TYPE_NORMAL) {
                        foreach ($part->uses as $item) {
                            if ($item->type !== Node\Stmt\Use_::TYPE_UNKNOWN && $item->type !== Node\Stmt\Use_::TYPE_NORMAL) continue;
                            $this->check($context, $source->path, $relative, $module, $part->prefix->toString() . '\\' . $item->name->toString(), $item);
                        }
                    }
                }
            }
        }
    }

    /** @param array<string, mixed> $sourceModule */
    private function check(AfterAnalysisContext $context, string $file, string $path, array $sourceModule, string $target, Node $item): void
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
            new SourceLocation($file, new Span($item->getStartFilePos(), $item->getEndFilePos() + 1)),
        )->withNote('Path and namespace were both matched; imports do not prove method calls or runtime dispatch.');
        $context->report(Level::Error, $code, $issue);
    }
}
