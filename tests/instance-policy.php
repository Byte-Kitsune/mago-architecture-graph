<?php

declare(strict_types=1);

use ByteKitsune\MagoArchitectureGraph\GraphPolicy;
use ByteKitsune\MagoArchitectureGraph\Policy;

require __DIR__ . '/source-autoload.php';

$root = __DIR__ . '/instance-corpus';
$data = json_decode(file_get_contents($root . '/policy.json'), true, 64, JSON_THROW_ON_ERROR);
$policy = new Policy($root, $root . '/policy.json');
function expect(mixed $actual, mixed $expected, string $reason): void
{
    if ($actual !== $expected) throw new RuntimeException($reason . ': ' . json_encode([$actual, $expected]));
}
function crossing(Policy $policy, string $source, string $target): ?string
{
    $namespace = static fn (string $path): string => 'App\\' . str_replace('/', '\\', dirname(substr($path, 4)));
    $from = $policy->classify($source, $namespace($source));
    $to = $policy->classify($target, $namespace($target));
    if ($from === null || $to === null) throw new RuntimeException('Fixture module was not classified.');
    return $policy->violation($from, $to, $source, $target);
}
foreach (['Amber', 'Cedar', 'Indigo'] as $owner) {
    expect(crossing($policy, 'src/Lobby/Visit.php', "src/Workshop/$owner/Gate/EntryGate.php"), null, 'Explicit entrance must support any owner');
    expect(crossing($policy, 'src/Lobby/Visit.php', "src/Workshop/$owner/Gate/HiddenGate.php"), 'forbidden-internal-access', 'A grant must not open internal classes');
    expect(crossing($policy, 'src/Visitors/Uninvited.php', "src/Workshop/$owner/Gate/EntryGate.php"), 'foreign-module-instance', 'Unlisted entrances must stay closed');
    expect(crossing($policy, 'src/Lobby/Visit.php', "src/Workshop/$owner/Steps/EntryStep.php"), 'foreign-module-instance', 'A gate grant must not open other layers');
    expect(crossing($policy, "src/Workshop/$owner/Gate/EntryGate.php", "src/Workshop/$owner/Steps/EntryStep.php"), null, 'Internal layers must share their owner');
    expect(crossing($policy, "src/Workshop/$owner/Gate/EntryGate.php", "src/Workshop/$owner/Steps/HiddenStep.php"), 'forbidden-internal-access', 'An owner binding must retain the layer entrypoint rule');
    expect(crossing($policy, "src/Workshop/$owner/Gate/PeerGate.php", "src/Workshop/$owner/Gate/EntryGate.php"), 'no-peer-entrypoint', 'Peer entrypoint rules must remain enforced');
    foreach (['Amber', 'Cedar', 'Indigo'] as $other) {
        if ($owner === $other) continue;
        expect(crossing($policy, "src/Workshop/$owner/Gate/EntryGate.php", "src/Workshop/$other/Steps/EntryStep.php"), 'foreign-module-instance', 'Cross-owner imports must remain blocked');
    }
}
// Even a same-layer entrance grant exposes only a declared entrypoint.
$sameLayer = $data;
$sameLayer['modules'][0]['layer'] = 'gate';
$temp = tempnam(sys_get_temp_dir(), 'instance-policy-');
try {
    file_put_contents($temp, json_encode($sameLayer, JSON_THROW_ON_ERROR));
    $samePolicy = new Policy($root, $temp);
    expect(crossing($samePolicy, 'src/Lobby/Visit.php', 'src/Workshop/Amber/Gate/HiddenGate.php'), 'forbidden-internal-access', 'Same-layer entrance must not bypass visibility');
    $withoutGrant = $data;
    unset($withoutGrant['modules'][0]['allowed_family_entrypoints']);
    file_put_contents($temp, json_encode($withoutGrant, JSON_THROW_ON_ERROR));
    expect(crossing(new Policy($root, $temp), 'src/Lobby/Visit.php', 'src/Workshop/Amber/Gate/EntryGate.php'), 'foreign-module-instance', 'Old policies must keep their original isolation');
    $invalidModules = [];
    $bad = $data; $bad['modules'][0]['allowed_family_entrypoints'] = [['target_family' => 'missing', 'target_layer' => 'gate']]; $invalidModules[] = $bad;
    $bad = $data; $bad['modules'][0]['allowed_family_entrypoints'][0]['target_layer'] = 'tool'; $invalidModules[] = $bad;
    $bad = $data; $bad['modules'][0]['allowed_family_entrypoints'][0]['extra'] = true; $invalidModules[] = $bad;
    $bad = $data; $bad['modules'][2]['allowed_family_entrypoints'] = [['target_family' => 'workshop', 'target_layer' => 'step']]; $invalidModules[] = $bad;
    $bad = $data; $bad['modules'][2]['excluded'] = true; $invalidModules[] = $bad;
    $bad = $data; $bad['modules'][0]['allowed_family_entrypoints'] = array_fill(0, 65, $data['modules'][0]['allowed_family_entrypoints'][0]); $invalidModules[] = $bad;
    foreach ($invalidModules as $invalid) {
        file_put_contents($temp, json_encode($invalid, JSON_THROW_ON_ERROR));
        try { new Policy($root, $temp); throw new RuntimeException('Malformed entrance grant was accepted.'); }
        catch (InvalidArgumentException) {}
    }
} finally { unlink($temp); }

