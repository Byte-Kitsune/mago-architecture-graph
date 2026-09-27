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
$attestations=array_values(array_filter($issues,fn($i)=>$i["code"]==="byte-kitsune/architecture-graph/analysis-attestation"));
if (count($attestations)!==1) throw new RuntimeException("Expected one architecture analysis attestation.");
$note=$attestations[0]["notes"][0]??"";
if (!str_starts_with($note,"extension-attestation: ")) throw new RuntimeException("Missing extension attestation payload.");
$attestation=json_decode(substr($note,strlen("extension-attestation: ")),true,512,JSON_THROW_ON_ERROR);
if (($attestation["schema_version"]??null)!=="1" || ($attestation["extension"]??null)!=="byte-kitsune/architecture-graph" || ($attestation["version"]??null)!=="0.1.0-beta.14" || ($attestation["capability"]??null)!=="scope_graph" || ($attestation["complete"]??null)!==false || ($attestation["source_files"]??0)<1) throw new RuntimeException("Invalid architecture analysis attestation.");
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
$issues=array_values(array_filter(json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["issues"],fn($i)=>$i["code"]!=="byte-kitsune/architecture-graph/analysis-attestation"));
$proofs=array_values(array_filter($issues,fn($i)=>$i["code"]==="byte-kitsune/architecture-graph/scope-forbidden-entrypoint-method"));
if (count($proofs)!==5 || count($issues)!==5) throw new RuntimeException("Alias corpus did not produce exactly five denials.");
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
$issues=array_values(array_filter(json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["issues"],fn($i)=>$i["code"]!=="byte-kitsune/architecture-graph/analysis-attestation"));
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
foreach (["recursive-cycle"=>3,"bounded-recursion"=>2,"scope-allowed-entrypoint-method"=>6,"scope-forbidden-entrypoint-method"=>2] as $suffix=>$expected) {
    if (count(array_filter($codes,fn($code)=>$code==="byte-kitsune/architecture-graph/".$suffix))!==$expected) throw new RuntimeException("Wrong recursion classification: ".$suffix);
}
if (!array_filter($issues,fn($issue)=>$issue["code"]==="byte-kitsune/architecture-graph/bounded-recursion" && str_contains($issue["message"],"walkByTwo"))) throw new RuntimeException("Positive two-step recursion breaker was not proven.");
foreach ($issues as $issue) if ($issue["code"]==="byte-kitsune/architecture-graph/scope-allowed-entrypoint-method") {
    $proof=json_decode(substr($issue["notes"][0],strlen("graph-evidence: ")),true,512,JSON_THROW_ON_ERROR);
    if (($proof["complete"]??null)!==false) throw new RuntimeException("Cycle did not invalidate graph completeness.");
}
echo "Recursion graph corpus passed\n";
' "$report"
cd ../unsafe-property-corpus
set +e
../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note > "$report"
status=$?
set -e
[ "$status" -eq 1 ]
php -r '
$issues=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["issues"];
$incomplete=array_values(array_filter($issues,fn($issue)=>$issue["code"]==="byte-kitsune/architecture-graph/scope-graph-incomplete"));
$proofs=array_values(array_filter($issues,fn($issue)=>str_ends_with($issue["code"],"-entrypoint-method")));
if (count($incomplete)!==1 || $proofs!==[] || !str_contains($incomplete[0]["message"],"Unresolved instance receiver")) throw new RuntimeException("Mutable constructor property was treated as a proof.");
echo "Mutable property corpus passed\n";
' "$report"
cd ../dispatch-corpus
set +e
../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note > "$report"
status=$?
set -e
[ "$status" -eq 1 ]
php -r '
$issues=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["issues"];
$denied=array_values(array_filter($issues,fn($issue)=>str_ends_with($issue["code"],"scope-forbidden-entrypoint-method")));
$incomplete=array_values(array_filter($issues,fn($issue)=>str_ends_with($issue["code"],"scope-graph-incomplete")));
if (count($denied)!==8 || count($incomplete)!==16) throw new RuntimeException("Expanded dispatch classification changed.");
$reasons=implode(" ",array_column($incomplete,"message"));
foreach (["Nested or indirect dispatch", "Dynamic function", "Dynamic or relative construction", "not a static method", "Unresolved instance receiver", "Unproven callback invocation", "function:App\\dynamicHelper", "Unproven Symfony container lookup", "Symfony container lookup result escapes immediate call"] as $reason) if (!str_contains($reasons,$reason)) throw new RuntimeException("Missing dispatch gap: ".$reason);
$edges=[];
foreach ($denied as $issue) {
    $proof=json_decode(substr($issue["notes"][0],strlen("graph-evidence: ")),true,512,JSON_THROW_ON_ERROR);
    if (($proof["complete"]??null)!==false) throw new RuntimeException("Unsafe dispatch certified a complete proof.");
    foreach ($proof["edges"] as $edge) $edges[]=$edge["evidence"];
}
foreach (["non-overridable this method", "literal instance callback", "literal static callback"] as $evidence) if (!str_contains(implode(" ",$edges),$evidence)) throw new RuntimeException("Missing proven dispatch: ".$evidence);
if (!in_array("project function call",$edges,true)) throw new RuntimeException("Project function edge was not included.");
$functionProofs=array_values(array_filter($denied,fn($issue)=>str_contains($issue["notes"][0]??"","App\\\\Entry::namedFunction")));
if (count($functionProofs)!==1) throw new RuntimeException("Project function chain was not reached.");
$functionProof=json_decode(substr($functionProofs[0]["notes"][0],strlen("graph-evidence: ")),true,512,JSON_THROW_ON_ERROR);
if (count($functionProof["edges"]??[])!==3) throw new RuntimeException("Project function chain was truncated.");
$importProofs=array_values(array_filter($denied,fn($issue)=>str_contains($issue["notes"][0]??"","App\\\\Entry::importedFunction")));
if (count($importProofs)!==1) throw new RuntimeException("Imported function alias was not reached.");
echo "Expanded dispatch corpus passed\n";
' "$report"
set +e
ARCHITECTURE_COMPLETE=1 ../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note > "$report"
status=$?
set -e
[ "$status" -eq 1 ]
php -r '
$issues=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["issues"];
$denied=array_values(array_filter($issues,fn($issue)=>str_ends_with($issue["code"],"scope-forbidden-entrypoint-method")));
$incomplete=array_values(array_filter($issues,fn($issue)=>str_ends_with($issue["code"],"scope-graph-incomplete")));
if (count($denied)!==16 || $incomplete!==[]) throw new RuntimeException("Complete readonly dispatch corpus is not complete.");
$edges=[];
foreach ($denied as $issue) {
    $proof=json_decode(substr($issue["notes"][0],strlen("graph-evidence: ")),true,512,JSON_THROW_ON_ERROR);
    if (($proof["complete"]??null)!==true) throw new RuntimeException("Proven readonly dispatch was marked incomplete.");
    foreach ($proof["edges"] as $edge) $edges[]=$edge["evidence"];
    if (str_contains(json_encode($proof,JSON_THROW_ON_ERROR),"inheritedConstruction") && ($proof["edges"][0]["to"]??null)!=="App\\BaseConstruction::__construct") throw new RuntimeException("Inherited constructor was not resolved to its declaration.");
}
if (!in_array("literal constructor call",$edges,true)) throw new RuntimeException("Constructor call was not included in the graph.");
if (count(array_filter($edges,fn($edge)=>$edge==="project function call"))<3) throw new RuntimeException("Complete project function paths were not included.");
foreach (["gateway.service", "gateway.alias", "App\\Gateway"] as $id) if (!str_contains(implode(" ",$edges),"Symfony literal service ID ".$id." -> App\\Gateway")) throw new RuntimeException("Literal container lookup was not proven: ".$id);
if (!in_array("Symfony single-use local service ID gateway.alias -> App\\Gateway",$edges,true)) throw new RuntimeException("Single-use local service lookup was not proven.");
if (count(array_filter($edges,fn($edge)=>$edge==="Symfony straight-line local service ID gateway.service -> App\\Gateway"))<2) throw new RuntimeException("Multiple local service calls were not proven.");
if (!array_filter($denied,fn($issue)=>str_contains($issue["notes"][0]??"","App\\\\Gateway::expensiveWith"))) throw new RuntimeException("Local service argument call was not reached.");
echo "Complete readonly dispatch corpus passed\n";
' "$report"
set +e
ARCHITECTURE_COMPLETE=1 ARCHITECTURE_SERVICE_INCOMPLETE=1 ../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note > "$report"
status=$?
set -e
[ "$status" -eq 1 ]
php -r '
$issues=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["issues"];
$incomplete=array_values(array_filter($issues,fn($issue)=>str_ends_with($issue["code"],"scope-graph-incomplete")));
if (count(array_filter($incomplete,fn($issue)=>str_contains($issue["message"],"Unproven Symfony container lookup")))!==3) throw new RuntimeException("Incomplete service config still supplied container proofs.");
foreach ($issues as $issue) if (str_ends_with($issue["code"],"scope-forbidden-entrypoint-method")) {
    $proof=json_decode(substr($issue["notes"][0],strlen("graph-evidence: ")),true,512,JSON_THROW_ON_ERROR);
    if (($proof["complete"]??null)!==false) throw new RuntimeException("Incomplete service config certified a proof.");
}
echo "Incomplete container configuration corpus passed\n";
' "$report"
cd ../duplicate-corpus
set +e
../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note > "$report"
status=$?
set -e
[ "$status" -eq 1 ]
php -r '
$issues=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["issues"];
$denied=array_values(array_filter($issues,fn($issue)=>$issue["code"]==="byte-kitsune/architecture-graph/scope-forbidden-entrypoint-method"));
$incomplete=array_values(array_filter($issues,fn($issue)=>$issue["code"]==="byte-kitsune/architecture-graph/scope-graph-incomplete"));
if (count($denied)!==1 || count($incomplete)<2) throw new RuntimeException("Duplicate stage or function did not fail closed.");
if (!str_contains(implode(" ",array_column($incomplete,"message")),"Duplicate or unresolved declaration App\\duplicateHelper")) throw new RuntimeException("Duplicate function was not reported.");
$proof=json_decode(substr($denied[0]["notes"][0],strlen("graph-evidence: ")),true,512,JSON_THROW_ON_ERROR);
if (($proof["complete"]??null)!==false || count($proof["edges"]??[])!==1) throw new RuntimeException("Duplicate stage certified a graph proof.");
echo "Duplicate declaration corpus passed\n";
' "$report"
cd ../proof-size-corpus
set +e
../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note > "$report"
status=$?
set -e
[ "$status" -eq 1 ]
php -r '
$issues=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["issues"];
$codes=array_column($issues,"code");
$prefix="byte-kitsune/architecture-graph/";
if (count(array_filter($codes,fn($code)=>$code===$prefix."scope-graph-incomplete"))!==1) throw new RuntimeException("Oversized graph proof did not report incomplete coverage.");
if (count(array_filter($codes,fn($code)=>$code===$prefix."scope-forbidden-entrypoint-method"))!==0) throw new RuntimeException("Oversized graph proof was published.");
$attestations=array_values(array_filter($issues,fn($issue)=>$issue["code"]===$prefix."analysis-attestation"));
if (count($attestations)!==1) throw new RuntimeException("Missing graph attestation.");
$note=$attestations[0]["notes"][0]??"";
if (!str_starts_with($note,"extension-attestation: ")) throw new RuntimeException("Missing structured attestation.");
$attestation=json_decode(substr($note,strlen("extension-attestation: ")),true,512,JSON_THROW_ON_ERROR);
if (($attestation["complete"]??null)!==false || ($attestation["source_files"]??null)!==2) throw new RuntimeException("Oversized proof was attested complete.");
echo "Oversized proof attestation corpus passed\n";
' "$report"
cd ../local-receiver-corpus
set +e
../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note > "$report"
status=$?
set -e
[ "$status" -eq 1 ]
php -r '
$issues=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["issues"];
$prefix="byte-kitsune/architecture-graph/";
$denied=array_values(array_filter($issues,fn($issue)=>$issue["code"]===$prefix."scope-forbidden-entrypoint-method"));
$incomplete=array_values(array_filter($issues,fn($issue)=>$issue["code"]===$prefix."scope-graph-incomplete"));
if (count($denied)!==2 || count($incomplete)!==3) throw new RuntimeException("Local receiver coverage changed.");
$reasons=implode(" ",array_column($incomplete,"message"));
foreach (["reassigned","branched","escaped"] as $method) if (!str_contains($reasons,$method)) throw new RuntimeException("Unsafe local receiver was not rejected: ".$method);
$evidence=[];
foreach ($denied as $issue) {
    $proof=json_decode(substr($issue["notes"][0],strlen("graph-evidence: ")),true,512,JSON_THROW_ON_ERROR);
    if (($proof["complete"]??null)!==false) throw new RuntimeException("Unsafe receiver scope was certified complete.");
    $evidence[]=$proof["edges"][0]["evidence"]??"";
}
foreach (["literal temporary instance","straight-line local instance"] as $name) if (!str_contains(implode(" ",$evidence),$name)) throw new RuntimeException("Missing exact receiver evidence: ".$name);
echo "Conservative local receiver corpus passed\n";
' "$report"
set +e
ARCHITECTURE_SAFE=1 ../../vendor/bin/mago analyze --reporting-format json --minimum-report-level note > "$report"
status=$?
set -e
[ "$status" -eq 1 ]
php -r '
$issues=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR)["issues"];
$prefix="byte-kitsune/architecture-graph/";
$denied=array_values(array_filter($issues,fn($issue)=>$issue["code"]===$prefix."scope-forbidden-entrypoint-method"));
if (count($denied)!==2 || count(array_filter($issues,fn($issue)=>$issue["code"]===$prefix."scope-graph-incomplete"))!==0) throw new RuntimeException("Exact receivers did not form a complete scoped graph.");
foreach ($denied as $issue) {
    $proof=json_decode(substr($issue["notes"][0],strlen("graph-evidence: ")),true,512,JSON_THROW_ON_ERROR);
    if (($proof["complete"]??null)!==true) throw new RuntimeException("Exact receiver proof was marked incomplete.");
}
$attestations=array_values(array_filter($issues,fn($issue)=>$issue["code"]===$prefix."analysis-attestation"));
if (count($attestations)!==1) throw new RuntimeException("Missing exact receiver attestation.");
$note=$attestations[0]["notes"][0]??"";
$attestation=json_decode(substr($note,strlen("extension-attestation: ")),true,512,JSON_THROW_ON_ERROR);
if (($attestation["complete"]??null)!==true) throw new RuntimeException("Exact receiver graph was not attested complete.");
echo "Complete local receiver corpus passed\n";
' "$report"
