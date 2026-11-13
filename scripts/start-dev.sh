#!/usr/bin/env bash
# Start local development processes (API, Mercure, Mailpit, Vite frontends).
#
# Preferred layout when you hand scripts to the client:
#   <client-folder>/
#     install-dev-ubuntu.sh
#     start-dev.sh          ← this file (keep it; do not replace with a wrapper)
#     delivery/             ← project folder cloned by the installer
#
# Also works from: project root, or project/scripts/.
# Before start: git pull --ff-only, cache:clear, doctrine migrations.
# If services are already running on the known ports, stop them and restart.
# Usage: ./start-dev.sh   OR   ./scripts/start-dev.sh
# Optional:
#   SKIP_GIT_PULL=1 / SKIP_MIGRATE=1 / SKIP_CACHE_CLEAR=1 — skip those steps
#   DEV_HOST=127.0.0.1 — bind/print on 127.0.0.1 instead of localhost
#   SKIP_CORS_CHECK=1 — skip post-start CORS preflight smoke test
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

resolve_project_root() {
  local base="$1" preferred="${DELIVERY_DIR_NAME:-delivery}" d

  # 1) Project folder next to this script (install-dev layout)
  if [[ -f "$base/$preferred/backend/composer.json" ]]; then
    (cd "$base/$preferred" && pwd)
    return 0
  fi
  for d in "$base"/*/ ; do
    [[ -d "$d" ]] || continue
    if [[ -f "${d}backend/composer.json" ]]; then
      (cd "$d" && pwd)
      return 0
    fi
  done

  # 2) Script lives in the project root
  if [[ -f "$base/backend/composer.json" ]]; then
    printf '%s\n' "$base"
    return 0
  fi

  # 3) Script lives in project/scripts/
  if [[ -f "$base/../backend/composer.json" ]]; then
    (cd "$base/.." && pwd)
    return 0
  fi

  return 1
}

ROOT="$(resolve_project_root "$SCRIPT_DIR")" || {
  echo "ERROR: cannot find project root (backend/composer.json)." >&2
  echo "Expected a project folder next to this script (e.g. ./delivery/)," >&2
  echo "or run from the project root / project/scripts/." >&2
  exit 1
}

echo "Project: $ROOT"

script_path() {
  local name="$1"
  if [[ -f "$ROOT/scripts/$name" ]]; then
    echo "$ROOT/scripts/$name"
  elif [[ -f "$SCRIPT_DIR/$name" ]]; then
    echo "$SCRIPT_DIR/$name"
  else
    echo "$ROOT/scripts/$name"
  fi
}

LOG_DIR="${ROOT}/.dev-logs"
PID_FILE="${LOG_DIR}/start-dev.pids"
mkdir -p "$LOG_DIR"

PIDS=()

# Ports / host used by local stack (browser + listeners must match)
DEV_HOST="${DEV_HOST:-localhost}"
API_PORT=8080
ADMIN_PORT=1420
DRIVER_PORT=1430
MERCURE_PORT=3000
MAILPIT_SMTP_PORT=1025
MAILPIT_UI_PORT=8025
MERCURE_HUB_URL="http://${DEV_HOST}:${MERCURE_PORT}/.well-known/mercure"
MAILPIT_SMTP_DSN="smtp://${DEV_HOST}:${MAILPIT_SMTP_PORT}"

pids_on_port() {
  local port="$1"
  local pids=""

  if command -v lsof >/dev/null 2>&1; then
    pids="$(lsof -tiTCP:"$port" -sTCP:LISTEN 2>/dev/null || true)"
  elif command -v fuser >/dev/null 2>&1; then
    pids="$(fuser "${port}/tcp" 2>/dev/null | tr -cs '0-9' ' ' || true)"
  elif command -v ss >/dev/null 2>&1; then
    pids="$(
      ss -ltnp "sport = :${port}" 2>/dev/null \
        | sed -n 's/.*pid=\([0-9][0-9]*\).*/\1/p' \
        | sort -u || true
    )"
  fi

  # shellcheck disable=SC2086
  printf '%s\n' $pids | awk 'NF && !seen[$0]++'
}

kill_pid_tree() {
  local pid="$1"
  local child

  if ! kill -0 "$pid" 2>/dev/null; then
    return 0
  fi

  while read -r child; do
    [[ -n "$child" ]] || continue
    kill_pid_tree "$child"
  done < <(ps -o pid= --ppid "$pid" 2>/dev/null || true)

  kill "$pid" 2>/dev/null || true
}

