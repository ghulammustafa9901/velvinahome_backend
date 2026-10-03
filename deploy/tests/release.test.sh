#!/usr/bin/env bash
# Tests for release.sh / rollback.sh with stub php, composer and curl (no server needed):
#   bash deploy/tests/release.test.sh
# Covers: first release, migration gate blocking a release, failed health check rolling back
# automatically, manual rollback, pruning of old releases.
#
# Assertions are strings evaluated later by expect(), so they are single-quoted on purpose
# (SC2016) and read $status there (SC2034); release names are generated, so ls is fine (SC2012).
# shellcheck disable=SC2016,SC2034,SC2012

set -Euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
DEPLOY="$(cd "$HERE/.." && pwd)"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

failures=0
ok() { printf '  ok   %s\n' "$1"; }
bad() { printf '  FAIL %s\n' "$1"; failures=$((failures + 1)); }
expect() { if eval "$2"; then ok "$1"; else bad "$1"; fi; }

# A repository with one commit per release.
git init --quiet --initial-branch=main "$WORK/src"
git -C "$WORK/src" -c user.email=t@t -c user.name=t commit --quiet --allow-empty -m init
commit() { echo "$1" >"$WORK/src/VERSION"; git -C "$WORK/src" add VERSION; git -C "$WORK/src" -c user.email=t@t -c user.name=t commit --quiet -m "$1"; }

# Stubs. php records `config:cache`'s APP_RELEASE in the release; curl serves it back as
# /health would, from whatever `current` points to.
mkdir -p "$WORK/bin"
cat >"$WORK/bin/php" <<'EOF'
#!/usr/bin/env bash
echo "php $*" >>"$STUB_LOG"
case "$2" in
  config:cache) echo "$APP_RELEASE" >.stub-release ;;
  tenants:migrate) [[ "${FAIL_MIGRATE:-0}" == 1 ]] && exit 1 ;;
esac
exit 0
EOF
cat >"$WORK/bin/composer" <<'EOF'
#!/usr/bin/env bash
exit 0
EOF
cat >"$WORK/bin/curl" <<'EOF'
#!/usr/bin/env bash
[[ "${FAIL_HEALTH:-0}" == 1 ]] && exit 22
printf '{"status":"ok","checks":{"database":true,"redis":true,"search":true},"release":"%s"}' "$(cat "$DEPLOY_BASE/current/.stub-release")"
EOF
chmod +x "$WORK/bin/"*

export DEPLOY_BASE="$WORK/base" REPO_URL="$WORK/src" HEALTH_URL=http://stub/health HEALTH_TOKEN=t
export PHP_BIN="$WORK/bin/php" COMPOSER_BIN="$WORK/bin/composer" CURL_BIN="$WORK/bin/curl"
export RELOAD_FPM=true HEALTH_ATTEMPTS=2 HEALTH_DELAY=0 KEEP_RELEASES=3 STUB_LOG="$WORK/php.log"
mkdir -p "$DEPLOY_BASE/shared" && echo "APP_KEY=x" >"$DEPLOY_BASE/shared/.env"

live() { cat "$DEPLOY_BASE/current/VERSION"; }
count() { ls -1 "$DEPLOY_BASE/releases" | wc -l; }
deploy() { sleep 1; bash "$DEPLOY/release.sh" main >"$WORK/out.log" 2>&1; }

echo "release.sh"
commit v1; deploy; status=$?
expect "first release succeeds" '[[ $status == 0 && "$(live)" == v1 ]]'
expect "shared .env and storage are linked" '[[ -L "$DEPLOY_BASE/current/.env" && -L "$DEPLOY_BASE/current/storage" ]]'
expect "migrations ran before the switch" 'grep -q "artisan landlord:migrate" "$STUB_LOG" && grep -q "artisan tenants:migrate" "$STUB_LOG"'

commit v2; FAIL_MIGRATE=1 deploy; status=$?
expect "failed tenant migration blocks the release" '[[ $status != 0 && "$(live)" == v1 ]]'
expect "blocked release is removed" '[[ $(count) == 1 ]]'

commit v3; deploy; status=$?
expect "next release goes live" '[[ $status == 0 && "$(live)" == v3 ]]'

commit v4; FAIL_HEALTH=1 deploy; status=$?
expect "unhealthy release rolls back automatically" '[[ $status != 0 && "$(live)" == v3 ]]'
expect "rollback is reported" 'grep -q "rolling back" "$WORK/out.log"'

echo "rollback.sh"
bash "$DEPLOY/rollback.sh" >/dev/null 2>&1; status=$?
expect "rolls back to the release before the live one" '[[ $status == 0 && "$(live)" == v1 ]]'

echo "pruning"
for v in v5 v6 v7; do commit "$v"; deploy; done
expect "keeps KEEP_RELEASES releases" '[[ $(count) == 3 && "$(live)" == v7 ]]'

if ((failures > 0)); then
  echo "$failures failure(s)"
  exit 1
fi
echo "all passed"
