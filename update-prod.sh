#!/usr/bin/env bash
# Pull latest code, run DB migrations, rebuild frontends (production).
#
# Usage (on the server, preferably as the app owner — not with sudo):
#   cd /path/to/delivery && bash update-prod.sh
#
# If you accidentally run as root, the script re-execs as the directory owner.
#
# Optional:
#   bash update-prod.sh --skip-npm
#   bash update-prod.sh --skip-mercure
#   APP_OWNER=ciciu bash update-prod.sh   # force deploy user
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SKIP_NPM=0
SKIP_MERCURE=0

for arg in "$@"; do
  case "$arg" in
    --skip-npm) SKIP_NPM=1 ;;
    --skip-mercure) SKIP_MERCURE=1 ;;
    -h|--help)
      sed -n '2,14p' "$0"
      exit 0
      ;;
    *)
      echo "Unknown option: $arg" >&2
      exit 1
      ;;
  esac
done

log() { printf '\n=== %s ===\n' "$*"; }

file_owner() {
  stat -c '%U' "$1" 2>/dev/null || stat -f '%Su' "$1"
}

file_group() {
  stat -c '%G' "$1" 2>/dev/null || stat -f '%Sg' "$1"
}

# Owner of the project tree (or APP_OWNER override). All deploy writes run as this user.
# Prefer a non-root owner: .git is often left root-owned after a mistaken "sudo git".
detect_owner() {
  if [[ -n "${APP_OWNER:-}" ]]; then
    printf '%s' "$APP_OWNER"
    return
  fi
  local path u
  for path in "$ROOT" "$ROOT/backend" "$ROOT/admin_frontend" "$ROOT/.git"; do
    [[ -e "$path" ]] || continue
    u="$(file_owner "$path")"
    if [[ -n "$u" && "$u" != "root" ]]; then
      printf '%s' "$u"
      return
    fi
  done
  if [[ $EUID -ne 0 ]]; then
    id -un
    return
  fi
  printf 'root'
}

detect_group() {
  if [[ -n "${APP_GROUP:-}" ]]; then
    printf '%s' "$APP_GROUP"
    return
  fi
  local path g owner
  owner="$(detect_owner)"
  for path in "$ROOT" "$ROOT/backend" "$ROOT/admin_frontend" "$ROOT/.git"; do
    [[ -e "$path" ]] || continue
    if [[ "$(file_owner "$path")" == "$owner" ]]; then
      g="$(file_group "$path")"
      if [[ -n "$g" ]]; then
        printf '%s' "$g"
        return
      fi
    fi
  done
  id -gn
}

can_sudo() {
  # Prefer passwordless check; interactive sudo is still OK later in run_sudo.
  sudo -n true 2>/dev/null
}

run_sudo() {
  if [[ $EUID -eq 0 ]]; then
    "$@"
    return
  fi
  # Interactive sudo (password prompt) is fine — do not require NOPASSWD.
  if ! sudo "$@"; then
    echo "sudo failed for: $*" >&2
    echo "Fix once manually, then re-run as the app user:" >&2
    echo "  sudo chown -R \"$(detect_owner):$(detect_group)\" \"$ROOT\"" >&2
    echo "  bash update-prod.sh" >&2
    exit 1
  fi
}

OWNER="$(detect_owner)"
GROUP="$(detect_group)"
CURRENT="$(id -un)"

if [[ -z "$OWNER" || "$OWNER" == "root" ]]; then
  echo "Project looks owned by root. Fix once, then re-run as the app user:" >&2
  echo "  sudo chown -R YOUR_USER:YOUR_USER \"$ROOT\"" >&2
  echo "  cd \"$ROOT\" && bash update-prod.sh" >&2
  exit 1
fi

# Never mutate the tree as root — that creates the next permission failure.
if [[ $EUID -eq 0 ]]; then
  echo "Running as root — re-executing as $OWNER (avoids root-owned files)."
  exec sudo -u "$OWNER" -H bash "$ROOT/update-prod.sh" "$@"
fi

if [[ "$CURRENT" != "$OWNER" ]]; then
  if id "$OWNER" >/dev/null 2>&1 && can_sudo; then
    echo "Current user is $CURRENT, project owner is $OWNER — re-executing as $OWNER."
    exec sudo -u "$OWNER" -H bash "$ROOT/update-prod.sh" "$@"
  fi
  echo "Run this script as \"$OWNER\" (project owner), not as \"$CURRENT\"." >&2
  echo "  sudo -u $OWNER -H bash $ROOT/update-prod.sh" >&2
  exit 1
fi

cd "$ROOT"

if [[ ! -d .git ]]; then
  echo "Not a git repository: $ROOT" >&2
  exit 1
fi

if [[ ! -f backend/bin/console ]]; then
  echo "backend/bin/console not found — wrong directory?" >&2
  exit 1
fi