stop_port() {
  local port="$1"
  local name="$2"
  local pid
  local found=0

  while read -r pid; do
    [[ -n "$pid" ]] || continue
    found=1
    echo "  stopping $name (pid $pid, port $port)"
    kill_pid_tree "$pid"
  done < <(pids_on_port "$port")

  if [[ "$found" -eq 1 ]]; then
    # Give listeners time to release the port
    sleep 0.3
    while read -r pid; do
      [[ -n "$pid" ]] || continue
      kill -9 "$pid" 2>/dev/null || true
    done < <(pids_on_port "$port")
  fi
}

stop_previous_pidfile() {
  local pid
  if [[ ! -f "$PID_FILE" ]]; then
    return 0
  fi
  echo "Stopping previous start-dev processes..."
  while read -r pid; do
    [[ -n "$pid" ]] || continue
    [[ "$pid" =~ ^[0-9]+$ ]] || continue
    if kill -0 "$pid" 2>/dev/null; then
      echo "  stopping pid $pid"
      kill_pid_tree "$pid"
    fi
  done <"$PID_FILE"
  rm -f "$PID_FILE"
}

stop_existing_services() {
  echo "Checking for already running services..."
  stop_previous_pidfile
  stop_port "$API_PORT" "api"
  stop_port "$ADMIN_PORT" "admin"
  stop_port "$DRIVER_PORT" "driver"
  stop_port "$MERCURE_PORT" "mercure"
  stop_port "$MAILPIT_SMTP_PORT" "mailpit-smtp"
  stop_port "$MAILPIT_UI_PORT" "mailpit-ui"
  sleep 0.5
}

write_pidfile() {
  printf '%s\n' "${PIDS[@]}" >"$PID_FILE"
}

cleanup() {
  echo
  echo "Stopping local services..."
  for pid in "${PIDS[@]:-}"; do
    if kill -0 "$pid" 2>/dev/null; then
      kill_pid_tree "$pid"
      wait "$pid" 2>/dev/null || true
    fi
  done
  rm -f "$PID_FILE"
}
trap cleanup EXIT INT TERM

start_bg() {
  local name="$1"
  shift
  local log="$LOG_DIR/${name}.log"
  echo "→ $name (log: $log)"
  (
    cd "$ROOT"
    "$@" >"$log" 2>&1
  ) &
  PIDS+=("$!")
}

pull_latest_changes() {
  if [[ "${SKIP_GIT_PULL:-0}" == "1" ]]; then
    echo "Skip git pull (SKIP_GIT_PULL=1)"
    return 0
  fi
  if ! command -v git >/dev/null 2>&1; then
    echo "Skip git pull: git not found"
    return 0
  fi
  if ! git -C "$ROOT" rev-parse --git-dir >/dev/null 2>&1; then
    echo "Skip git pull: not a git repository"
    return 0
  fi

  echo "Pulling latest changes from git..."
  (
    cd "$ROOT"
    if [[ -n "$(git status --porcelain 2>/dev/null || true)" ]]; then
      echo "WARNING: working tree has local changes; pull may fail."
    fi
    # Fast-forward only — refuse to create merge commits on a dirty/divergent branch
    git pull --ff-only
  )
}

clear_symfony_cache() {
  if [[ "${SKIP_CACHE_CLEAR:-0}" == "1" ]]; then
    echo "Skip cache:clear (SKIP_CACHE_CLEAR=1)"
    return 0
  fi
  if ! command -v php >/dev/null 2>&1; then
    echo "WARNING: php not found; cannot clear Symfony cache." >&2
    return 0
  fi
  if [[ ! -f "$ROOT/backend/bin/console" ]]; then
    echo "WARNING: backend/bin/console not found; skip cache:clear." >&2
    return 0
  fi

  echo "Clearing Symfony cache (applies CORS / security config)..."
  (
    cd "$ROOT/backend"
    php bin/console cache:clear --no-warmup
    php bin/console cache:warmup
  )
}

run_migrations() {
  if [[ "${SKIP_MIGRATE:-0}" == "1" ]]; then
    echo "Skip migrations (SKIP_MIGRATE=1)"
    return 0
  fi
  if ! command -v php >/dev/null 2>&1; then
    echo "ERROR: php not found; cannot run migrations." >&2
    exit 1
  fi
  if [[ ! -f "$ROOT/backend/bin/console" ]]; then
    echo "ERROR: backend/bin/console not found; cannot run migrations." >&2
    exit 1
  fi

  echo "Running database migrations..."
  (
    cd "$ROOT/backend"
    php bin/console doctrine:migrations:migrate --no-interaction
  )
}

