<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph\Analyzer;

use ByteKitsune\MagoArchitectureGraph\Policy;
use Mago\Sdk\Analyzer\Plugin;
use Mago\Sdk\Analyzer\PluginDefinition;
use Mago\Sdk\Analyzer\PluginRegistry;

final class ArchitecturePlugin implements Plugin
{
    /** @param array<string, string> $classBindings */
    public function __construct(private readonly Policy $policy, private readonly array $classBindings = [], private readonly bool $serviceConfigurationComplete = true) {}

    public function getDefinition(): PluginDefinition
    {
        return new PluginDefinition('byte-kitsune/architecture-graph', 'Path-aware architecture', 'Checks configured module instances and entrypoints.');
    }

    public function register(PluginRegistry $registry): void
    {
        $registry->registerNodeAnalysisHook(new BoundaryHook($this->policy));
        if ($this->policy->graph?->enabled) $registry->registerAfterAnalysisHook(new GraphHook($this->policy, $this->classBindings, $this->serviceConfigurationComplete));
    }
}
