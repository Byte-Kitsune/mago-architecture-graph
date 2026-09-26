<?php

declare(strict_types=1);

namespace ByteKitsune\MagoArchitectureGraph;

use ByteKitsune\MagoArchitectureGraph\Analyzer\ArchitecturePlugin;
use Mago\Sdk\Extension;

final class ArchitectureGraphExtension
{
    /** The policy is trusted operator configuration, never selected by analyzed PHP. */
    public static function create(string $projectRoot, string $policyPath): Extension
    {
        return new Extension(
            identifier: 'byte-kitsune/architecture-graph',
            name: 'Path-aware architecture graph',
            version: '0.1.0-beta.1',
            analyzerPlugins: [new ArchitecturePlugin(new Policy($projectRoot, $policyPath))],
        );
    }
}