# Handoff model: a full start-dev.sh shipped beside the project must stay as-is.
# Never overwrite it with a thin wrapper (git pull must not replace the vendor script).
sync_sibling_launcher() {
  local repo_script="$ROOT/scripts/start-dev.sh"
  local parent sibling
  parent="$(cd "$ROOT/.." && pwd)"
  sibling="${parent}/start-dev.sh"

  if [[ -f "$sibling" ]] && grep -q 'resolve_project_root' "$sibling" 2>/dev/null; then
    chmod +x "$sibling" 2>/dev/null || true
    return 0
  fi

  # No bundled launcher — optional wrapper for clone-only installs
  [[ -f "$repo_script" ]] || return 0
  if [[ "$(cd "$SCRIPT_DIR" && pwd)" != "$parent" ]]; then
    return 0
  fi
  if [[ -f "$sibling" ]]; then
    return 0
  fi

  cat >"$sibling" <<EOF
#!/usr/bin/env bash
# Generated by start-dev.sh — runs the in-repo start-dev.sh.
set -euo pipefail
exec bash $(printf '%q' "$repo_script") "\$@"
EOF
  chmod +x "$sibling"
  echo "Created launcher: $sibling → $repo_script"
}

ensure_jwt_keys() {
  local private_pem="$ROOT/backend/config/jwt/private.pem"
  local public_pem="$ROOT/backend/config/jwt/public.pem"
  local gen_script="$ROOT/scripts/generate-jwt-keys.php"

  mkdir -p "$ROOT/backend/config/jwt"
  if [[ -f "$private_pem" && -f "$public_pem" ]]; then
    return 0
  fi
  if ! command -v php >/dev/null 2>&1 || [[ ! -f "$ROOT/backend/bin/console" ]]; then
    echo "WARNING: JWT keys missing and cannot generate them now." >&2
    return 0
  fi

  echo "Generating missing JWT keypair..."
  (
    cd "$ROOT/backend"
    if ! php bin/console lexik:jwt:generate-keypair --overwrite --no-interaction; then
      [[ -f "$gen_script" ]] && php "$gen_script" --overwrite
    fi
  ) || true
}

ensure_dependencies() {
  if [[ ! -f "$ROOT/backend/vendor/autoload.php" ]]; then
    if command -v composer >/dev/null 2>&1; then
      echo "Installing PHP dependencies (vendor missing)..."
      (cd "$ROOT/backend" && composer install --no-interaction)
    else
      echo "WARNING: backend/vendor missing and composer not found." >&2
    fi
  fi
  if [[ ! -d "$ROOT/admin_frontend/node_modules" ]] && command -v npm >/dev/null 2>&1; then
    echo "Installing admin frontend dependencies..."
    (cd "$ROOT/admin_frontend" && npm install --no-fund --no-audit)
  fi
  if [[ ! -d "$ROOT/driver_frontend/node_modules" ]] && command -v npm >/dev/null 2>&1; then
    echo "Installing driver frontend dependencies..."
    (cd "$ROOT/driver_frontend" && npm install --no-fund --no-audit)
  fi
}

# Heal stale installer env so Ubuntu copies keep working after localhost migration.
heal_local_env() {
  local env_local="$ROOT/backend/.env.local"
  local mercure_env="$ROOT/mercure/.env"
  local cors_re='^https?://(localhost|127\\.0\\.0\\.1|tauri\\.localhost)(:[0-9]+)?$'
  local cors_line="CORS_ALLOW_ORIGIN='${cors_re}'"

  if [[ -f "$env_local" ]]; then
    if grep -q '^CORS_ALLOW_ORIGIN=' "$env_local"; then
      # Ensure private-network-friendly local origins (localhost + 127.0.0.1 + tauri)
      if ! grep -q 'tauri\\.localhost' "$env_local"; then
        # Portable in-place edit (GNU sed on Ubuntu)
        sed -i "s|^CORS_ALLOW_ORIGIN=.*|${cors_line}|" "$env_local"
        echo "Updated CORS_ALLOW_ORIGIN in backend/.env.local"
      fi
    else
      printf '\n%s\n' "$cors_line" >>"$env_local"
      echo "Added CORS_ALLOW_ORIGIN to backend/.env.local"
    fi

    # Prefer localhost hub URLs when DEV_HOST is localhost (API process still overrides)
    if [[ "$DEV_HOST" == "localhost" ]]; then
      sed -i \
        -e 's|^MERCURE_URL=http://127\.0\.0\.1:3000|MERCURE_URL=http://localhost:3000|' \
        -e 's|^MERCURE_PUBLIC_URL=http://127\.0\.0\.1:3000|MERCURE_PUBLIC_URL=http://localhost:3000|' \
        -e 's|^MAILER_DSN=smtp://127\.0\.0\.1:1025|MAILER_DSN=smtp://localhost:1025|' \
        "$env_local"
    fi
  fi

  if [[ -f "$mercure_env" ]]; then
    # Ensure driver :1430 is allowed even on old mercure/.env from earlier installers
    if ! grep -q ':1430' "$mercure_env"; then
      sed -i "s|^MERCURE_EXTRA_DIRECTIVES=.*|MERCURE_EXTRA_DIRECTIVES=cors_origins http://localhost:${ADMIN_PORT} http://localhost:${DRIVER_PORT} http://127.0.0.1:${ADMIN_PORT} http://127.0.0.1:${DRIVER_PORT}|" "$mercure_env"
      echo "Updated Mercure cors_origins (added driver :${DRIVER_PORT})"
    fi
  fi
}

