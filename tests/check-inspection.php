<?php

declare(strict_types=1);

$snapshot = json_decode((string) file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
if ($snapshot['capability'] !== 'full_source_call_graph' || $snapshot['complete'] !== true || $snapshot['source_files'] !== 2) throw new RuntimeException('Inspection did not cover the whole source set.');
$nodes = array_column($snapshot['nodes'], null, 'symbol');
if (!isset($nodes['Frontend\\Entry::index'], $nodes['Storage\\Store::find'], $nodes['Storage\\Store::read'], $nodes['Storage\\Unused::run'])) throw new RuntimeException('Inspection excluded a directory or namespace.');
if ($nodes['Frontend\\Entry::index']['entry_scope'] !== 'entry-0' || $nodes['Storage\\Store::find']['entry_scope'] !== null) throw new RuntimeException('Configured entry paths were not respected.');
$edges = $snapshot['edges'];
if (count($edges) !== 2 || $edges[0]['to'] !== $nodes['Storage\\Store::find']['id'] || $edges[1]['to'] !== $nodes['Storage\\Store::read']['id']) throw new RuntimeException('Actual calls were lost or injection-only edges were invented.');
if ($edges[0]['path'] !== 'app/Http/Entry.php' || $edges[0]['line'] !== 5 || $edges[0]['column'] !== 37) throw new RuntimeException('Source navigation location is inaccurate.');
$metadata = array_column($snapshot['symbol_metadata'], null, 'symbol');
if (($metadata['Storage\\Store::find']['annotations'][0]['severity'] ?? null) !== 'warning') throw new RuntimeException('Method annotation missing from actual Mago export.');
$sites = array_values(array_filter($snapshot['annotation_sites'], static fn ($site) => $site['kind'] === 'call' && $site['target_symbol'] === 'Storage\\Store::find'));
if (count($sites) !== 1 || $sites[0]['path'] !== 'app/Http/Entry.php' || $sites[0]['line'] !== 5 || $sites[0]['column'] !== 51 || $sites[0]['end_column'] !== 55) throw new RuntimeException('Actual call annotation range is inaccurate.');
echo "Complete inspection source graph and comment sites passed\n";
