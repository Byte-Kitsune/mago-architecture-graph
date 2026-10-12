<?php

declare(strict_types=1);

$report = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
$mode = $argv[2];
$expected = $mode === 'imports' ? [] : array_merge(
    array_map(static fn (int $i): string => "app/Handlers/Constructor{$i}Task.php", range(1, 7)),
    ['app/Handlers/BuildTask.php', 'app/Handlers/TokenTask.php', 'app/Handlers/RelativeTask.php'],
);
$actual = [];
foreach ($report['issues'] as $issue) {
    if ($issue['code'] !== 'byte-kitsune/architecture-graph/no-peer-entrypoint'
        || !str_contains($issue['message'], 'Sample\\Flow\\PeerTask crosses')) {
        throw new RuntimeException('Unexpected namespace issue: ' . json_encode($issue));
    }
    $path = $issue['annotations'][0]['span']['file_id']['name'] ?? '';
    if (!in_array($path, $expected, true) || isset($actual[$path])) {
        throw new RuntimeException('Unexpected or duplicate dependency: ' . $path);
    }
    $actual[$path] = true;
}
if (count($actual) !== count($expected)) throw new RuntimeException('Missing unimported namespace dependencies.');
echo "Same-namespace references passed ($mode, " . count($actual) . " findings)\n";