verify_cors_config() {
  local yaml="$ROOT/backend/config/packages/nelmio_cors.yaml"
  if [[ ! -f "$yaml" ]]; then
    return 0
  fi
  if ! grep -Eq 'allow_private_network:[[:space:]]*true' "$yaml"; then
    echo "WARNING: nelmio_cors.yaml lacks allow_private_network: true" >&2
    echo "  Authenticated API calls may fail CORS preflight on Ubuntu Chrome/Firefox." >&2
    echo "  Run: git -C \"$ROOT\" pull --ff-only && (cd \"$ROOT/backend\" && php bin/console cache:clear)" >&2
  fi
}

wait_for_http() {
  local url="$1" name="$2" attempts="${3:-40}"
  local i
  for ((i = 1; i <= attempts; i++)); do
    if curl -fsS -o /dev/null --connect-timeout 1 --max-time 2 "$url" 2>/dev/null; then
      return 0
    fi
    # PHP built-in may answer 404 on / — still means the listener is up
    if curl -sS -o /dev/null --connect-timeout 1 --max-time 2 -w '%{http_code}' "$url" 2>/dev/null | grep -qE '^[1-5][0-9][0-9]$'; then
      return 0
    fi
    sleep 0.25
  done
  echo "WARNING: $name did not become ready at $url" >&2
  return 1
}

cors_preflight_smoke_test() {
  if [[ "${SKIP_CORS_CHECK:-0}" == "1" ]]; then
    echo "Skip CORS smoke test (SKIP_CORS_CHECK=1)"
    return 0
  fi
  if ! command -v curl >/dev/null 2>&1; then
    echo "Skip CORS smoke test: curl not found"
    return 0
  fi

  local origin="http://${DEV_HOST}:${ADMIN_PORT}"
  local api="http://${DEV_HOST}:${API_PORT}/api/me"
  local tmp status body

  echo "CORS preflight smoke test → $api (Origin: $origin)"
  tmp="$(mktemp)"
  status="$(
    curl -sS -o "$tmp" -w '%{http_code}' -X OPTIONS "$api" \
      -H "Origin: ${origin}" \
      -H 'Access-Control-Request-Method: GET' \
      -H 'Access-Control-Request-Headers: authorization,x-delivery-authorization,content-type,accept' \
      -H 'Access-Control-Request-Private-Network: true' \
      --connect-timeout 2 --max-time 5 || true
  )"
  body="$(cat "$tmp" 2>/dev/null || true)"
  rm -f "$tmp"

  if [[ "$status" != "200" && "$status" != "204" ]]; then
    echo "ERROR: CORS preflight failed (HTTP ${status:-000})." >&2
    if [[ -n "$body" ]]; then
      echo "  Response: $body" >&2
    fi
    echo "  Typical cause: missing allow_private_network or stale Symfony cache." >&2
    echo "  Fix: cd \"$ROOT/backend\" && php bin/console cache:clear && re-run start-dev." >&2
    return 1
  fi

  echo "CORS preflight OK (HTTP $status)"
}

# Bundled launcher (next to install-dev-ubuntu.sh) is the source of truth when
# you hand scripts to the client — do not switch onto an older in-repo copy.
reexec_repo_script_if_needed() {
  local repo_script="$ROOT/scripts/start-dev.sh"
  local self abs_repo
  self="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/$(basename "${BASH_SOURCE[0]}")"
  [[ -f "$repo_script" ]] || return 0
  abs_repo="$(cd "$(dirname "$repo_script")" && pwd)/$(basename "$repo_script")"
  if [[ "$self" == "$abs_repo" || "${SKIP_REEXEC:-0}" == "1" ]]; then
    return 0
  fi
  # Full shipped script outside the repo → keep running it
  if grep -q 'resolve_project_root' "$self" 2>/dev/null; then
    return 0
  fi
  echo "Delegating to project script: $abs_repo"
  exec env SKIP_REEXEC=1 SKIP_GIT_PULL=1 bash "$abs_repo"
}

