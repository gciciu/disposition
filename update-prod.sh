#!/usr/bin/env bash
# Pull latest code, run DB migrations, rebuild frontends (production).
# Usage (on the server):
#   cd /var/www/delivery && bash update-prod.sh
# Optional:
#   bash update-prod.sh --skip-npm     # backend only
#   bash update-prod.sh --skip-mercure # do not restart Mercure
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SKIP_NPM=0
SKIP_MERCURE=0

for arg in "$@"; do
  case "$arg" in
    --skip-npm) SKIP_NPM=1 ;;
    --skip-mercure) SKIP_MERCURE=1 ;;
    -h|--help)
      sed -n '2,8p' "$0"
      exit 0
      ;;
    *)
      echo "Unknown option: $arg" >&2
      exit 1
      ;;
  esac
done

log() { printf '\n=== %s ===\n' "$*"; }

cd "$ROOT"

if [[ ! -d .git ]]; then
  echo "Not a git repository: $ROOT" >&2
  exit 1
fi

if [[ ! -f backend/bin/console ]]; then
  echo "backend/bin/console not found — wrong directory?" >&2
  exit 1
fi

log "1. Git pull (ff-only)"
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

if [[ "$SKIP_MERCURE" -eq 0 ]]; then
  log "7. Mercure"
  if [[ -x "$ROOT/scripts/download-mercure.sh" ]]; then
    bash "$ROOT/scripts/download-mercure.sh" || echo "Warning: download-mercure.sh failed (continuing)" >&2
  fi
  if systemctl list-unit-files delivery-mercure.service >/dev/null 2>&1; then
    if systemctl is-active --quiet delivery-mercure.service; then
      sudo systemctl restart delivery-mercure.service
      echo "delivery-mercure.service restarted"
    else
      echo "delivery-mercure.service is installed but not active — skip restart"
    fi
  else
    echo "No delivery-mercure.service — skip restart"
  fi
else
  log "7. Skipping Mercure (--skip-mercure)"
fi

log "Done"
echo "Deploy finished at $(date -Is) ($BRANCH @ $(git -C "$ROOT" rev-parse --short HEAD))"
