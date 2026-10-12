#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
mago_bin=${MAGO_BIN:-"$root/vendor/bin/mago"}
work=$(mktemp -d)
report=$(mktemp)
second=$(mktemp)
trap 'rm -rf "$work"; rm -f "$report" "$second"' EXIT HUP INT TERM
cd "$root"
php tests/requirements-policy.php
php tests/class-reference-collector.php
cd tests/requirements-corpus
run() {
    set +e
    "$mago_bin" analyze --reporting-format json --minimum-report-level note > "$report"
    status=$?
    set -e
    [ "$status" -eq 1 ]
    php "$root/tests/check-requirements-report.php" "$report" "$1"
}
run imports
cp -R "$root/tests/requirements-corpus/." "$work/"
export NEUTRAL_AUTOLOAD="$root/tests/source-autoload.php"
export NEUTRAL_POLICY_PATH="$work/policy.json"
php -r '$p=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);$p["dependency_mode"]="class-references";file_put_contents($argv[1],json_encode($p,JSON_THROW_ON_ERROR));' "$work/policy.json"
run class-references
# Repeat the entire matrix without ordinary import aliases; class dependencies
# must still resolve to the same targets and preserve every policy decision.
php "$root/tests/prepare-literal-corpus.php" "$work" "$root"
cd "$work"
run class-references
cp "$report" "$second"
run class-references
cmp "$report" "$second"
# An over-budget snapshot must produce an explicit completed error finding,
# rather than silently skipping dependency coverage or crashing the worker.
php -r 'file_put_contents($argv[1], "<?php\nnamespace Sample\\Cells\\Amber\\Service;\n" . str_repeat(" ", 8 * 1024 * 1024));' "$work/app/Cells/Amber/Service/Oversize.php"
set +e
"$mago_bin" analyze --reporting-format json --minimum-report-level note > "$report"
status=$?
set -e
[ "$status" -eq 1 ]
php -r '$r=json_decode(file_get_contents($argv[1]),true,64,JSON_THROW_ON_ERROR);$i=array_values(array_filter($r["issues"],static fn($i)=>$i["code"]==="byte-kitsune/architecture-graph/architecture-reference-incomplete"));if(count($i)!==1 || $i[0]["level"]!=="Error")throw new RuntimeException("Oversized reference coverage did not fail explicitly.");echo "Real Mago oversized reference coverage failed closed\n";' "$report"
