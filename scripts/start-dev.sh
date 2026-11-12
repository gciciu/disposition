#!/usr/bin/env bash
# Start local development processes (API, Mercure, Mailpit, Vite frontends).
# Works from: scripts/ inside the repo, project root, or the client folder
# that contains a cloned project subfolder (e.g. ./delivery/).
# If services are already running on the known ports, stop them and restart.
# Usage: ./scripts/start-dev.sh   OR   ./start-dev.sh
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

resolve_project_root() {
  local base="$1" preferred="${DELIVERY_DIR_NAME:-delivery}" d
  if [[ -f "$base/backend/composer.json" ]]; then
    printf '%s\n' "$base"
    return 0
  fi
  if [[ -f "$base/../backend/composer.json" ]]; then
    (cd "$base/.." && pwd)
    return 0
  fi
  if [[ -f "$base/$preferred/backend/composer.json" ]]; then
    printf '%s\n' "$base/$preferred"
    return 0
  fi
  for d in "$base"/*/ ; do
    [[ -d "$d" ]] || continue
    if [[ -f "${d}backend/composer.json" ]]; then
      (cd "$d" && pwd)
      return 0
    fi
  done
  return 1
}

ROOT="$(resolve_project_root "$SCRIPT_DIR")" || {
  echo "ERROR: cannot find project root (backend/composer.json)." >&2
  echo "Run from the project folder, scripts/, or the client folder that contains it." >&2
  exit 1
}

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

# Ports used by local stack
API_PORT=8080
ADMIN_PORT=1420
DRIVER_PORT=1430
MERCURE_PORT=3000
MAILPIT_SMTP_PORT=1025
MAILPIT_UI_PORT=8025

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

stop_existing_services

# Mercure
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
  start_bg mailpit "$ROOT/mailpit/mailpit" --smtp "127.0.0.1:${MAILPIT_SMTP_PORT}" --listen "127.0.0.1:${MAILPIT_UI_PORT}"
fi

# Symfony PHP built-in server
start_bg api bash -c "cd \"$ROOT/backend\" && php -S 127.0.0.1:${API_PORT} -t public"

# Frontends
start_bg admin bash -c "cd \"$ROOT/admin_frontend\" && npm run dev -- --host 127.0.0.1 --port ${ADMIN_PORT}"
start_bg driver bash -c "cd \"$ROOT/driver_frontend\" && npm run dev -- --host 127.0.0.1 --port ${DRIVER_PORT}"

write_pidfile

echo
echo "Local development is running:"
echo "  Admin UI : http://127.0.0.1:${ADMIN_PORT}/"
echo "  Driver UI: http://127.0.0.1:${DRIVER_PORT}/"
echo "  API      : http://127.0.0.1:${API_PORT}/"
echo "  Mercure  : http://127.0.0.1:${MERCURE_PORT}/.well-known/mercure"
if [[ -x "$ROOT/mailpit/mailpit" ]]; then
  echo "  Mailpit  : http://127.0.0.1:${MAILPIT_UI_PORT}/"
fi
echo
echo "Press Ctrl+C to stop all."
wait
