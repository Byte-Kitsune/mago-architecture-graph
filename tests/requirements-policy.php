<?php

declare(strict_types=1);

use ByteKitsune\MagoArchitectureGraph\Policy;
require __DIR__ . '/source-autoload.php';
$root = __DIR__ . '/requirements-corpus';
$data = json_decode(file_get_contents($root . '/policy.json'), true, 64, JSON_THROW_ON_ERROR);
$cases = json_decode(file_get_contents($root . '/cases.json'), true, 64, JSON_THROW_ON_ERROR);
$policy = new Policy($root, $root . '/policy.json');
$namespace = static fn (string $path): string => 'Sample\\' . str_replace('/', '\\', dirname(substr($path, 4)));
foreach ($cases as $case) {
    foreach ($case['targets'] as $target) {
        $sourceModule = $policy->classify($case['source'], $namespace($case['source']));
        $targetModule = $policy->classify($target, $namespace($target));
        if ($sourceModule === null || $targetModule === null) throw new RuntimeException('Requirement fixture was not classified.');
        $result = $policy->violation($sourceModule, $targetModule, $case['source'], $target);
        if ($result !== $case['expected']) throw new RuntimeException('Wrong requirement decision: ' . json_encode([$case, $result]));
    }
}
// Policy selectors contain no fixture instance names or target-type lists.
$policyText = json_encode($data, JSON_THROW_ON_ERROR);
foreach (['Amber', 'Cedar', 'Indigo'] as $owner) if (str_contains($policyText, $owner)) throw new RuntimeException('Policy unexpectedly lists a concrete owner.');
$temp = tempnam(sys_get_temp_dir(), 'neutral-policy-');
try {
    foreach (['invalid-mode', true] as $invalid) {
        $bad = $data; $bad['dependency_mode'] = $invalid;
        file_put_contents($temp, json_encode($bad, JSON_THROW_ON_ERROR));
        try { new Policy($root, $temp); throw new RuntimeException('Invalid dependency mode accepted.'); } catch (InvalidArgumentException) {}
    }
    foreach (['yes', 1] as $invalid) {
        $bad = $data; $bad['modules'][2]['external_entrypoints_only'] = $invalid;
        file_put_contents($temp, json_encode($bad, JSON_THROW_ON_ERROR));
        try { new Policy($root, $temp); throw new RuntimeException('Invalid entrypoint flag accepted.'); } catch (InvalidArgumentException) {}
    }
    $bad = $data; $bad['modules'][14]['external_entrypoints_only'] = true;
    file_put_contents($temp, json_encode($bad, JSON_THROW_ON_ERROR));
    try { new Policy($root, $temp); throw new RuntimeException('Ownerless entrypoint isolation accepted.'); } catch (InvalidArgumentException) {}
    // Moving the entry to an unrelated folder/name does not weaken its role.
    $moved = $data; $moved['modules'][2]['path_pattern'] = 'app/Cells/*/Misc/Front.php'; $moved['modules'][2]['entrypoint_suffix'] = '';
    file_put_contents($temp, json_encode($moved, JSON_THROW_ON_ERROR));
    $movedPolicy = new Policy($root, $temp);
    $source = 'app/Cells/Amber/Service/OwnPortal.php'; $target = 'app/Cells/Amber/Misc/Front.php';
    if ($movedPolicy->violation($movedPolicy->classify($source, $namespace($source)), $movedPolicy->classify($target, $namespace($target)), $source, $target) !== 'no-peer-entrypoint') throw new RuntimeException('Internal entrypoint guard depended on a folder or class name.');
    // An arbitrary-public grant must not override a target's internal entrypoint guard.
    $bad = $data; $bad['modules'][0]['allowed_family_entrypoints'] = [['target_family' => 'cell', 'target_layer' => 'cell-portal']];
    file_put_contents($temp, json_encode($bad, JSON_THROW_ON_ERROR));
    $guard = new Policy($root, $temp);
    $source = 'app/Cells/Amber/Service/OwnPortal.php'; $target = 'app/Cells/Amber/Api/Portal.php';
    if ($guard->violation($guard->classify($source, $namespace($source)), $guard->classify($target, $namespace($target)), $source, $target) !== 'no-peer-entrypoint') throw new RuntimeException('Public grant overrode internal entrypoint guard.');
    $bad = $data; $bad['modules'][0]['module_family_captures'] = ['caller'];
    $bad['modules'][0]['allowed_family_bindings'] = [['target_family' => 'cell', 'target_layer' => 'cell-portal', 'source_capture' => 'caller', 'target_capture' => 'owner']];
    file_put_contents($temp, json_encode($bad, JSON_THROW_ON_ERROR));
    $guard = new Policy($root, $temp);
    if ($guard->violation($guard->classify($source, $namespace($source)), $guard->classify($target, $namespace($target)), $source, $target) !== 'no-peer-entrypoint') throw new RuntimeException('Renaming capture fields bypassed internal entrypoint isolation.');

} finally { unlink($temp); }
echo count($cases) . " neutral cross-family, bridge, entrypoint and shared-data cases passed\n";