$graphData = $data['scope_graph'];
$graph = new GraphPolicy($graphData);
foreach (['Amber', 'Cedar', 'Indigo'] as $owner) {
    $path = "src/Workshop/$owner/Steps/EntryStep.php";
    $scope = $graph->scopeFor($path, "App\\Workshop\\$owner\\Steps\\EntryStep");
    expect($scope['captures'] ?? null, ['owner' => $owner], 'Scope must capture this entry owner');
    expect($graph->possibleScopePath($path), true, 'Wildcard entry path must be indexed');
    expect($graph->permission('steps', "App\\Workshop\\$owner\\Tools\\EntryTool::ping", $scope['captures'])['decision'] ?? null, 'allow', 'Bound allow must beat generic deny');
    expect($graph->permission('steps', 'App\\Workshop\\Other\\Tools\\EntryTool::ping', $scope['captures'])['decision'] ?? null, 'deny', 'Different owner must use the deny');
    expect($graph->permission('steps', "app\\workshop\\$owner\\tools\\entrytool::PING", $scope['captures'])['decision'] ?? null, 'allow', 'PHP symbol matching must stay case insensitive');
}
expect($graph->permission('steps', 'App\\Workshop\\Amber\\Tools\\EntryTool::ping')['decision'] ?? null, 'deny', 'Absent captures must never create a wildcard allowance');
expect($graph->possibleScopePath('src/Workshop/Amber/Other/Steps/EntryStep.php'), false, 'One star must not match multiple directories');
expect($graph->possibleScopePath('src/Workshop//Steps/EntryStep.php'), false, 'One star must not match an empty directory');
expect($graph->scopeFor('src/Workshop/Amber/Steps/EntryStep.php', 'Other\\Entry'), null, 'Namespace remains required');
expect($graph->permission('steps', 'App\\Workshop\\Amber\\Tools\\Nested\\EntryTool::ping', ['owner' => 'Amber']), null, 'Type stars match exactly one namespace segment');
expect($graph->permission('steps', 'App\\Workshop\\Amber\\Tools\\EntryTool::ping', ['owner' => '*'])['decision'] ?? null, 'deny', 'Captured text must not become pattern syntax');
$reversed = $graphData; $reversed['method_permissions'] = array_reverse($reversed['method_permissions']);
expect((new GraphPolicy($reversed))->permission('steps', 'App\\Workshop\\Amber\\Tools\\EntryTool::ping', ['owner' => 'Amber'])['decision'], 'allow', 'Rule specificity must be independent of order');
$equal = $graphData; $deny = $equal['method_permissions'][1]; $deny['decision'] = 'deny'; $equal['method_permissions'][] = $deny;
expect((new GraphPolicy($equal))->permission('steps', 'App\\Workshop\\Amber\\Tools\\EntryTool::ping', ['owner' => 'Amber'])['decision'], 'deny', 'Equal-specificity denial must win');
$literal = $graphData; $literal['method_permissions'][] = ['scope_id' => 'steps', 'target_type' => 'App\\Workshop\\Amber\\Tools\\EntryTool', 'method' => 'ping', 'decision' => 'deny', 'policy_id' => 'literal', 'rationale' => 'Exact class exception'];
expect((new GraphPolicy($literal))->permission('steps', 'App\\Workshop\\Amber\\Tools\\EntryTool::ping', ['owner' => 'Amber'])['policy_id'], 'literal', 'Literal class must beat a captured class');
$multi = $graphData;
$multi['scopes'][0]['path_pattern'] = 'src/Zone/*/Workshop/*/Steps/';
$multi['scopes'][0]['path_captures'] = ['zone', 'owner'];
$multi['method_permissions'][0]['target_type_pattern'] = 'App\\Zone\\*\\Workshop\\*\\Tools\\EntryTool';
$multi['method_permissions'][1]['target_type_pattern'] = 'App\\Zone\\{zone}\\Workshop\\{owner}\\Tools\\EntryTool';
$multiGraph = new GraphPolicy($multi);
$scope = $multiGraph->scopeFor('src/Zone/North/Workshop/Amber/Steps/EntryStep.php', 'App\\Workshop\\Entry');
expect($scope['captures'], ['zone' => 'North', 'owner' => 'Amber'], 'Multiple captures retain their named identity');
foreach (['App\\Zone\\North\\Workshop\\Amber\\Tools\\EntryTool::ping' => 'allow', 'App\\Zone\\South\\Workshop\\Amber\\Tools\\EntryTool::ping' => 'deny', 'App\\Zone\\North\\Workshop\\Cedar\\Tools\\EntryTool::ping' => 'deny'] as $symbol => $decision) {
    expect($multiGraph->permission('steps', $symbol, $scope['captures'])['decision'] ?? null, $decision, 'Every binding dimension must match');
}
$exactPath = $graphData;
$exactPath['scopes'][0]['path_pattern'] = 'src/Workshop/*/Steps/EntryStep.php';
expect((new GraphPolicy($exactPath))->possibleScopePath('src/Workshop/Amber/Steps/EntryStep.php'), true, 'Exact patterned file must match');
expect((new GraphPolicy($exactPath))->possibleScopePath('src/Workshop/Amber/Steps/EntryStep.php/extra'), false, 'File selector must not become a prefix');
$anonymous = $graphData;
unset($anonymous['scopes'][0]['path_captures']);
$anonymous['method_permissions'] = [$anonymous['method_permissions'][0]];
expect((new GraphPolicy($anonymous))->scopeFor('src/Workshop/Amber/Steps/EntryStep.php', 'App\\Workshop\\Entry')['id'], 'steps', 'Wildcard scope may be used without named bindings');

