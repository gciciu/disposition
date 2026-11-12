#!/usr/bin/env bash
# Bootstrap local development on Ubuntu:
# put this script (and optional helpers) into any client folder, cd into it, run.
# Creates a new subfolder inside it, clones the repo there, installs deps,
# asks for settings (e.g. GOOGLE_MAPS_API_KEY), configures the project.
#
# Usage (client folder name can be anything):
#   mkdir ~/client-setup && cd ~/client-setup
#   # put scripts here, then:
#   chmod +x install-dev-ubuntu.sh
#   ./install-dev-ubuntu.sh
#   # → creates ./delivery (or --dir NAME) and clones the project into it
#
# Or from an existing checkout:
#   ./scripts/install-dev-ubuntu.sh --inplace
set -euo pipefail

DEFAULT_REPO_URL="https://github.com/gciciu/disposition.git"
DEFAULT_BRANCH="main"
DEFAULT_DIR_NAME="delivery"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
INPLACE=0
NONINTERACTIVE=0
REPO_URL="${DELIVERY_REPO_URL:-$DEFAULT_REPO_URL}"
BRANCH="${DELIVERY_BRANCH:-$DEFAULT_BRANCH}"
DIR_NAME="${DELIVERY_DIR_NAME:-$DEFAULT_DIR_NAME}"
TARGET_DIR=""

GOOGLE_MAPS_API_KEY="${GOOGLE_MAPS_API_KEY:-}"
DB_NAME="${DELIVERY_DB_NAME:-delivery}"
DB_USER="${DELIVERY_DB_USER:-delivery}"
DB_PASS="${DELIVERY_DB_PASS:-delivery}"
USE_MAILPIT=1
CREATE_SUPER_ADMIN=1
SUPER_ADMIN_EMAIL=""
SUPER_ADMIN_PASSWORD=""
SUPER_ADMIN_NAME="Admin"
START_AFTER=1

usage() {
  cat <<'EOF'
Usage: install-dev-ubuntu.sh [options]

  Put the script in any client folder, cd into it, and run. The script
  creates a new subfolder there and clones the project into it.

  --inplace              Configure the repo that contains this script (no clone)
  --dir NAME             Project subfolder name (default: delivery)
  --repo URL             Git clone URL
  --branch NAME          Git branch (default: main)
  --non-interactive      Use defaults / env vars; minimal prompts
  -h, --help             Show this help

Env overrides: DELIVERY_REPO_URL, DELIVERY_BRANCH, DELIVERY_DIR_NAME,
  GOOGLE_MAPS_API_KEY, DELIVERY_DB_NAME, DELIVERY_DB_USER, DELIVERY_DB_PASS
EOF
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --inplace) INPLACE=1; shift ;;
    --dir) DIR_NAME="$2"; shift 2 ;;
    --repo) REPO_URL="$2"; shift 2 ;;
    --branch) BRANCH="$2"; shift 2 ;;
    --non-interactive) NONINTERACTIVE=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "Unknown option: $1" >&2; usage >&2; exit 1 ;;
  esac
done

