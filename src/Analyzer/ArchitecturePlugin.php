<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph\Analyzer;

use ByteKitsune\MagoArchitectureGraph\Policy;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

final class ArchitecturePlugin implements Plugin
{
    public function __construct(private readonly Policy $policy) {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('byte-kitsune/architecture-graph', 'Path-aware architecture', 'Checks configured module instances and entrypoints.');
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerAfterAnalysisHook(new BoundaryHook($this->policy));
        if ($this->policy->graph?->enabled) $registry->registerAfterAnalysisHook(new GraphHook($this->policy));
    }
}