$invalidGraphs = [];
foreach (['src/' . str_repeat('*/', 17), "src/*/Bad\n/", 'src/**/Steps/', 'src/A*/Steps/', 'src/../*/Steps/', '/src/*/Steps/', 'src/*/../Steps/'] as $pattern) {
    $bad = $graphData; $bad['scopes'][0]['path_pattern'] = $pattern; $invalidGraphs[] = $bad;
}
foreach ([['owner', 'owner'], ['owner', 'second'], [null], 'owner'] as $captures) {
    $bad = $graphData; $bad['scopes'][0]['path_captures'] = $captures; $invalidGraphs[] = $bad;
}
$bad = $graphData; $bad['scopes'][0]['path_prefix'] = 'src/'; $invalidGraphs[] = $bad;
foreach (['App\\Workshop\\{missing}\\Tools\\EntryTool', 'App\\Workshop\\**\\Tools\\EntryTool', 'App\\Workshop\\A*\\Tools\\EntryTool', 'App\\Workshop\\*\\Tools\\', 'App\\Workshop\\Amber\\Tools\\EntryTool'] as $pattern) {
    $bad = $graphData; $bad['method_permissions'][0]['target_type_pattern'] = $pattern; $invalidGraphs[] = $bad;
}
$bad = $graphData; $bad['method_permissions'][0]['target_type'] = 'App\\Entry'; $invalidGraphs[] = $bad;
foreach ($invalidGraphs as $invalid) {
    try { new GraphPolicy($invalid); throw new RuntimeException('Unsafe or ambiguous graph pattern was accepted.'); }
    catch (InvalidArgumentException) {}
}
echo "Neutral instance entrances, owner isolation, bound scopes and selector validation passed\n";
