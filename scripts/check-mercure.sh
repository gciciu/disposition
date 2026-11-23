#!/usr/bin/env bash
# Diagnose Mercure on a production host (binary, systemd, port, nginx, public URL).
# Usage:
#   bash scripts/check-mercure.sh
#   ADMIN_HOST=delivery.example.com bash scripts/check-mercure.sh
# Optional:
#   SERVICE=delivery-mercure.service
#   PUBLIC_URL=https://delivery.example.com/.well-known/mercure
set -u

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SERVICE="${SERVICE:-delivery-mercure.service}"
PASS=0
FAIL=0
WARN=0

ok()   { PASS=$((PASS + 1)); printf '  [OK]   %s\n' "$*"; }
bad()  { FAIL=$((FAIL + 1)); printf '  [FAIL] %s\n' "$*"; }
warn() { WARN=$((WARN + 1)); printf '  [WARN] %s\n' "$*"; }
info() { printf '  --     %s\n' "$*"; }
section() { printf '\n== %s ==\n' "$*"; }

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

http_code() {
  local url="$1"
  curl -sS -o /dev/null -w '%{http_code}' --connect-timeout 3 --max-time 8 "$url" 2>/dev/null || echo "000"
}

read_env_key() {
  local file="$1" key="$2" line value
  [[ -f "$file" ]] || return 1
  line="$(grep -E "^${key}=" "$file" | tail -n 1 || true)"
  [[ -n "$line" ]] || return 1
  value="${line#*=}"
  value="${value%$'\r'}"
  if [[ "$value" == \"*\" && "$value" == *\" ]]; then
    value="${value:1:${#value}-2}"
  elif [[ "$value" == \'*\' && "$value" == *\' ]]; then
    value="${value:1:${#value}-2}"
  fi
  printf '%s' "$value"
}

ROOT="$(resolve_project_root "$SCRIPT_DIR")" || {
  echo "ERROR: cannot find project root (backend/composer.json)." >&2
  exit 1
}

MERCURE_DIR="$ROOT/mercure"
BIN="$MERCURE_DIR/mercure"
HUB_ENV="$MERCURE_DIR/.env"
BACKEND_ENV="$ROOT/backend/.env.local"
UNIT_PATH="/etc/systemd/system/${SERVICE}"

echo "Mercure diagnostics"
echo "Project: $ROOT"
echo "Service: $SERVICE"

# --- binary ---
section "1. Binary"
if [[ -f "$BIN" ]]; then
  ok "found $BIN"
else
  bad "missing $BIN"
  info "fix: cd \"$ROOT\" && bash scripts/download-mercure.sh"
fi

if [[ -f "$BIN" ]]; then
  if [[ -x "$BIN" ]]; then
    ok "executable (+x)"
  else
    bad "not executable"
    info "fix: chmod +x \"$BIN\""
  fi

  if command -v file >/dev/null 2>&1; then
    FT="$(file -b "$BIN" 2>/dev/null || true)"
    info "file: $FT"
    if echo "$FT" | grep -qi 'PE32\|MS Windows\|DOS executable'; then
      bad "Windows binary on Linux (203/EXEC)"
      info "fix: bash scripts/download-mercure.sh"
    elif echo "$FT" | grep -qi 'ELF'; then
      ok "Linux ELF binary"
    else
      warn "unexpected binary type"
    fi
  fi

  ARCH="$(uname -m)"
  info "host arch: $ARCH"
fi

# --- hub env ---
section "2. Hub env (mercure/.env)"
if [[ -f "$HUB_ENV" ]]; then
  ok "found $HUB_ENV"
  PUB_KEY="$(read_env_key "$HUB_ENV" MERCURE_PUBLISHER_JWT_KEY || true)"
  SUB_KEY="$(read_env_key "$HUB_ENV" MERCURE_SUBSCRIBER_JWT_KEY || true)"
  SERVER_NAME="$(read_env_key "$HUB_ENV" SERVER_NAME || true)"
  if [[ -n "${PUB_KEY:-}" && -n "${SUB_KEY:-}" ]]; then
    ok "JWT keys present"
  else
    bad "MERCURE_PUBLISHER_JWT_KEY / MERCURE_SUBSCRIBER_JWT_KEY missing"
  fi
  if [[ -n "${SERVER_NAME:-}" ]]; then
    info "SERVER_NAME=$SERVER_NAME"
  else
    warn "SERVER_NAME empty (default localhost:3000)"
  fi
else
  bad "missing $HUB_ENV"
fi

if [[ -f "$BACKEND_ENV" ]]; then
  ok "found backend/.env.local"
  BACK_SECRET="$(read_env_key "$BACKEND_ENV" MERCURE_JWT_SECRET || true)"
  PUBLIC_FROM_ENV="$(read_env_key "$BACKEND_ENV" MERCURE_PUBLIC_URL || true)"
  if [[ -n "${BACK_SECRET:-}" && -n "${PUB_KEY:-}" ]]; then
    if [[ "$BACK_SECRET" == "$PUB_KEY" && "$BACK_SECRET" == "$SUB_KEY" ]]; then
      ok "JWT secret matches backend ↔ hub"
    else
      bad "JWT secret mismatch backend/.env.local ↔ mercure/.env"
      info "keys must be identical; hub file must have NO quotes around the secret"
    fi
  elif [[ -z "${BACK_SECRET:-}" ]]; then
    warn "MERCURE_JWT_SECRET missing in backend/.env.local"
  fi
  if [[ -n "${PUBLIC_FROM_ENV:-}" ]]; then
    info "MERCURE_PUBLIC_URL=$PUBLIC_FROM_ENV"
  fi
else
  warn "backend/.env.local not found"
  PUBLIC_FROM_ENV=""
fi

# --- systemd ---
section "3. systemd unit"
if [[ -f "$UNIT_PATH" ]]; then
  ok "unit file $UNIT_PATH"
  UNIT_USER="$(grep -E '^User=' "$UNIT_PATH" | head -n1 | cut -d= -f2- || true)"
  UNIT_WD="$(grep -E '^WorkingDirectory=' "$UNIT_PATH" | head -n1 | cut -d= -f2- || true)"
  UNIT_ENV="$(grep -E '^EnvironmentFile=' "$UNIT_PATH" | head -n1 | cut -d= -f2- || true)"
  UNIT_EXEC="$(grep -E '^ExecStart=' "$UNIT_PATH" | head -n1 | cut -d= -f2- || true)"
  info "User=$UNIT_USER"
  info "WorkingDirectory=$UNIT_WD"
  info "EnvironmentFile=$UNIT_ENV"
  info "ExecStart=$UNIT_EXEC"

  if [[ "$UNIT_USER" == "APP_USER" || -z "$UNIT_USER" ]]; then
    bad "User= still placeholder APP_USER or empty"
  elif id "$UNIT_USER" >/dev/null 2>&1; then
    ok "system user exists: $UNIT_USER"
  else
    bad "system user does not exist: $UNIT_USER"
  fi

  if [[ "$UNIT_WD" == "$MERCURE_DIR" ]]; then
    ok "WorkingDirectory matches project mercure/"
  else
    bad "WorkingDirectory mismatch"
    info "expected: $MERCURE_DIR"
    info "unit has: ${UNIT_WD:-<empty>}"
  fi

  if [[ "$UNIT_ENV" == "$HUB_ENV" ]]; then
    ok "EnvironmentFile matches mercure/.env"
  else
    bad "EnvironmentFile mismatch"
    info "expected: $HUB_ENV"
  fi

  if [[ "$UNIT_EXEC" == "$BIN run --config Caddyfile" ]]; then
    ok "ExecStart matches binary path"
  else
    warn "ExecStart may be wrong"
    info "expected: $BIN run --config Caddyfile"
  fi
else
  bad "unit missing: $UNIT_PATH"
  info "create it from INSTALL-MERCURE.md (use real paths for this host)"
fi

if command -v systemctl >/dev/null 2>&1; then
  STATE="$(systemctl is-active "$SERVICE" 2>/dev/null || true)"
  if [[ "$STATE" == "active" ]]; then
    ok "service active"
  else
    bad "service not active (state=${STATE:-unknown})"
    info "recent journal:"
    journalctl -u "$SERVICE" -n 15 --no-pager 2>/dev/null | sed 's/^/         /' || true
    if journalctl -u "$SERVICE" -n 50 --no-pager 2>/dev/null | grep -q 'status=203/EXEC'; then
      bad "detected 203/EXEC — binary missing, not +x, or wrong OS/arch"
      info "fix: cd \"$ROOT\" && bash scripts/download-mercure.sh && systemctl restart $SERVICE"
    fi
    if journalctl -u "$SERVICE" -n 50 --no-pager 2>/dev/null | grep -qE 'status=217/USER|Invalid user|Failed to determine user'; then
      bad "detected bad User= in unit"
    fi
  fi
else
  warn "systemctl not available"
fi

# --- port ---
section "4. Port 3000"
if ss -tlnp 2>/dev/null | grep -qE '[:.]3000\b'; then
  ok "something listens on :3000"
  ss -tlnp 2>/dev/null | grep -E '[:.]3000\b' | sed 's/^/         /' || true
else
  bad "nothing listens on :3000"
fi

# --- local HTTP ---
section "5. Local hub HTTP"
LOCAL_URL="http://127.0.0.1:3000/.well-known/mercure?topic=admin.routes"
LOCAL_CODE="$(http_code "$LOCAL_URL")"
info "GET $LOCAL_URL -> HTTP $LOCAL_CODE"
case "$LOCAL_CODE" in
  400|401) ok "hub responds locally ($LOCAL_CODE = expected without JWT)" ;;
  000) bad "cannot connect to local hub" ;;
  404) bad "local hub returned 404 — wrong binary/config or path" ;;
  *) warn "unexpected local code $LOCAL_CODE" ;;
