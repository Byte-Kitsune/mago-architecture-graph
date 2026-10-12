#!/bin/sh
set -eu
root=$(CDPATH= cd -- "$(dirname "$0")/.." && pwd)
mago_bin=${MAGO_BIN:-"$root/vendor/bin/mago"}
report=$(mktemp)
baseline=$(mktemp)
policy=$(mktemp)
trap 'rm -f "$report" "$baseline" "$policy"' EXIT HUP INT TERM
cd "$root/tests/namespace-corpus"
run() {
    set +e
    "$mago_bin" analyze --reporting-format json --minimum-report-level note > "$report"
    status=$?
    set -e
    [ "$status" -eq "$2" ]
    php "$root/tests/check-namespace-report.php" "$report" "$1"
}
# No dependency_mode and no use imports: real declaration paths must identify
# peers even though their namespace does not mirror the module directory.
run default 1
cp "$report" "$baseline"
export NAMESPACE_POLICY_PATH="$policy"
php -r '$p=json_decode(file_get_contents("policy.json"),true,64,JSON_THROW_ON_ERROR); $p["dependency_mode"]="class-references"; file_put_contents($argv[1],json_encode($p,JSON_THROW_ON_ERROR));' "$policy"
run class-references 1
cmp "$report" "$baseline"
php -r '$p=json_decode(file_get_contents("policy.json"),true,64,JSON_THROW_ON_ERROR); $p["dependency_mode"]="imports"; file_put_contents($argv[1],json_encode($p,JSON_THROW_ON_ERROR));' "$policy"
run imports 0
