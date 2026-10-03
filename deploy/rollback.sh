#!/usr/bin/env bash
# Switch `current` back to an earlier release (runbook: docs/runbooks/deploy.md).
#
#   deploy/rollback.sh                 # the release before the live one
#   deploy/rollback.sh <release dir>   # a named one from releases/
#
# Code only: database migrations are not reversed. Because migrations are expand-only, the
# previous release runs on the newer schema. A schema problem is fixed forward, or by
# restoring a tenant (docs/runbooks/restore-tenant.md).

set -Eeuo pipefail

: "${DEPLOY_BASE:?DEPLOY_BASE is required}"
PHP_BIN="${PHP_BIN:-php}"
RELOAD_FPM="${RELOAD_FPM:-sudo systemctl reload php8.4-fpm}"

cd "$DEPLOY_BASE"
exec 9>"$DEPLOY_BASE/.deploy.lock"
flock -n 9 || { echo "another deploy is running" >&2; exit 1; }

live="$(basename "$(readlink -f current)")"

if [[ $# -ge 1 ]]; then
  target="$1"
else
  # Release names are <stamp>-<sha> (release.sh), so ls output is safe to parse.
  # shellcheck disable=SC2012
  target="$(ls -1 releases | sort | grep -B1 -x "$live" | head -n1)"
  [[ "$target" != "$live" ]] || target=""
fi

[[ -n "$target" && -d "releases/$target" ]] || { echo "no release to roll back to (live: $live)" >&2; exit 1; }
[[ "$target" != "$live" ]] || { echo "$target is already live" >&2; exit 1; }

ln -sfn "$DEPLOY_BASE/releases/$target" current.next
mv -Tf current.next current
eval "$RELOAD_FPM"
(cd current && "$PHP_BIN" artisan horizon:terminate) || true

echo "rolled back: $live → $target"