log "0. Fix ownership (root leftovers from previous sudo deploys)"
# Only reclaim paths that must be writable during deploy / by PHP.
PATHS_TO_OWN=(
  "$ROOT/.git"
  "$ROOT/backend/var"
  "$ROOT/backend/vendor"
  "$ROOT/backend/public"
  "$ROOT/admin_frontend/node_modules"
  "$ROOT/admin_frontend/dist"
  "$ROOT/driver_frontend/node_modules"
  "$ROOT/driver_frontend/dist"
)
for path in "${PATHS_TO_OWN[@]}"; do
  [[ -e "$path" ]] || continue
  # Skip if already fully owned by us (cheap check on the directory itself).
  path_owner="$(file_owner "$path")"
  if [[ "$path_owner" != "$OWNER" ]] || [[ ! -w "$path" ]]; then
    echo "chown $OWNER:$GROUP → $path"
    run_sudo chown -R "$OWNER:$GROUP" "$path"
  elif find "$path" -user root -print -quit 2>/dev/null | grep -q .; then
    echo "chown $OWNER:$GROUP → $path (root-owned files inside)"
    run_sudo chown -R "$OWNER:$GROUP" "$path"
  fi
done

# PHP-FPM often runs as www-data; keep var group-writable when that group exists.
if getent group www-data >/dev/null 2>&1; then
  mkdir -p "$ROOT/backend/var"
  run_sudo chown -R "$OWNER:www-data" "$ROOT/backend/var"
  run_sudo chmod -R ug+rwX "$ROOT/backend/var"
fi

if [[ ! -w .git ]]; then
  echo "Still cannot write to .git after chown. Aborting." >&2
  exit 1
fi

log "1. Git pull (ff-only) as $OWNER"
git fetch --prune origin
BRANCH="$(git rev-parse --abbrev-ref HEAD)"
BEFORE="$(git rev-parse HEAD)"
git pull --ff-only origin "$BRANCH"
AFTER="$(git rev-parse HEAD)"
if [[ "$BEFORE" == "$AFTER" ]]; then
  echo "Already up to date ($BRANCH @ ${AFTER:0:7})"
else
  echo "Updated $BRANCH: ${BEFORE:0:7} → ${AFTER:0:7}"
  git --no-pager log --oneline "${BEFORE}..${AFTER}"
fi

# Real env vars win over .env / .env.local. Without this, composer auto-scripts
# run cache:clear as APP_ENV=dev and fail after --no-dev (DebugBundle missing).
export APP_ENV=prod
export APP_DEBUG=0

log "2. Composer install"
cd "$ROOT/backend"
mkdir -p var config/jwt
# Skip auto-scripts: we clear/warmup cache explicitly for prod below.
composer install --no-dev --optimize-autoloader --no-interaction --no-scripts

log "3. Doctrine migrations"
php bin/console doctrine:migrations:migrate --no-interaction --env=prod

log "4. Symfony cache (prod)"
php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:warmup --env=prod

if [[ "$SKIP_NPM" -eq 0 ]]; then
  log "5. Build admin frontend"
  cd "$ROOT/admin_frontend"
  npm install --no-fund --no-audit
  npm run build

  log "6. Build driver frontend"
  cd "$ROOT/driver_frontend"
  npm install --no-fund --no-audit
  npm run build
else
  log "5–6. Skipping frontend builds (--skip-npm)"
fi

log "7. Permissions after build"
mkdir -p "$ROOT/backend/var"
if getent group www-data >/dev/null 2>&1; then
  run_sudo chown -R "$OWNER:www-data" "$ROOT/backend/var"
else
  run_sudo chown -R "$OWNER:$GROUP" "$ROOT/backend/var"
fi
run_sudo chmod -R ug+rwX "$ROOT/backend/var"
# Frontends must stay readable by the web server.
[[ -d "$ROOT/admin_frontend/dist" ]] && run_sudo chmod -R a+rX "$ROOT/admin_frontend/dist"
[[ -d "$ROOT/driver_frontend/dist" ]] && run_sudo chmod -R a+rX "$ROOT/driver_frontend/dist"

if [[ "$SKIP_MERCURE" -eq 0 ]]; then
  log "8. Mercure"
  if [[ -f "$ROOT/scripts/download-mercure.sh" ]]; then
    bash "$ROOT/scripts/download-mercure.sh" || echo "Warning: download-mercure.sh failed (continuing)" >&2
  fi
  if systemctl list-unit-files delivery-mercure.service >/dev/null 2>&1; then
    if systemctl is-active --quiet delivery-mercure.service; then
      run_sudo systemctl restart delivery-mercure.service
      echo "delivery-mercure.service restarted"
    else
      echo "delivery-mercure.service is installed but not active — skip restart"
    fi
  else
    echo "No delivery-mercure.service — skip restart"
  fi
else
  log "8. Skipping Mercure (--skip-mercure)"
fi

log "Done"
echo "Deploy finished at $(date -Is) as $OWNER ($BRANCH @ $(git -C "$ROOT" rev-parse --short HEAD))"