esac

# --- nginx ---
section "6. nginx Mercure location"
NGINX_HITS="$(grep -Rsl --include='*.conf' 'well-known/mercure' /etc/nginx 2>/dev/null || true)"
if [[ -n "$NGINX_HITS" ]]; then
  ok "found /.well-known/mercure in nginx configs:"
  echo "$NGINX_HITS" | sed 's/^/         /'
else
  bad "no /.well-known/mercure in /etc/nginx — public URL will 404"
  info "add location from INSTALL-MERCURE.md on the admin site, then: nginx -t && systemctl reload nginx"
fi

# --- public URL ---
section "7. Public URL"
PUBLIC_URL="${PUBLIC_URL:-}"
if [[ -z "$PUBLIC_URL" && -n "${PUBLIC_FROM_ENV:-}" ]]; then
  PUBLIC_URL="$PUBLIC_FROM_ENV"
fi
if [[ -z "$PUBLIC_URL" && -n "${ADMIN_HOST:-}" ]]; then
  PUBLIC_URL="https://${ADMIN_HOST}/.well-known/mercure"
fi

if [[ -z "$PUBLIC_URL" ]]; then
  warn "set ADMIN_HOST=... or PUBLIC_URL=... to test the public endpoint"
else
  # ensure topic query for a realistic probe
  if [[ "$PUBLIC_URL" != *\?* ]]; then
    PROBE_URL="${PUBLIC_URL}?topic=admin.routes"
  else
    PROBE_URL="$PUBLIC_URL"
  fi
  PUBLIC_CODE="$(http_code "$PROBE_URL")"
  info "GET $PROBE_URL -> HTTP $PUBLIC_CODE"
  case "$PUBLIC_CODE" in
    400|401) ok "public proxy OK ($PUBLIC_CODE)" ;;
    404)
      bad "public 404 — nginx not proxying to Mercure (or wrong site/vhost)"
      if [[ "$LOCAL_CODE" == "400" || "$LOCAL_CODE" == "401" ]]; then
        info "local hub works → fix nginx location / reload nginx on admin host"
      else
        info "local hub also broken → fix service first"
      fi
      ;;
    502|503)
      bad "public $PUBLIC_CODE — nginx proxy OK-ish but upstream down"
      info "fix service on :3000 first"
      ;;
    000) bad "cannot reach public URL (DNS/SSL/firewall)" ;;
    *) warn "unexpected public code $PUBLIC_CODE" ;;
  esac

  # CORS: driver SPA often connects cross-origin to admin hub
  DRIVER_ORIGIN="${DRIVER_ORIGIN:-}"
  if [[ -z "$DRIVER_ORIGIN" && -n "${DRIVER_HOST:-}" ]]; then
    DRIVER_ORIGIN="https://${DRIVER_HOST}"
  fi
  if [[ -z "$DRIVER_ORIGIN" && -n "${ADMIN_HOST:-}" ]]; then
    # guess sibling driver host
    DRIVER_ORIGIN="https://${ADMIN_HOST/delivery./driver.}"
  fi
  if [[ -n "$DRIVER_ORIGIN" && "$PUBLIC_CODE" != "000" ]]; then
    CORS_HDR="$(curl -si -H "Origin: ${DRIVER_ORIGIN}" --connect-timeout 3 --max-time 8 "$PROBE_URL" 2>/dev/null \
      | tr -d '\r' | grep -i '^access-control-allow-origin:' | head -n1 || true)"
    if [[ -n "$CORS_HDR" ]]; then
      ok "CORS header present for Origin ${DRIVER_ORIGIN}: ${CORS_HDR}"
    else
      bad "no Access-Control-Allow-Origin for Origin ${DRIVER_ORIGIN}"
      info "ensure mercure/Caddyfile has 'cors_origins *', then: systemctl restart $SERVICE"
      info "also add /.well-known/mercure on the driver vhost (same-origin, no CORS)"
    fi
  fi
fi

# --- summary ---
section "Summary"
echo "  OK=$PASS  FAIL=$FAIL  WARN=$WARN"
if [[ "$FAIL" -eq 0 ]]; then
  echo "  Verdict: Mercure looks healthy."
  exit 0
fi

echo "  Verdict: problems found. Typical fixes:"
echo "    1) cd \"$ROOT\" && bash scripts/download-mercure.sh"
echo "    2) fix systemd User=/paths to this ROOT, then:"
echo "       systemctl daemon-reload && systemctl restart $SERVICE"
echo "    3) ensure nginx location /.well-known/mercure → 127.0.0.1:3000"
echo "       nginx -t && systemctl reload nginx"
echo "    4) re-run: bash scripts/check-mercure.sh"
exit 1
