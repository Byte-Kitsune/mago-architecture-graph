#!/bin/sh
set -eu
php tests/check.php
cd tests/corpus
report=$(mktemp)
trap 'rm -f "$report"' EXIT HUP INT TERM
set +e
../../vendor/bin/mago analyze --reporting-format json --minimum-report-level error > "$report"
status=$?
set -e
[ "$status" -eq 1 ]
php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); $c=array_column($r["issues"],"code"); foreach(["byte-kitsune/architecture-graph/foreign-module-instance"=>1,"byte-kitsune/architecture-graph/forbidden-internal-access"=>2] as $required=>$expected) if(count(array_filter($c,fn($v)=>$v===$required))!==$expected) { fwrite(STDERR,json_encode($c)); exit(1); } echo "Architecture corpus passed\n";' "$report"
cd ../graph-corpus
set +e
../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note > "$report"
status=$?
set -e
[ "$status" -eq 1 ]
php -r '
$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
$issues=$r["issues"];
foreach (["scope-forbidden-entrypoint-method"=>1,"scope-allowed-entrypoint-method"=>3,"scope-graph-incomplete"=>1] as $suffix=>$expected) {
    $matches=array_values(array_filter($issues,fn($i)=>$i["code"]==="byte-kitsune/architecture-graph/".$suffix));
    if (count($matches)!==$expected) { fwrite(STDERR,"Expected $expected $suffix, got ".count($matches)."\n"); exit(1); }
    if ($suffix==="scope-graph-incomplete") continue;
    $notes=array_values(array_filter($matches[0]["notes"]??[],fn($n)=>str_starts_with($n,"graph-evidence: ")));
    if (count($notes)!==1) { fwrite(STDERR,"Missing graph evidence\n"); exit(1); }
    $proof=json_decode(substr($notes[0],strlen("graph-evidence: ")),true,512,JSON_THROW_ON_ERROR);
    if (($proof["schema_version"]??null)!=="1" || count($proof["edges"]??[])!==2 || ($proof["complete"]??null)!==false || ($proof["target"]??null)!=="App\\Api\\Gateway::expensive") { fwrite(STDERR,"Invalid graph evidence\n"); exit(1); }
}
echo "Graph corpus passed\n";
' "$report"
cd ../alias-corpus
set +e
../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note > "$report"
status=$?
set -e
[ "$status" -eq 1 ]
php -r '
$issues=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["issues"];
$proofs=array_values(array_filter($issues,fn($i)=>$i["code"]==="byte-kitsune/architecture-graph/scope-forbidden-entrypoint-method"));
if (count($proofs)!==3 || count($issues)!==3) throw new RuntimeException("Alias corpus did not produce exactly three denials.");
foreach ($proofs as $issue) {
    $proof=json_decode(substr($issue["notes"][0],strlen("graph-evidence: ")),true,512,JSON_THROW_ON_ERROR);
    if (($proof["complete"]??null)!==true || count($proof["edges"]??[])!==1 || !str_starts_with($proof["edges"][0]["evidence"],"Symfony ")) throw new RuntimeException("Alias proof incomplete or misbound.");
    if ($proof["edges"][0]["to"]!==$proof["target"]) throw new RuntimeException("Alias proof target has noncanonical case.");
}
echo "Symfony alias graph corpus passed\n";
' "$report"
set +e
ARCHITECTURE_CONFIG_INCOMPLETE=1 ../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note > "$report"
status=$?
set -e
[ "$status" -eq 1 ]
php -r '
$issues=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["issues"];
$incomplete=array_values(array_filter($issues,fn($i)=>$i["code"]==="byte-kitsune/architecture-graph/scope-graph-incomplete"));
if (count($incomplete)!==1 || !str_contains($incomplete[0]["message"],"service configuration")) throw new RuntimeException("Incomplete service configuration was not reported.");
foreach ($issues as $issue) if ($issue["code"]==="byte-kitsune/architecture-graph/scope-forbidden-entrypoint-method") {
    $proof=json_decode(substr($issue["notes"][0],strlen("graph-evidence: ")),true,512,JSON_THROW_ON_ERROR);
    if (($proof["complete"]??null)!==false) throw new RuntimeException("Incomplete service configuration certified a proof.");
}
echo "Incomplete service configuration corpus passed\n";
' "$report"
cd ../ambiguous-alias-corpus
set +e
../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note > "$report"
status=$?
set -e
[ "$status" -eq 1 ]
php -r '
$issues=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["issues"];
if (count($issues)!==1 || $issues[0]["code"]!=="byte-kitsune/architecture-graph/scope-graph-incomplete" || !str_contains($issues[0]["message"],"Symfony AsAlias")) throw new RuntimeException("Ambiguous alias was not rejected as incomplete.");
echo "Ambiguous alias corpus passed\n";
' "$report"
cd ../recursion-corpus
set +e
../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note > "$report"
status=$?
set -e
[ "$status" -eq 1 ]
php -r '
$issues=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["issues"];
$codes=array_column($issues,"code");
foreach (["recursive-cycle"=>2,"bounded-recursion"=>1,"scope-allowed-entrypoint-method"=>2,"scope-forbidden-entrypoint-method"=>1] as $suffix=>$expected) {
    if (count(array_filter($codes,fn($code)=>$code==="byte-kitsune/architecture-graph/".$suffix))!==$expected) throw new RuntimeException("Wrong recursion classification: ".$suffix);
}
foreach ($issues as $issue) if ($issue["code"]==="byte-kitsune/architecture-graph/scope-allowed-entrypoint-method") {
    $proof=json_decode(substr($issue["notes"][0],strlen("graph-evidence: ")),true,512,JSON_THROW_ON_ERROR);
    if (($proof["complete"]??null)!==false) throw new RuntimeException("Cycle did not invalidate graph completeness.");
}
echo "Recursion graph corpus passed\n";
' "$report"
