<?php

declare(strict_types=1);

$report = json_decode(file_get_contents($argv[1]), true, 64, JSON_THROW_ON_ERROR);
$counts = [];
$roots = [];
foreach ($report['issues'] as $issue) {
    $code = $issue['code'];
    $counts[$code] = ($counts[$code] ?? 0) + 1;
    foreach ($issue['notes'] ?? [] as $note) {
        if (!str_starts_with($note, 'graph-evidence: ')) continue;
        $proof = json_decode(substr($note, strlen('graph-evidence: ')), true, 64, JSON_THROW_ON_ERROR);
        if ($proof['complete'] !== true || count($proof['edges']) < 1) throw new RuntimeException('Incomplete instance proof.');
        $root = $proof['edges'][0]['from'];
        $owner = explode('\\', $root)[2];
        if ($proof['scope_captures'] !== ['owner' => $owner]) throw new RuntimeException('The owner was rebound during traversal.');
        if ($proof['policy_id'] === 'own-tool' && !str_starts_with($proof['target'], "App\\Workshop\\$owner\\Tools\\")) throw new RuntimeException('Foreign owner was allowed.');
        if ($proof['policy_id'] === 'foreign-tool' && str_starts_with($proof['target'], "App\\Workshop\\$owner\\Tools\\")) throw new RuntimeException('Own owner was denied.');
        $roots[$root] = [$proof['policy_id'], count($proof['edges'])];
    }
    if (str_ends_with($code, '/analysis-attestation')) {
        $attestation = json_decode(substr($issue['notes'][0], strlen('extension-attestation: ')), true, 64, JSON_THROW_ON_ERROR);
        if ($attestation['complete'] !== true) throw new RuntimeException('Full instance graph must be complete.');
    }
}
$prefix = 'byte-kitsune/architecture-graph/';
$expected = ['foreign-module-instance' => 3, 'forbidden-internal-access' => 1, 'no-peer-entrypoint' => 1, 'scope-forbidden-entrypoint-method' => 4, 'scope-allowed-entrypoint-method' => 4, 'analysis-attestation' => 1];
foreach ($expected as $suffix => $count) {
    if (($counts[$prefix . $suffix] ?? 0) !== $count) throw new RuntimeException('Unexpected findings: ' . json_encode($counts));
    unset($counts[$prefix . $suffix]);
}
if ($counts !== []) throw new RuntimeException('Unexpected analyzer findings: ' . json_encode($counts));
foreach (['same' => ['own-tool', 1], 'foreign' => ['foreign-tool', 1], 'indirect' => ['foreign-tool', 2], 'rebinding' => ['foreign-tool', 2]] as $method => $expected) {
    if (($roots['App\\Workshop\\Amber\\Steps\\EntryStep::' . $method] ?? null) !== $expected) throw new RuntimeException('Missing exact direct/transitive owner proof: ' . $method);
}
if (($roots['App\\Workshop\\Cedar\\Steps\\EntryStep::same'] ?? null) !== ['own-tool', 1]) throw new RuntimeException('The second owner must be evaluated independently.');
echo "Real Mago instance entrances and direct/transitive owner isolation passed\n";
