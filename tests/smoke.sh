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
php -r '$r=json_decode(file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR); $c=array_column($r["issues"],"code"); foreach(["byte-kitsune/architecture-graph/foreign-module-instance","byte-kitsune/architecture-graph/forbidden-internal-access"] as $required) if(count(array_filter($c,fn($v)=>$v===$required))!==1) { fwrite(STDERR,json_encode($c)); exit(1); } echo "Architecture corpus passed\n";' "$report"