stop_existing_services

pull_latest_changes
reexec_repo_script_if_needed
sync_sibling_launcher
heal_local_env
verify_cors_config
ensure_dependencies
ensure_jwt_keys
clear_symfony_cache
run_migrations

# Mercure (override SERVER_NAME so an old mercure/.env with 127.0.0.1 still matches DEV_HOST)
if [[ ! -x "$ROOT/mercure/mercure" ]]; then
  bash "$(script_path download-mercure.sh)"
fi
if [[ ! -f "$ROOT/mercure/.env" ]]; then
  cp "$ROOT/mercure/.env.example" "$ROOT/mercure/.env"
fi
(
  set -a
  # shellcheck disable=SC1091
  source "$ROOT/mercure/.env"
  SERVER_NAME="http://${DEV_HOST}:${MERCURE_PORT}"
  MERCURE_EXTRA_DIRECTIVES="cors_origins http://${DEV_HOST}:${ADMIN_PORT} http://${DEV_HOST}:${DRIVER_PORT} http://localhost:${ADMIN_PORT} http://localhost:${DRIVER_PORT} http://127.0.0.1:${ADMIN_PORT} http://127.0.0.1:${DRIVER_PORT}"
  set +a
  cd "$ROOT/mercure"
  ./mercure run --config Caddyfile
) >"$LOG_DIR/mercure.log" 2>&1 &
PIDS+=("$!")
echo "→ mercure (log: $LOG_DIR/mercure.log)"

# Mailpit (optional if binary present or downloadable)
if [[ ! -x "$ROOT/mailpit/mailpit" ]]; then
  bash "$(script_path download-mailpit.sh)" || true
fi
if [[ -x "$ROOT/mailpit/mailpit" ]]; then
  start_bg mailpit "$ROOT/mailpit/mailpit" --smtp "${DEV_HOST}:${MAILPIT_SMTP_PORT}" --listen "${DEV_HOST}:${MAILPIT_UI_PORT}"
fi

# Symfony PHP built-in server (router keeps Authorization for Lexik JWT).
# Override Mercure/Mailpit URLs for this process so stale .env.local with 127.0.0.1 still works.
API_ENV=(
  "MERCURE_URL=${MERCURE_HUB_URL}"
  "MERCURE_PUBLIC_URL=${MERCURE_HUB_URL}"
)
if [[ -x "$ROOT/mailpit/mailpit" ]]; then
  API_ENV+=("MAILER_DSN=${MAILPIT_SMTP_DSN}")
fi
start_bg api env "${API_ENV[@]}" bash -c "cd \"$ROOT/backend\" && php -S ${DEV_HOST}:${API_PORT} -t public public/router.php"

# Frontends — force API/Mercure host so an older clone (127.0.0.1 defaults) still matches.
FRONTEND_ENV=(
  "VITE_API_URL=http://${DEV_HOST}:${API_PORT}"
  "VITE_MERCURE_URL=${MERCURE_HUB_URL}"
)
start_bg admin env "${FRONTEND_ENV[@]}" bash -c "cd \"$ROOT/admin_frontend\" && npm run dev -- --host ${DEV_HOST} --port ${ADMIN_PORT}"
start_bg driver env "${FRONTEND_ENV[@]}" bash -c "cd \"$ROOT/driver_frontend\" && npm run dev -- --host ${DEV_HOST} --port ${DRIVER_PORT}"

write_pidfile

echo
echo "Waiting for API..."
wait_for_http "http://${DEV_HOST}:${API_PORT}/" "API" 60 || true
cors_preflight_smoke_test || true

echo
echo "Local development is running (host: ${DEV_HOST}):"
echo "  Admin UI : http://${DEV_HOST}:${ADMIN_PORT}/"
echo "  Driver UI: http://${DEV_HOST}:${DRIVER_PORT}/"
echo "  API      : http://${DEV_HOST}:${API_PORT}/"
echo "  Mercure  : ${MERCURE_HUB_URL}"
if [[ -x "$ROOT/mailpit/mailpit" ]]; then
  echo "  Mailpit  : http://${DEV_HOST}:${MAILPIT_UI_PORT}/"
fi
echo
echo "Open ONLY these URLs in the browser (not hostname / LAN IP) — otherwise CORS will fail."
echo "Press Ctrl+C to stop all."
wait