log()  { printf '\n==> %s\n' "$*"; }
info() { printf '    %s\n' "$*"; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

need_cmd() {
  command -v "$1" >/dev/null 2>&1
}

ask() {
  # ask VAR "Prompt" "default"
  local var="$1" prompt="$2" default="${3:-}"
  local value=""
  if [[ "$NONINTERACTIVE" -eq 1 ]]; then
    printf -v "$var" '%s' "${!var:-$default}"
    return
  fi
  if [[ -n "$default" ]]; then
    read -r -p "$prompt [$default]: " value || true
    value="${value:-$default}"
  else
    read -r -p "$prompt: " value || true
  fi
  printf -v "$var" '%s' "$value"
}

ask_secret() {
  local var="$1" prompt="$2" default="${3:-}"
  local value=""
  if [[ "$NONINTERACTIVE" -eq 1 ]]; then
    printf -v "$var" '%s' "${!var:-$default}"
    return
  fi
  if [[ -n "$default" ]]; then
    read -r -s -p "$prompt [$default]: " value || true
    echo
    value="${value:-$default}"
  else
    read -r -s -p "$prompt: " value || true
    echo
  fi
  printf -v "$var" '%s' "$value"
}

ask_secret_confirm() {
  # ask_secret_confirm VAR "Prompt" "default"
  local var="$1" prompt="$2" default="${3:-}"
  local first="" second=""
  if [[ "$NONINTERACTIVE" -eq 1 ]]; then
    ask_secret "$var" "$prompt" "$default"
    return
  fi
  while true; do
    if [[ -n "$default" ]]; then
      read -r -s -p "$prompt [$default]: " first || true
      echo
      first="${first:-$default}"
    else
      read -r -s -p "$prompt: " first || true
      echo
    fi
    read -r -s -p "Repeat password: " second || true
    echo
    if [[ "$first" == "$second" ]]; then
      printf -v "$var" '%s' "$first"
      return
    fi
    # Allow confirming the default with a second empty Enter.
    if [[ -n "$default" && -z "$second" && "$first" == "$default" ]]; then
      printf -v "$var" '%s' "$first"
      return
    fi
    echo "Passwords do not match, try again."
  done
}

confirm() {
  # confirm VAR "Prompt" default_y_or_n
  local var="$1" prompt="$2" default="${3:-y}"
  local value="" reply
  if [[ "$NONINTERACTIVE" -eq 1 ]]; then
    if [[ -n "${!var:-}" ]]; then
      return
    fi
    if [[ "$default" == "y" ]]; then
      printf -v "$var" '%s' "1"
    else
      printf -v "$var" '%s' "0"
    fi
    return
  fi
  if [[ "$default" == "y" ]]; then
    read -r -p "$prompt [Y/n]: " reply || true
    reply="${reply:-y}"
  else
    read -r -p "$prompt [y/N]: " reply || true
    reply="${reply:-n}"
  fi
  case "$reply" in
    y|Y|yes|YES) printf -v "$var" '%s' "1" ;;
    *)           printf -v "$var" '%s' "0" ;;
  esac
}

sudo_run() {
  if [[ "$(id -u)" -eq 0 ]]; then
    "$@"
  else
    sudo "$@"
  fi
}

rand_hex() {
  local bytes="${1:-32}"
  if need_cmd openssl; then
    openssl rand -hex "$bytes"
  else
    head -c "$bytes" /dev/urandom | xxd -p -c 256 | tr -d '\n'
  fi
}

urlencode() {
  # Minimal URL-encode for DATABASE_URL userinfo (php rawurlencode)
  php -r 'echo rawurlencode($argv[1]);' "$1"
}

# --- resolve target directory ---
# Client puts scripts in any folder; project goes into a NEW subfolder inside it.
if [[ "$INPLACE" -eq 1 ]]; then
  if [[ -f "$SCRIPT_DIR/../backend/composer.json" ]]; then
    TARGET_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
  elif [[ -f "$SCRIPT_DIR/backend/composer.json" ]]; then
    TARGET_DIR="$SCRIPT_DIR"
  else
    die "--inplace requires running from this repository (scripts/ or root)"
  fi
else
  # Smart default: if this script already lives inside a checkout, offer inplace
  if [[ -f "$SCRIPT_DIR/../backend/composer.json" && "$NONINTERACTIVE" -eq 0 ]]; then
    confirm USE_EXISTING "Script is inside an existing checkout. Configure this copy (no clone)?" "y"
    if [[ "$USE_EXISTING" -eq 1 ]]; then
      INPLACE=1
      TARGET_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
    fi
  elif [[ -f "$SCRIPT_DIR/backend/composer.json" && "$NONINTERACTIVE" -eq 0 ]]; then
    confirm USE_EXISTING "Project files already in this folder. Configure here (no clone)?" "y"
    if [[ "$USE_EXISTING" -eq 1 ]]; then
      INPLACE=1
      TARGET_DIR="$SCRIPT_DIR"
    fi
  fi
  if [[ "$INPLACE" -eq 0 ]]; then
    ask DIR_NAME "Project folder name (created inside this folder)" "$DIR_NAME"
    TARGET_DIR="${SCRIPT_DIR}/${DIR_NAME}"
  fi
