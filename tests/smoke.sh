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
