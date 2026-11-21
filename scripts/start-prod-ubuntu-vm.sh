#!/usr/bin/env bash
# Production smoke stack for VirtualBox Ubuntu (host-based admin/driver split).
# Intended for local VM testing, not a public internet deploy.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
APP="${DELIVERY_APP_DIR:-$HOME/client-setup/delivery}"
ADMIN_HOST="${ADMIN_HOST:-delivery.example.test}"
DRIVER_HOST="${DRIVER_HOST:-driver.example.test}"
DB_NAME="${DELIVERY_DB_NAME:-delivery}"
DB_USER="${DELIVERY_DB_USER:-delivery}"
DB_PASS="${DELIVERY_DB_PASS:-delivery}"
MERCURE_SECRET="${MERCURE_JWT_SECRET:-ChangeThisMercureHubJWTSecretKeyForVmProd}"
APP_SECRET="${APP_SECRET:-$(openssl rand -hex 24)}"
JWT_PASSPHRASE="${JWT_PASSPHRASE:-$(openssl rand -hex 32)}"
SYNC_FROM_SHARED="${SYNC_FROM_SHARED:-0}"
SHARED_SRC="${SHARED_SRC:-/media/sf_delivery}"

log()  { printf '\n==> %s\n' "$*"; }
info() { printf '    %s\n' "$*"; }
die()  { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

need_sudo() {
  sudo -n true 2>/dev/null || die "passwordless sudo required"
}

wait_dpkg() {
  local i=0
  while sudo fuser /var/lib/dpkg/lock-frontend >/dev/null 2>&1 \
     || sudo fuser /var/lib/dpkg/lock >/dev/null 2>&1; do
    i=$((i + 1))
    [[ $i -gt 60 ]] && die "dpkg lock held too long"
    info "Waiting for dpkg lock ($i)..."
    sleep 5
  done
}

install_packages() {
  if command -v nginx >/dev/null 2>&1 && command -v php-fpm8.4 >/dev/null 2>&1; then
    log "nginx + php8.4-fpm already installed — skip apt"
    return
  fi
  log "Installing nginx + php-fpm"
  wait_dpkg
  sudo apt-get update -y -o Acquire::AllowReleaseInfoChange::Label=true \
    -o Acquire::AllowReleaseInfoChange::Version=true || true
  wait_dpkg
  sudo DEBIAN_FRONTEND=noninteractive apt-get install -y \
    nginx php8.4-fpm php8.4-cli php8.4-mysql php8.4-xml php8.4-mbstring \
    php8.4-curl php8.4-zip php8.4-intl php8.4-bcmath
}

sync_app() {
  mkdir -p "$(dirname "$APP")"
  if [[ "$SYNC_FROM_SHARED" == "1" && -d "$SHARED_SRC/backend" ]]; then
    log "Syncing project from $SHARED_SRC -> $APP"
    mkdir -p "$APP"
    if ! command -v rsync >/dev/null 2>&1; then
      sudo DEBIAN_FRONTEND=noninteractive apt-get install -y rsync
    fi
    # Copy only source trees (vboxsf full-tree rsync is too slow / huge).
    for rel in backend/src backend/config backend/public backend/migrations \
      backend/templates backend/composer.json backend/composer.lock \
      backend/bin admin_frontend/src admin_frontend/public admin_frontend/index.html \
      admin_frontend/package.json admin_frontend/package-lock.json admin_frontend/vite.config.ts \
      admin_frontend/tsconfig.json admin_frontend/tsconfig.node.json \
      driver_frontend/src driver_frontend/index.html driver_frontend/package.json \
      driver_frontend/package-lock.json driver_frontend/vite.config.ts \
      driver_frontend/tsconfig.json driver_frontend/tsconfig.node.json \
      scripts INSTALL-SERVER.md; do
      src="$SHARED_SRC/$rel"
      dst="$APP/$rel"
      [[ -e "$src" ]] || continue
      mkdir -p "$(dirname "$dst")"
      if [[ -d "$src" ]]; then
        mkdir -p "$dst"
        rsync -a --delete --exclude 'node_modules/' --exclude 'dist/' --exclude 'var/cache/' --exclude 'var/log/' "$src/" "$dst/"
      else
        cp -a "$src" "$dst"
      fi
    done
    # Keep SPA entrypoints / assets dirs present
    mkdir -p "$APP/backend/public"
  fi
  [[ -d "$APP/backend" ]] || die "App not found at $APP"
}

write_env() {
  log "Writing production .env.local"
  local maps_key=""
  if [[ -f "$SHARED_SRC/backend/.env.local" ]]; then
    maps_key="$(grep -E '^GOOGLE_MAPS_API_KEY=' "$SHARED_SRC/backend/.env.local" | head -1 | cut -d= -f2- || true)"
  fi
  if [[ -z "$maps_key" && -f "$APP/backend/.env.local" ]]; then
    maps_key="$(grep -E '^GOOGLE_MAPS_API_KEY=' "$APP/backend/.env.local" | head -1 | cut -d= -f2- || true)"
  fi

  cat > "$APP/backend/.env.local" <<EOF
APP_ENV=prod
APP_SECRET=${APP_SECRET}

DATABASE_URL="mysql://${DB_USER}:${DB_PASS}@127.0.0.1:3306/${DB_NAME}?serverVersion=8.0.46&charset=utf8mb4"

JWT_PASSPHRASE=${JWT_PASSPHRASE}

CORS_ALLOW_ORIGIN='^https?://(${ADMIN_HOST//./\\.}|${DRIVER_HOST//./\\.}|127\\.0\\.0\\.1|localhost)(:[0-9]+)?$'

ADMIN_HOST=${ADMIN_HOST}
DRIVER_HOST=${DRIVER_HOST}

GOOGLE_MAPS_API_KEY=${maps_key}

MAILER_DSN=null://null
MAIL_FROM=noreply@${ADMIN_HOST}

MERCURE_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_PUBLIC_URL=http://${ADMIN_HOST}/.well-known/mercure
MERCURE_JWT_SECRET="${MERCURE_SECRET}"

DRIVER_APP_VERSION_CODE=1
DRIVER_APP_VERSION_NAME=0.1.0
DRIVER_APP_FORCE_UPDATE=0
DRIVER_APP_CHANGELOG=
DRIVER_APP_APK_FILENAME=driver.apk
EOF
  chmod 600 "$APP/backend/.env.local"

  mkdir -p "$APP/mercure"
  cat > "$APP/mercure/.env" <<EOF
SERVER_NAME=http://127.0.0.1:3000
MERCURE_PUBLISHER_JWT_KEY=${MERCURE_SECRET}
MERCURE_SUBSCRIBER_JWT_KEY=${MERCURE_SECRET}
MERCURE_PUBLISHER_JWT_ALG=HS256
MERCURE_SUBSCRIBER_JWT_ALG=HS256
MERCURE_EXTRA_DIRECTIVES="cors_origins *"
EOF
  chmod 600 "$APP/mercure/.env"
}

ensure_db() {
  log "Ensuring MySQL database/user"
  sudo systemctl enable --now mysql
  sudo mysql -e "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
  sudo mysql -e "CREATE USER IF NOT EXISTS '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
  sudo mysql -e "ALTER USER '${DB_USER}'@'localhost' IDENTIFIED BY '${DB_PASS}';"
  sudo mysql -e "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${DB_USER}'@'localhost'; FLUSH PRIVILEGES;"
}

build_backend() {
  log "Composer + migrate + cache (prod)"
  cd "$APP/backend"
  composer install --no-dev --optimize-autoloader --no-interaction
  mkdir -p var/uploads config/jwt
  if [[ ! -f config/jwt/private.pem || ! -f config/jwt/public.pem ]]; then
    php bin/console lexik:jwt:generate-keypair --overwrite --no-interaction
  fi
  php bin/console lexik:jwt:check-config
  php bin/console doctrine:migrations:migrate --no-interaction --env=prod
  php bin/console cache:clear --env=prod --no-warmup
  php bin/console cache:warmup --env=prod
  php bin/console app:user:create-super-admin admin@delivery.local 'Admin123!' --name="Admin" --force || true
  php <<'PHP'
<?php
require __DIR__.'/vendor/autoload.php';
use App\Entity\User;
use App\Enum\UserRole;
use App\Kernel;
use Symfony\Component\Dotenv\Dotenv;
(new Dotenv())->bootEnv(__DIR__.'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();
$em = $kernel->getContainer()->get('doctrine')->getManager();
$email = 'courier@delivery.local';
$user = $em->getRepository(User::class)->findOneBy(['email' => $email]) ?? new User();
$user->setEmail($email)->setName('Courier')->setRoles([UserRole::Courier->value])->setIsActive(true);
$user->setPassword(password_hash('Courier123!', PASSWORD_BCRYPT));
$em->persist($user);
$em->flush();
echo "Courier ready: {$email}\n";
PHP
  chmod -R ug+rwX var || true
  chmod 640 config/jwt/private.pem || true
  chmod 644 config/jwt/public.pem || true
}

build_frontends() {
  log "Building admin frontend"
  cd "$APP/admin_frontend"
  npm install --no-fund --no-audit
  npm run build

  log "Building driver frontend"
  cd "$APP/driver_frontend"
  npm install --no-fund --no-audit
  npm run build
}

setup_mercure() {
  log "Mercure hub systemd unit"
  cd "$APP"
  bash scripts/download-mercure.sh

  sudo tee /etc/systemd/system/delivery-mercure.service >/dev/null <<UNIT
[Unit]
Description=Delivery Mercure hub
After=network.target

[Service]
Type=simple
User=delivery
WorkingDirectory=${APP}/mercure
EnvironmentFile=${APP}/mercure/.env
ExecStart=${APP}/mercure/mercure run --config Caddyfile
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT

  sudo systemctl daemon-reload
  sudo systemctl enable --now delivery-mercure.service
}

write_nginx() {
  log "Writing nginx vhosts for ${ADMIN_HOST} and ${DRIVER_HOST}"
  local sock
  sock="$(ls /run/php/php8.4-fpm.sock 2>/dev/null || ls /run/php/php*-fpm.sock | head -1)"
  [[ -n "$sock" ]] || die "php-fpm socket not found"

  sudo tee /etc/nginx/sites-available/delivery-prod >/dev/null <<NGINX
server {
    listen 80;
    server_name ${ADMIN_HOST} ${DRIVER_HOST};
    root ${APP}/backend/public;
    index index.php;
    client_max_body_size 50m;

    location /.well-known/mercure {
        proxy_pass http://127.0.0.1:3000;
        proxy_http_version 1.1;
        proxy_set_header Connection "";
        proxy_set_header Host \$host;
        proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
        proxy_set_header X-Forwarded-Host \$host;
        proxy_set_header X-Forwarded-Proto \$scheme;
        proxy_buffering off;
        proxy_read_timeout 24h;
    }

    location / {
        try_files \$uri /index.php\$is_args\$args;
    }

    location ~ ^/index\\.php(/|\$) {
        include fastcgi_params;
        fastcgi_pass unix:${sock};
        fastcgi_param SCRIPT_FILENAME ${APP}/backend/public/index.php;
        fastcgi_param SCRIPT_NAME /index.php;
        fastcgi_param DOCUMENT_ROOT ${APP}/backend/public;
        fastcgi_read_timeout 120s;
        internal;
    }

    location ~ /\\.(?!well-known) {
        deny all;
    }
}
NGINX

  sudo ln -sfn /etc/nginx/sites-available/delivery-prod /etc/nginx/sites-enabled/delivery-prod
  sudo rm -f /etc/nginx/sites-enabled/default
  echo "127.0.0.1 ${ADMIN_HOST} ${DRIVER_HOST}" | sudo tee /etc/hosts.d-delivery >/dev/null || true
  if ! grep -q "${ADMIN_HOST}" /etc/hosts; then
    echo "127.0.0.1 ${ADMIN_HOST} ${DRIVER_HOST}" | sudo tee -a /etc/hosts >/dev/null
  fi
  sudo nginx -t
  sudo systemctl enable --now php8.4-fpm
  sudo systemctl enable --now nginx
  sudo systemctl reload nginx
}

fix_permissions() {
  log "Fixing permissions for nginx/php-fpm under home/"
  # www-data must traverse every path component to read public/ assets
  sudo chmod 755 /home/delivery /home/delivery/client-setup "$APP" "$APP/backend" 2>/dev/null || true
  sudo chmod -R a+rX \
    "$APP/backend/public" \
    "$APP/backend/vendor" \
    "$APP/backend/config" \
    "$APP/backend/src" \
    "$APP/admin_frontend/dist" \
    "$APP/driver_frontend/dist" \
    "$APP/backend/var" || true
  sudo chgrp -R www-data "$APP/backend/var" 2>/dev/null || true
  sudo chmod -R ug+rwX "$APP/backend/var" || true
  sudo chmod a+r "$APP/backend/.env.local" "$APP/backend/.env" 2>/dev/null || true

  local pool=/etc/php/8.4/fpm/pool.d/www.conf
  if [[ -f "$pool" ]]; then
    sudo sed -i 's/^user = .*/user = delivery/' "$pool"
    sudo sed -i 's/^group = .*/group = delivery/' "$pool"
    sudo sed -i 's/^listen.owner = .*/listen.owner = www-data/' "$pool"
    sudo sed -i 's/^listen.group = .*/listen.group = www-data/' "$pool"
    sudo systemctl restart php8.4-fpm
  fi
  sudo systemctl reload nginx || true
}

smoke() {
  log "Smoke checks"
  sleep 1
  local admin_html driver_html
  admin_html="$(curl -s -H "Host: ${ADMIN_HOST}" http://127.0.0.1/ || true)"
  driver_html="$(curl -s -H "Host: ${DRIVER_HOST}" http://127.0.0.1/ || true)"

  printf 'admin_http=%s\n' "$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${ADMIN_HOST}" http://127.0.0.1/)"
  printf 'driver_http=%s\n' "$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${DRIVER_HOST}" http://127.0.0.1/)"
  printf 'api_login=%s\n' "$(curl -s -o /dev/null -w '%{http_code}' -X POST -H "Host: ${ADMIN_HOST}" -H 'Content-Type: application/json' -d '{}' http://127.0.0.1/api/login)"
  printf 'mercure=%s\n' "$(curl -s -o /dev/null -w '%{http_code}' -H "Host: ${ADMIN_HOST}" "http://127.0.0.1/.well-known/mercure?topic=admin.routes")"

  echo "$admin_html" | grep -q '/spa/' && info "OK: admin HTML references /spa/" || info "WARN: admin HTML missing /spa/"
  echo "$driver_html" | grep -q '/driver-spa/' && info "OK: driver HTML references /driver-spa/" || info "WARN: driver HTML missing /driver-spa/"
  echo "$admin_html" | grep -q '/driver-spa/' && info "FAIL: admin HTML also has /driver-spa/" || info "OK: admin HTML has no /driver-spa/"
  echo "$driver_html" | grep -q '/spa/' && info "FAIL: driver HTML also has /spa/ (without driver-)" || true
  if echo "$driver_html" | grep -q 'driver-spa'; then
    info "OK: driver SPA asset prefix present"
  fi

  log "Done"
  info "From Windows host (NAT forward 8088->80), add to hosts:"
  info "  127.0.0.1 ${ADMIN_HOST} ${DRIVER_HOST}"
  info "Then open:"
  info "  http://${ADMIN_HOST}:8088/   (admin)"
  info "  http://${DRIVER_HOST}:8088/  (driver)"
}

main() {
  need_sudo
  install_packages
  sync_app
  write_env
  ensure_db
  build_backend
  build_frontends
  setup_mercure
  write_nginx
  fix_permissions
  smoke
}

main "$@"