fi

log "Delivery local-dev installer"
info "Target: $TARGET_DIR"

# --- dialogs ---
log "Settings"
if [[ "$INPLACE" -eq 0 ]]; then
  ask REPO_URL "Git repository URL" "$REPO_URL"
  ask BRANCH "Git branch" "$BRANCH"
fi

ask DB_NAME "MySQL database name" "$DB_NAME"
ask DB_USER "MySQL user" "$DB_USER"
ask_secret DB_PASS "MySQL password" "$DB_PASS"
ask GOOGLE_MAPS_API_KEY "GOOGLE_MAPS_API_KEY (Enter = skip for now)" "$GOOGLE_MAPS_API_KEY"
confirm USE_MAILPIT "Use Mailpit for local email (SMTP 1025 / UI 8025)?" "y"

confirm CREATE_SUPER_ADMIN "Create a super-admin user now?" "y"
if [[ "$CREATE_SUPER_ADMIN" -eq 1 ]]; then
  ask SUPER_ADMIN_EMAIL "Super-admin email" "${SUPER_ADMIN_EMAIL:-admin@delivery.local}"
  SUPER_ADMIN_EMAIL="$(printf '%s' "$SUPER_ADMIN_EMAIL" | tr '[:upper:]' '[:lower:]')"
  SUPER_ADMIN_EMAIL="${SUPER_ADMIN_EMAIL#"${SUPER_ADMIN_EMAIL%%[![:space:]]*}"}"
  SUPER_ADMIN_EMAIL="${SUPER_ADMIN_EMAIL%"${SUPER_ADMIN_EMAIL##*[![:space:]]}"}"
  ask_secret_confirm SUPER_ADMIN_PASSWORD "Super-admin password" "${SUPER_ADMIN_PASSWORD:-Admin123!}"
  ask SUPER_ADMIN_NAME "Super-admin display name" "$SUPER_ADMIN_NAME"
fi

confirm START_AFTER "Start local services when setup finishes?" "y"

# --- system packages ---
log "Checking / installing system packages"
sudo_run apt-get update -y

ensure_apt() {
  local pkg
  for pkg in "$@"; do
    if ! dpkg -s "$pkg" >/dev/null 2>&1; then
      info "Installing $pkg"
      # Use env: VAR=value before a command is not valid inside "$@" (fails as root).
      sudo_run env DEBIAN_FRONTEND=noninteractive apt-get install -y "$pkg"
    else
      info "OK $pkg"
    fi
  done
}

ensure_apt ca-certificates curl git unzip zip openssl software-properties-common

# PHP 8.4 via ondrej if needed
php_ok=0
if need_cmd php; then
  php_ver="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  info "Found PHP $php_ver"
  if php -r 'exit(version_compare(PHP_VERSION, "8.2.0", ">=") ? 0 : 1);'; then
    php_ok=1
  fi
fi

if [[ "$php_ok" -eq 0 ]]; then
  info "Installing PHP 8.4 (ondrej/php)"
  if ! apt-cache policy php8.4-cli 2>/dev/null | grep -q Candidate; then
    sudo_run add-apt-repository -y ppa:ondrej/php
    sudo_run apt-get update -y
  fi
  ensure_apt \
    php8.4-cli php8.4-mysql php8.4-xml php8.4-mbstring php8.4-curl \
    php8.4-zip php8.4-intl php8.4-sqlite3 php8.4-sodium
  if [[ -x /usr/bin/php8.4 ]]; then
    sudo_run update-alternatives --set php /usr/bin/php8.4 2>/dev/null || true
  fi
else
  ensure_apt \
    php-cli php-mysql php-xml php-mbstring php-curl php-zip php-intl \
    php-sqlite3 php-sodium || true
  # Named packages if meta packages missed extensions
  php_mm="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
  ensure_apt \
    "php${php_mm}-mysql" "php${php_mm}-xml" "php${php_mm}-mbstring" \
    "php${php_mm}-curl" "php${php_mm}-zip" "php${php_mm}-intl" \
    "php${php_mm}-sodium" || true
fi

# Composer
if ! need_cmd composer; then
  info "Installing Composer"
  tmp="$(mktemp -d)"
  curl -fsSL https://getcomposer.org/installer -o "$tmp/composer-setup.php"
  sudo_run php "$tmp/composer-setup.php" --install-dir=/usr/local/bin --filename=composer
  rm -rf "$tmp"
else
  info "OK composer $(composer --version 2>/dev/null | head -n1)"
fi

# Node.js LTS (20+)
node_ok=0
if need_cmd node; then
  node_major="$(node -v | sed 's/^v//; s/\..*$//')"
  info "Found Node $(node -v)"
  if [[ "$node_major" -ge 20 ]]; then
    node_ok=1
  fi
fi
if [[ "$node_ok" -eq 0 ]]; then
  info "Installing Node.js 22.x"
  curl -fsSL https://deb.nodesource.com/setup_22.x | sudo_run bash -
  ensure_apt nodejs
fi

# MySQL
ensure_apt mysql-server mysql-client
if ! systemctl is-active --quiet mysql 2>/dev/null && ! systemctl is-active --quiet mysqld 2>/dev/null; then
  sudo_run systemctl enable --now mysql 2>/dev/null || sudo_run service mysql start || true
fi

# --- clone ---
# Create a new subfolder inside the client folder and clone the project into it.
if [[ "$INPLACE" -eq 0 ]]; then
  log "Cloning repository"
  if [[ -d "$TARGET_DIR/.git" ]]; then
    info "Existing git checkout at $TARGET_DIR — pulling $BRANCH"
    git -C "$TARGET_DIR" fetch --all --prune
    git -C "$TARGET_DIR" checkout "$BRANCH"
    git -C "$TARGET_DIR" pull --ff-only origin "$BRANCH" || git -C "$TARGET_DIR" pull --ff-only
  elif [[ -e "$TARGET_DIR" ]]; then
    die "Path exists and is not a git repo: $TARGET_DIR (remove it, pick another --dir, or use --inplace)"
  else
    git clone --branch "$BRANCH" --single-branch "$REPO_URL" "$TARGET_DIR"
    info "Cloned into $TARGET_DIR"
  fi
fi

[[ -f "$TARGET_DIR/backend/composer.json" ]] || die "backend/composer.json missing in $TARGET_DIR"

chmod +x "$TARGET_DIR/scripts/"*.sh 2>/dev/null || true

# --- MySQL database ---
log "Configuring MySQL database"
mysql_root() {
  if sudo_run mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
    sudo_run mysql -u root "$@"
    return
  fi
  if mysql -u root -e "SELECT 1" >/dev/null 2>&1; then
    mysql -u root "$@"
    return
  fi
  die "Cannot connect to MySQL as root (sudo mysql). Create DB manually and re-run with --inplace."
}

sql_escape() {
  printf '%s' "$1" | sed "s/'/''/g"
}
DB_NAME_SQL="$(sql_escape "$DB_NAME")"
DB_USER_SQL="$(sql_escape "$DB_USER")"
DB_PASS_SQL="$(sql_escape "$DB_PASS")"

mysql_root <<SQL
CREATE DATABASE IF NOT EXISTS \`${DB_NAME_SQL}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '${DB_USER_SQL}'@'localhost' IDENTIFIED BY '${DB_PASS_SQL}';
CREATE USER IF NOT EXISTS '${DB_USER_SQL}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS_SQL}';
ALTER USER '${DB_USER_SQL}'@'localhost' IDENTIFIED BY '${DB_PASS_SQL}';
ALTER USER '${DB_USER_SQL}'@'127.0.0.1' IDENTIFIED BY '${DB_PASS_SQL}';
GRANT ALL PRIVILEGES ON \`${DB_NAME_SQL}\`.* TO '${DB_USER_SQL}'@'localhost';
GRANT ALL PRIVILEGES ON \`${DB_NAME_SQL}\`.* TO '${DB_USER_SQL}'@'127.0.0.1';
FLUSH PRIVILEGES;
SQL
info "Database ${DB_NAME} ready (user ${DB_USER})"

# Detect server version for DATABASE_URL
SERVER_VERSION="$(mysql -u "$DB_USER" -p"$DB_PASS" -N -e "SELECT VERSION();" 2>/dev/null || echo "8.4.9")"
if [[ "$SERVER_VERSION" == *"MariaDB"* ]]; then
  DB_SERVER_VERSION="$(echo "$SERVER_VERSION" | sed -E 's/^([0-9]+\.[0-9]+\.[0-9]+).*/\1/')-MariaDB"
else
  DB_SERVER_VERSION="$(echo "$SERVER_VERSION" | sed -E 's/^([0-9]+\.[0-9]+\.[0-9]+).*/\1/')"
fi
DB_USER_ENC="$(urlencode "$DB_USER")"
DB_PASS_ENC="$(urlencode "$DB_PASS")"
DATABASE_URL="mysql://${DB_USER_ENC}:${DB_PASS_ENC}@127.0.0.1:3306/${DB_NAME}?serverVersion=${DB_SERVER_VERSION}&charset=utf8mb4"

# --- env files ---
log "Writing environment files"
APP_SECRET="$(rand_hex 16)"
JWT_PASSPHRASE="$(rand_hex 32)"
MERCURE_SECRET="$(rand_hex 32)"

if [[ "$USE_MAILPIT" -eq 1 ]]; then
  MAILER_DSN="smtp://127.0.0.1:1025"
else
  MAILER_DSN="null://null"
fi

ENV_LOCAL="$TARGET_DIR/backend/.env.local"
cat >"$ENV_LOCAL" <<EOF
APP_ENV=dev
APP_SECRET=${APP_SECRET}

DATABASE_URL="${DATABASE_URL}"

JWT_PASSPHRASE=${JWT_PASSPHRASE}
CORS_ALLOW_ORIGIN='^https?://(localhost|127\\.0\\.0\\.1|tauri\\.localhost)(:[0-9]+)?$'

GOOGLE_MAPS_API_KEY=${GOOGLE_MAPS_API_KEY}

MAILER_DSN=${MAILER_DSN}
MAIL_FROM=noreply@delivery.local

MERCURE_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_PUBLIC_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_JWT_SECRET="${MERCURE_SECRET}"

DRIVER_APP_VERSION_CODE=1
DRIVER_APP_VERSION_NAME=0.1.0
DRIVER_APP_FORCE_UPDATE=0
DRIVER_APP_CHANGELOG=
DRIVER_APP_APK_FILENAME=driver.apk
EOF
info "Wrote $ENV_LOCAL"

mkdir -p "$TARGET_DIR/mercure"
cat >"$TARGET_DIR/mercure/.env" <<EOF
SERVER_NAME=http://127.0.0.1:3000
MERCURE_PUBLISHER_JWT_KEY=${MERCURE_SECRET}
MERCURE_SUBSCRIBER_JWT_KEY=${MERCURE_SECRET}
MERCURE_PUBLISHER_JWT_ALG=HS256
MERCURE_SUBSCRIBER_JWT_ALG=HS256
MERCURE_EXTRA_DIRECTIVES=cors_origins http://127.0.0.1:1420 http://localhost:1420
EOF
chmod 600 "$TARGET_DIR/mercure/.env"
info "Wrote mercure/.env"

# JWT keys are not in git. Missing/mismatched keys cause:
# login OK → immediately "Your session has expired" + cannot load routes.
ensure_jwt_keys() {
  local backend="$1"
  local gen_script="$2"
  local private_pem="$backend/config/jwt/private.pem"
  local public_pem="$backend/config/jwt/public.pem"

  mkdir -p "$backend/config/jwt" "$backend/var/uploads"

  log "Generating JWT keypair (required for login)"
  (
    cd "$backend"
    if ! php bin/console lexik:jwt:generate-keypair --overwrite --no-interaction; then
      info "lexik keygen failed — trying scripts/generate-jwt-keys.php"
      php "$gen_script" --overwrite
    fi
  )

  if [[ ! -f "$private_pem" || ! -f "$public_pem" ]]; then
    die "JWT keys missing after generation ($private_pem). Login will show 'Your session has expired'."
  fi

  chmod 640 "$private_pem" || true
  chmod 644 "$public_pem" || true

  (
    cd "$backend"
    if ! php bin/console lexik:jwt:check-config --no-interaction; then
      info "JWT check-config failed — regenerating via scripts/generate-jwt-keys.php"
      php "$gen_script" --overwrite
      php bin/console lexik:jwt:check-config --no-interaction \
        || die "JWT keys invalid. Check JWT_PASSPHRASE in backend/.env.local and regenerate."
    fi
  )

  info "JWT keys OK: $backend/config/jwt"
}

# --- PHP / JS deps ---
log "Installing PHP dependencies"
(
  cd "$TARGET_DIR/backend"
  composer install --no-interaction
)
ensure_jwt_keys "$TARGET_DIR/backend" "$TARGET_DIR/scripts/generate-jwt-keys.php"
(
  cd "$TARGET_DIR/backend"
  php bin/console doctrine:migrations:migrate --no-interaction
  php bin/console cache:clear
)

log "Installing frontend dependencies"
(
  cd "$TARGET_DIR/admin_frontend"
  npm install --no-fund --no-audit
)
(
  cd "$TARGET_DIR/driver_frontend"
  npm install --no-fund --no-audit
)

# --- binaries ---
log "Downloading Mercure / Mailpit"
bash "$TARGET_DIR/scripts/download-mercure.sh"
if [[ "$USE_MAILPIT" -eq 1 ]]; then
  bash "$TARGET_DIR/scripts/download-mailpit.sh"
fi

# --- super admin ---
if [[ "$CREATE_SUPER_ADMIN" -eq 1 ]]; then
  log "Creating super-admin"
  (
    cd "$TARGET_DIR/backend"
    # -- ends options so passwords starting with "-" are not parsed as flags
    php bin/console app:user:create-super-admin \
      --name="$SUPER_ADMIN_NAME" --force -- \
      "$SUPER_ADMIN_EMAIL" "$SUPER_ADMIN_PASSWORD"
  )
else
  info "Skipped super-admin. Later:"
  info "  cd $TARGET_DIR/backend && php bin/console app:user:create-super-admin email@example.com 'Secret' --name=Admin --force"
fi

# --- done ---
log "Setup complete"
cat <<EOF

Project:  $TARGET_DIR
API:      http://127.0.0.1:8080/
Admin UI: http://127.0.0.1:1420/
Driver:   http://127.0.0.1:1430/
Mercure:  http://127.0.0.1:3000/.well-known/mercure
JWT keys: $TARGET_DIR/backend/config/jwt/*.pem (generated; not in git)
EOF
if [[ "$USE_MAILPIT" -eq 1 ]]; then
  echo "Mailpit:  http://127.0.0.1:8025/"
fi
if [[ "$CREATE_SUPER_ADMIN" -eq 1 ]]; then
  echo "Admin login email: $SUPER_ADMIN_EMAIL"
  echo "Admin password:    (the password entered during setup; Enter kept default Admin123!)"
  echo "Login URL:         http://127.0.0.1:1420/  (admin UI, not driver)"
fi
echo
echo "If login immediately shows 'Your session has expired', regenerate JWT keys:"
echo "  cd $TARGET_DIR/backend && php bin/console lexik:jwt:generate-keypair --overwrite --no-interaction && php bin/console lexik:jwt:check-config"
echo
echo "Start later with:"
echo "  cd $TARGET_DIR && ./scripts/start-dev.sh"
echo


if [[ "$START_AFTER" -eq 1 ]]; then
  log "Starting development services"
  exec bash "$TARGET_DIR/scripts/start-dev.sh"
fi
