<?php
declare(strict_types=1);
$report = json_decode(file_get_contents($argv[1]), true, 512, JSON_THROW_ON_ERROR);
$found = [];
foreach ($report['issues'] as $issue) if (str_ends_with($issue['code'], '/scope-forbidden-entrypoint-method')) {
    $evidence = json_decode(substr($issue['notes'][0], strlen('graph-evidence: ')), true, 512, JSON_THROW_ON_ERROR);
    $found[] = $evidence['edges'][0]['from'];
}
sort($found); $expected = array_map(static fn(string $method): string => 'App\\Entry::' . $method, ['classArray', 'emptyWithSecondArray', 'immediate', 'literalString', 'map', 'method', 'stringFunction', 'stringClassArray', 'invokable', 'comparator', 'regexMapping']); sort($expected);
if ($found !== $expected) throw new RuntimeException('Eager callback edges or deferred separation failed: ' . json_encode($found));
echo "Eleven eager callback graph paths and four deferred/empty controls passed\n";
