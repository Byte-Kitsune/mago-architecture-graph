<?php

declare(strict_types=1);

$report = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
$cases = json_decode(file_get_contents(__DIR__ . '/requirements-corpus/cases.json'), true, 64, JSON_THROW_ON_ERROR);
$mode = $argv[2] ?? 'imports';
$actual = [];
foreach ($report['issues'] as $issue) {
    $annotation = $issue['annotations'][0] ?? null;
    $path = $annotation['span']['file_id']['name'] ?? '';
    $actual[$path][] = $issue;
}
foreach ($cases as $case) {
    $expected = isset($case['modes']) && !in_array($mode, $case['modes'], true) ? null : $case['expected'];
    $issues = $actual[$case['source']] ?? [];
    if ($expected === null && $issues !== []) throw new RuntimeException('Allowed requirement was rejected: ' . json_encode([$case, $issues]));
    if ($expected !== null) {
        if (count($issues) !== count($case['targets'])) throw new RuntimeException('Missing or duplicate dependency finding: ' . json_encode([$case, $issues]));
        foreach ($issues as $issue) {
            if ($issue['code'] !== 'byte-kitsune/architecture-graph/' . $expected) throw new RuntimeException('Wrong requirement finding: ' . json_encode($issue));
            $target = 'Sample\\' . str_replace('/', '\\', substr($case['targets'][0], 4, -4));
            if (!str_contains($issue['message'], $target . ' crosses')) throw new RuntimeException('Dependency target was not resolved to its class: ' . $issue['message']);
        }
    }
    unset($actual[$case['source']]);
}
if ($actual !== []) throw new RuntimeException('Unexpected native analyzer issues: ' . json_encode($actual));
echo "Real Mago requirement matrix passed ($mode)\n";
