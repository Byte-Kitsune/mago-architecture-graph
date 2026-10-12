#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
mago_bin=${MAGO_BIN:-"$root/vendor/bin/mago"}
report=$(mktemp)
second=$(mktemp)
trap 'rm -f "$report" "$second"' EXIT HUP INT TERM
cd "$root"
php tests/instance-policy.php
cd tests/instance-corpus
set +e
"$mago_bin" analyze --reporting-format json --minimum-report-level note > "$report"
status=$?
set -e
[ "$status" -eq 1 ]
php ../check-instance-report.php "$report"
set +e
"$mago_bin" analyze --reporting-format json --minimum-report-level note > "$second"
status=$?
set -e
[ "$status" -eq 1 ]
php ../check-instance-report.php "$second"
cmp "$report" "$second"
