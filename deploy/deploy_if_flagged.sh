#!/usr/bin/env bash
set -Eeuo pipefail

APP_PATH_DEFAULT="/home/tbkd2629/kost-app"
CONFIG_FILE="${DEPLOY_CONFIG:-$APP_PATH_DEFAULT/deploy/deploy.env}"

log() {
  printf '[%s] %s\n' "$(date '+%Y-%m-%d %H:%M:%S %z')" "$*"
}

if [ ! -f "$CONFIG_FILE" ]; then
  log "No deploy config found at $CONFIG_FILE; skipping."
  exit 0
fi

# shellcheck disable=SC1090
source "$CONFIG_FILE"

APP_PATH="${APP_PATH:-$APP_PATH_DEFAULT}"
PUBLIC_PATH="${PUBLIC_PATH:-/home/tbkd2629/public_html}"
BRANCH="${BRANCH:-main}"
PHP_BIN="${PHP_BIN:-php}"
COMPOSER_BIN="${COMPOSER_BIN:-composer}"
GIT_BIN="${GIT_BIN:-git}"
RUN_MIGRATIONS="${RUN_MIGRATIONS:-0}"
FLAG_FILE="$APP_PATH/storage/app/deploy.flag"
LOCK_DIR="$APP_PATH/storage/app/deploy.lock"
LOG_DIR="$APP_PATH/storage/logs"

mkdir -p "$APP_PATH/storage/app" "$LOG_DIR" "$APP_PATH/bootstrap/cache"

if [ ! -f "$FLAG_FILE" ]; then
  exit 0
fi

if ! mkdir "$LOCK_DIR" 2>/dev/null; then
  log "Deploy already running; skipping."
  exit 0
fi
trap 'rmdir "$LOCK_DIR" 2>/dev/null || true' EXIT

REQUESTED_COMMIT="$(grep '^commit=' "$FLAG_FILE" 2>/dev/null | head -n1 | cut -d= -f2- || true)"
REQUESTED_BRANCH="$(grep '^branch=' "$FLAG_FILE" 2>/dev/null | head -n1 | cut -d= -f2- || true)"
REQUESTED_BRANCH="${REQUESTED_BRANCH:-$BRANCH}"

if [ "$REQUESTED_BRANCH" != "$BRANCH" ]; then
  log "Flag branch '$REQUESTED_BRANCH' does not match configured branch '$BRANCH'; removing flag without deploy."
  rm -f "$FLAG_FILE"
  exit 0
fi

if [ -z "${REPO_URL:-}" ]; then
  log "REPO_URL is empty in $CONFIG_FILE; cannot deploy."
  exit 1
fi

log "Starting deploy for branch=$BRANCH commit=${REQUESTED_COMMIT:-latest}"

if [ ! -d "$APP_PATH/.git" ]; then
  log "No git checkout found; cloning into $APP_PATH.tmp-clone"
  TMP_CLONE="$APP_PATH.tmp-clone"
  rm -rf "$TMP_CLONE"
  "$GIT_BIN" clone --branch "$BRANCH" --depth 1 "$REPO_URL" "$TMP_CLONE"

  mkdir -p "$APP_PATH/storage" "$APP_PATH/bootstrap/cache"
  if [ -f "$APP_PATH/.env" ]; then
    cp "$APP_PATH/.env" "$TMP_CLONE/.env"
  fi
  if [ -d "$APP_PATH/storage" ]; then
    rm -rf "$TMP_CLONE/storage"
    cp -R "$APP_PATH/storage" "$TMP_CLONE/storage"
  fi
  if [ -f "$CONFIG_FILE" ]; then
    mkdir -p "$TMP_CLONE/deploy"
    cp "$CONFIG_FILE" "$TMP_CLONE/deploy/deploy.env"
  fi
  rm -rf "$APP_PATH.old"
  mv "$APP_PATH" "$APP_PATH.old"
  mv "$TMP_CLONE" "$APP_PATH"
  mkdir -p "$APP_PATH/storage/app" "$APP_PATH/storage/logs" "$APP_PATH/bootstrap/cache"
else
  cd "$APP_PATH"
  "$GIT_BIN" fetch origin "$BRANCH" --prune
  if [ -n "$REQUESTED_COMMIT" ]; then
    "$GIT_BIN" checkout -f "$BRANCH"
    "$GIT_BIN" reset --hard "$REQUESTED_COMMIT"
  else
    "$GIT_BIN" checkout -f "$BRANCH"
    "$GIT_BIN" reset --hard "origin/$BRANCH"
  fi
fi

cd "$APP_PATH"

if command -v "$COMPOSER_BIN" >/dev/null 2>&1; then
  "$COMPOSER_BIN" install --no-dev --optimize-autoloader --no-interaction
else
  log "Composer not available; skipping composer install. Vendor must already exist."
fi

if [ ! -f "$APP_PATH/public/build/manifest.json" ]; then
  log "public/build/manifest.json missing. Build assets locally and commit them before deploy."
  exit 1
fi

mkdir -p "$PUBLIC_PATH"
rm -rf "$PUBLIC_PATH/build" "$PUBLIC_PATH/storage"
cp -R "$APP_PATH/public/." "$PUBLIC_PATH"
ln -sfn "$APP_PATH/storage/app/public" "$PUBLIC_PATH/storage"

PUBLIC_PATH="$PUBLIC_PATH" "$PHP_BIN" -r '$file = getenv("PUBLIC_PATH") . "/index.php"; $contents = file_get_contents($file); $contents = str_replace("__DIR__." . "\047/../vendor/autoload.php\047", "__DIR__." . "\047/../kost-app/vendor/autoload.php\047", $contents); $contents = str_replace("__DIR__." . "\047/../bootstrap/app.php\047", "__DIR__." . "\047/../kost-app/bootstrap/app.php\047", $contents); file_put_contents($file, $contents);'

if [ "$RUN_MIGRATIONS" = "1" ]; then
  "$PHP_BIN" artisan migrate --force
else
  log "RUN_MIGRATIONS is not 1; skipping migrations."
fi

"$PHP_BIN" artisan optimize:clear
"$PHP_BIN" artisan optimize

rm -f "$FLAG_FILE"
log "Deploy complete: $("$GIT_BIN" rev-parse --short HEAD 2>/dev/null || echo unknown)"
