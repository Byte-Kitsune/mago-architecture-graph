#!/usr/bin/env sh
set -eu
root=$(CDPATH= cd -- "$(dirname -- "$0")/.." && pwd)
php_bin=${PHP_BIN:-php}
mago_bin=${MAGO_BIN:-$root/vendor/bin/mago}
export PATH="$(dirname -- "$php_bin"):$PATH"
output=$(mktemp -d)
trap 'rm -rf "$output"' EXIT
export MAGO_GRAPH_SNAPSHOT="$output/snapshot.json"
"$mago_bin" --workspace "$root/tests/inspection-corpus" analyze --reporting-format json > "$output/report.json"
"$php_bin" "$root/tests/check-inspection.php" "$output/snapshot.json"
