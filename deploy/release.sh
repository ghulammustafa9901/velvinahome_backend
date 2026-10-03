#!/usr/bin/env bash
# Zero-downtime release of platform-api (spec §9, §13 Phase 7). Hosting-neutral: any Linux
# server with PHP-FPM, a process supervisor for Horizon and cron for the scheduler.
#
# Layout under $DEPLOY_BASE:
#   repo/                     bare mirror of the repository (created on first run)
#   releases/<stamp>-<sha>/   one directory per release, never changed after it went live
#   shared/.env               the environment (secrets live only here)
#   shared/storage/           logs, framework cache, local disks
#   current -> releases/...   what PHP-FPM, Horizon and cron run
#
# Order (runbook: docs/runbooks/deploy.md):
#   build → cache config → landlord:migrate → tenants:migrate (any tenant failing blocks the
#   release; the old code keeps serving) → atomic switch → reload PHP-FPM, restart Horizon →
#   health check against the new release → on failure, switch back automatically.
#
# Migrations run while the previous release is still serving, so they must be expand-only
# (add tables/columns/indexes; drop or rename only in a later release). See the runbook.
#
#   DEPLOY_BASE=/srv/platform-api REPO_URL=git@github.com:org/platform-api.git \
#   HEALTH_URL=https://platform.example.com/health HEALTH_TOKEN=... \
#   deploy/release.sh <git ref>

set -Eeuo pipefail

REF="${1:?usage: release.sh <git ref>}"
: "${DEPLOY_BASE:?DEPLOY_BASE is required}"
: "${REPO_URL:?REPO_URL is required}"
: "${HEALTH_URL:?HEALTH_URL is required}"
: "${HEALTH_TOKEN:?HEALTH_TOKEN is required}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
GIT_BIN="${GIT_BIN:-git}"
CURL_BIN="${CURL_BIN:-curl}"
RELOAD_FPM="${RELOAD_FPM:-sudo systemctl reload php8.4-fpm}"
KEEP_RELEASES="${KEEP_RELEASES:-5}"
HEALTH_ATTEMPTS="${HEALTH_ATTEMPTS:-10}"
HEALTH_DELAY="${HEALTH_DELAY:-3}"

log() { printf '[%s] %s\n' "$(date -u +%H:%M:%S)" "$*"; }
die() { log "FAILED: $*"; exit 1; }

cd "$DEPLOY_BASE"
mkdir -p releases shared/storage
[[ -f shared/.env ]] || die "shared/.env is missing"

exec 9>"$DEPLOY_BASE/.deploy.lock"
flock -n 9 || die "another deploy is running"

# 1. Code ------------------------------------------------------------------------------------
if [[ ! -d repo ]]; then
  "$GIT_BIN" clone --mirror --quiet "$REPO_URL" repo
fi
"$GIT_BIN" -C repo remote update --prune >/dev/null
SHA="$("$GIT_BIN" -C repo rev-parse --verify "${REF}^{commit}")" || die "unknown ref $REF"
RELEASE="$(date -u +%Y%m%d%H%M%S)-${SHA:0:12}"
NEW="$DEPLOY_BASE/releases/$RELEASE"
PREVIOUS="$(readlink -f current 2>/dev/null || true)"

log "release $RELEASE (ref $REF)${PREVIOUS:+, previous $(basename "$PREVIOUS")}"
mkdir -p "$NEW"
"$GIT_BIN" -C repo archive "$SHA" | tar -x -C "$NEW"

# A failed build or gate removes the half-built release; the live one is untouched.
cleanup_failed_build() { log "removing unfinished release $RELEASE"; rm -rf "$NEW"; }
trap cleanup_failed_build ERR

# 2. Build -----------------------------------------------------------------------------------
rm -rf "$NEW/storage"
ln -s "$DEPLOY_BASE/shared/storage" "$NEW/storage"
ln -s "$DEPLOY_BASE/shared/.env" "$NEW/.env"
mkdir -p "$DEPLOY_BASE"/shared/storage/{app,logs,framework/{cache,sessions,views}}

(cd "$NEW" && "$COMPOSER_BIN" install --no-dev --no-interaction --prefer-dist --optimize-autoloader --no-progress)

# The release id is baked into the cached config: error reports and /health name it.
(cd "$NEW" && APP_RELEASE="$RELEASE" "$PHP_BIN" artisan config:cache)
(cd "$NEW" && "$PHP_BIN" artisan route:cache && "$PHP_BIN" artisan event:cache \
  && "$PHP_BIN" artisan view:cache && "$PHP_BIN" artisan filament:optimize)

# 3. Migration gate --------------------------------------------------------------------------
log "landlord migrations"
(cd "$NEW" && "$PHP_BIN" artisan landlord:migrate)
log "tenant migrations (one transaction per tenant; any failure blocks the release)"
if ! (cd "$NEW" && "$PHP_BIN" artisan tenants:migrate); then
  trap - ERR
  cleanup_failed_build
  die "tenant migrations failed — release blocked, ${PREVIOUS:+$(basename "$PREVIOUS") }still live"
fi

trap - ERR

# 4. Switch ----------------------------------------------------------------------------------
switch_to() {
  ln -sfn "$1" "$DEPLOY_BASE/current.next"
  mv -Tf "$DEPLOY_BASE/current.next" "$DEPLOY_BASE/current"
  # FPM must drop its opcache (validate_timestamps=0); Horizon's supervisor restarts it
  # from `current`; the scheduler is started by cron from `current` each minute anyway.
  eval "$RELOAD_FPM"
  (cd "$DEPLOY_BASE/current" && "$PHP_BIN" artisan horizon:terminate) || true
}

log "switching to $RELEASE"
switch_to "$NEW"

# 5. Verify ----------------------------------------------------------------------------------
healthy() {
  local body
  body="$("$CURL_BIN" -fsS --max-time 10 -H "X-Health-Token: $HEALTH_TOKEN" "$HEALTH_URL")" || return 1
  # The new code must be the one answering, and the core dependencies reachable. The queue
  # backlog and failed-job flags are alerts, not release blockers.
  grep -q "\"release\":\"$RELEASE\"" <<<"$body" \
    && grep -q '"database":true' <<<"$body" \
    && grep -q '"redis":true' <<<"$body" \
    && grep -q '"search":true' <<<"$body"
}

for ((i = 1; i <= HEALTH_ATTEMPTS; i++)); do
  if healthy; then
    log "healthy"
    break
  fi
  if ((i == HEALTH_ATTEMPTS)); then
    if [[ -n "$PREVIOUS" ]]; then
      log "health check failed — rolling back to $(basename "$PREVIOUS")"
      switch_to "$PREVIOUS"
    fi
    die "release $RELEASE is not healthy"
  fi
  sleep "$HEALTH_DELAY"
done

# 6. Prune -----------------------------------------------------------------------------------
# Release names are generated above (<stamp>-<sha>), so ls output is safe to parse.
# shellcheck disable=SC2012
mapfile -t old < <(ls -1d releases/*/ 2>/dev/null | sort | head -n "-$KEEP_RELEASES")
for dir in "${old[@]}"; do
  [[ "$(readlink -f "$dir")" == "$(readlink -f current)" ]] || rm -rf "$dir"
done

log "done: $RELEASE is live"
