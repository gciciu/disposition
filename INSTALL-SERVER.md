# Deploy Delivery on ISPConfig / CloudPanel

Assumes the server already has everything needed: **ISPConfig** or **CloudPanel**, PHP 8.4+ (with `pdo_mysql`, `mysqli`, `openssl`, `mbstring`, `curl`, `zip`, `intl`, `fileinfo`, `sodium`), Composer 2, Node.js LTS, MySQL 8 (or MariaDB 10.11+), nginx, systemd, `curl`, and DNS for your domains.

This guide covers **only the project**: clone, database, env, build, panel sites, and Mercure.

## Architecture

| Component | Path / service | Purpose |
|-----------|----------------|---------|
| Admin UI | `admin_frontend/dist` | Admin SPA |
| Driver UI | `driver_frontend/dist` | Driver SPA |
| Backend | `backend/public` (PHP-FPM) | Symfony API |
| Realtime | `mercure` → `systemd` `:3000` | Mercure SSE hub |
| Database | MySQL 8 / MariaDB | Application data |

Recommended domains (replace with yours):

- `delivery.example.com` — admin SPA + API (`/api`) + Mercure (`/.well-known/mercure`)
- `driver.example.com` — driver SPA + same API (same-origin)

Both sites use the **same** document root `backend/public`. Symfony picks the SPA from the `Host` header (`ADMIN_HOST` / `DRIVER_HOST`).

```
Client → nginx (delivery / driver) → document root backend/public
           ├─ /                        → PHP-FPM → AdminSpaController or DriverSpaController
           ├─ /spa/*, /driver-spa/*     → static files copied by Vite into public/
           ├─ /api                     → PHP-FPM → backend/public/index.php
           └─ /.well-known/mercure     → proxy → 127.0.0.1:3000
```

One repository clone for everything:

```text
/var/www/delivery/
  backend/
  admin_frontend/
  driver_frontend/
  mercure/
```

`APP_USER` below = site/system user (ISPConfig: often `webX`; CloudPanel: site username).

---

## 1. Clone

```bash
mkdir -p /var/www
cd /var/www
git clone <REPOSITORY_URL> delivery
chown -R APP_USER:APP_USER /var/www/delivery
```

---

## 2. Database (project role/DB only)

```bash
sudo mysql <<'SQL'
CREATE DATABASE delivery CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'delivery'@'localhost' IDENTIFIED BY 'REPLACE_PASSWORD';
GRANT ALL PRIVILEGES ON delivery.* TO 'delivery'@'localhost';
FLUSH PRIVILEGES;
SQL
```

Or create the same user/database in the panel UI if you prefer.

---

## 3. Backend

```bash
cd /var/www/delivery/backend
cp .env.local.example .env.local
```

Production `.env.local`:

```bash
APP_ENV=prod
APP_SECRET=<random_string_32+_chars>

DATABASE_URL="mysql://delivery:REPLACE_PASSWORD@127.0.0.1:3306/delivery?serverVersion=8.4.9&charset=utf8mb4"
# MariaDB example:
# DATABASE_URL="mysql://delivery:REPLACE_PASSWORD@127.0.0.1:3306/delivery?serverVersion=10.11.2-MariaDB&charset=utf8mb4"

JWT_PASSPHRASE=<long_random_string>

CORS_ALLOW_ORIGIN='^https?://(localhost|127\.0\.0\.1|tauri\.localhost|delivery\.example\.com|driver\.example\.com)(:[0-9]+)?$'

# Hostnames for SPA routing (must match the two site domains exactly)
ADMIN_HOST=delivery.example.com
DRIVER_HOST=driver.example.com

GOOGLE_MAPS_API_KEY=<Google_Cloud_key>

MAILER_DSN=smtp://user:pass@smtp.example.com:587
MAIL_FROM=noreply@example.com

MERCURE_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_PUBLIC_URL=https://delivery.example.com/.well-known/mercure
MERCURE_JWT_SECRET="<random_secret_same_as_hub>"
```

> HTTPS → `https://…/.well-known/mercure`. Without SSL temporarily → `http://delivery.example.com/.well-known/mercure`.

```bash
cd /var/www/delivery/backend
composer install --no-dev --optimize-autoloader --no-interaction

mkdir -p var/uploads config/jwt
php bin/console lexik:jwt:generate-keypair --overwrite --no-interaction
# if OpenSSL fails — see scripts/generate-jwt-keys.php
php bin/console lexik:jwt:check-config
# must print: The configuration seems correct.
# Without valid keys, login succeeds then UI shows "Your session has expired".
chmod 640 config/jwt/private.pem
chmod 644 config/jwt/public.pem

php bin/console doctrine:migrations:migrate --no-interaction
# optional demo data (not for production):
# php bin/console doctrine:fixtures:load --no-interaction

php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:warmup --env=prod

php bin/console app:user:create-super-admin you@example.com 'StrongPassword!' --name="Admin" --force

chown -R APP_USER:www-data var
chmod -R ug+rwX var
chmod 640 config/jwt/private.pem
chmod 644 config/jwt/public.pem
```

Fixture seed users (only if you loaded fixtures):

| Role | Email | Password |
|------|-------|----------|
| Super admin | `superadmin@delivery.local` | `SuperAdmin123!` |
| Admin | `admin@delivery.local` | `Admin123!` |
| Courier | `courier@delivery.local` | `Courier123!` |

---

## 4. Frontends

`VITE_API_URL` stays empty in production — API goes same-origin via `/api`.

Both builds are required: Symfony serves `*/dist/index.html` by host, and Vite copies hashed assets into `backend/public/spa/` (admin) and `backend/public/driver-spa/` (driver) for nginx.

```bash
cd /var/www/delivery/admin_frontend
npm install --no-fund --no-audit
npm run build

cd /var/www/delivery/driver_frontend
npm install --no-fund --no-audit
npm run build
```

### Driver Android app (download link + self-update)

Public install page (share with couriers):

- Admin host: `https://delivery.example.com/download`
- Driver host (via API proxy): `https://driver.example.com/api/driver/download`
- Direct APK: `https://delivery.example.com/download/app.apk`

The link is also shown on the admin **Couriers** page (copy / open).

The Android app checks `GET /api/driver/app-version` on launch and can download the APK after confirmation.

1. Bump version in `driver_frontend/src-tauri/tauri.conf.json` (`version`) and rebuild/sign the APK.
2. Copy the APK to `backend/var/driver/driver.apk` (create the directory if needed).
3. Set in `backend/.env.local` (must match the APK / `tauri.conf.json` version):

```bash
DRIVER_APP_VERSION_CODE=2
DRIVER_APP_VERSION_NAME=0.1.1
DRIVER_APP_FORCE_UPDATE=0
DRIVER_APP_CHANGELOG=Bugfixes
DRIVER_APP_APK_FILENAME=driver.apk
```

4. `php bin/console cache:clear` (or restart php-fpm). Verify:

```bash
curl -s https://delivery.example.com/api/driver/app-version
curl -sI https://delivery.example.com/download
```

With both vhosts on `backend/public`, `/download` works on either host. `/api/driver/download` remains available as well.

Sign every release with the **same** keystore, or Android will refuse the in-place update.

---

## 5. Mercure hub

Download the official binary (same JWT secret as `backend/.env.local`, without surrounding quotes in `mercure/.env`):

```bash
cd /var/www/delivery
bash scripts/download-mercure.sh

# same secret as MERCURE_JWT_SECRET in backend/.env.local
cat > /var/www/delivery/mercure/.env <<'EOF'
SERVER_NAME=http://127.0.0.1:3000
MERCURE_PUBLISHER_JWT_KEY=YOUR_SECRET
MERCURE_SUBSCRIBER_JWT_KEY=YOUR_SECRET
MERCURE_PUBLISHER_JWT_ALG=HS256
MERCURE_SUBSCRIBER_JWT_ALG=HS256
MERCURE_EXTRA_DIRECTIVES=
EOF
chown APP_USER:APP_USER /var/www/delivery/mercure/.env
chmod 600 /var/www/delivery/mercure/.env
```

(`cors_origins *` is in `mercure/Caddyfile` — do not put it only in the env file.)

```bash
cat > /etc/systemd/system/delivery-mercure.service <<'UNIT'
[Unit]
Description=Delivery Mercure hub
After=network.target

[Service]
Type=simple
User=APP_USER
WorkingDirectory=/var/www/delivery/mercure
EnvironmentFile=/var/www/delivery/mercure/.env
ExecStart=/var/www/delivery/mercure/mercure run --config Caddyfile
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT

systemctl daemon-reload
systemctl enable --now delivery-mercure.service
ss -tlnp | grep 3000
```

Keep port `3000` on localhost only; public access is via nginx `/.well-known/mercure`.

If you previously ran the Node WebSocket hub or Docker Mercure:

```bash
systemctl disable --now delivery-realtime.service 2>/dev/null || true
docker compose -f /var/www/delivery/backend/compose.prod.yaml down 2>/dev/null || true
```

---

## 6. nginx snippets (both sites)

Use the **same** snippet on both sites. Document root for both: `/var/www/delivery/backend/public`.

Symfony chooses admin vs driver SPA from `Host` (`ADMIN_HOST` / `DRIVER_HOST`). Allow `admin_frontend` and `driver_frontend` in `open_basedir` if the panel restricts it.

```nginx
client_max_body_size 50m;

location /.well-known/mercure {
    proxy_pass http://127.0.0.1:3000;
    proxy_http_version 1.1;
    proxy_set_header Connection "";
    proxy_set_header Host $host;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Host $host;
    proxy_set_header X-Forwarded-Proto $scheme;
    proxy_buffering off;
    proxy_read_timeout 24h;
}

location / {
    try_files $uri /index.php$is_args$args;
}

location ~ ^/index\.php(/|$) {
    include fastcgi_params;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    fastcgi_param SCRIPT_FILENAME /var/www/delivery/backend/public/index.php;
    fastcgi_param SCRIPT_NAME /index.php;
    fastcgi_param DOCUMENT_ROOT /var/www/delivery/backend/public;
    fastcgi_read_timeout 120s;
    internal;
}

location ~ /\.(?!well-known) {
    deny all;
}
```

If the panel already routes all PHP through `index.php`, only the Mercure `location` is needed — put it on **both** admin and driver sites (driver SPA uses same-origin Mercure).

Do **not** wrap in `server { }` — the panel already generates it.

---

## 7. ISPConfig

1. Create two websites: `delivery.example.com`, `driver.example.com`.
2. PHP-FPM 8.4+, SSL (Let's Encrypt).
3. Document roots for **both** → `/var/www/delivery/backend/public`

If the panel forces `clientX/webY/web`:

```bash
ln -sfn /var/www/delivery/backend/public /var/www/clients/client1/web1/web
ln -sfn /var/www/delivery/backend/public /var/www/clients/client1/web2/web
```

Owner must be the site user (`webX`). Prefer one site user owning the clone when both domains are on the same panel account.

4. **Options → nginx Directives**: paste section 6 (`location` + `client_max_body_size` only). Use the site’s FPM socket for `fastcgi_pass`.
5. If `open_basedir` is on, allow `/var/www/delivery/backend`, `/var/www/delivery/admin_frontend`, `/var/www/delivery/driver_frontend` and `/tmp`.
6. Set `ADMIN_HOST` / `DRIVER_HOST` in `backend/.env.local` to the exact site hostnames.
7. `nginx -t && systemctl reload nginx`

---

## 8. CloudPanel

1. Create two **PHP** sites (8.4+): `delivery.example.com`, `driver.example.com`, enable SSL.
2. Point **both** document roots at `backend/public` (Symfony serves the matching SPA by host):

```bash
# example — adjust user/paths to your sites
rm -rf /home/delivery/htdocs/delivery.example.com
ln -sfn /var/www/delivery/backend/public /home/delivery/htdocs/delivery.example.com

rm -rf /home/driver/htdocs/driver.example.com
ln -sfn /var/www/delivery/backend/public /home/driver/htdocs/driver.example.com
```

One site-user owning both domains + one clone is simpler when possible.

3. **Vhost → Custom Nginx Config**: paste section 6 on both sites. Socket:

```bash
grep fastcgi_pass /etc/nginx/sites-enabled/*example.com*
# or: ls /home/clp/php*/var/run/*.sock
```

4. If `open_basedir` is on, include `backend`, `admin_frontend`, and `driver_frontend` (PHP must read both `dist/` trees).
5. Set `ADMIN_HOST` / `DRIVER_HOST` in `backend/.env.local` to the exact site hostnames.
6. Permissions:

```bash
chown -R APP_USER:APP_USER /var/www/delivery
chmod -R ug+rwX /var/www/delivery/backend/var
```

Use the same `APP_USER` in the Mercure systemd unit.

---

## 9. SSL / Mercure / Maps / mail

1. Certificates in the panel for both domains.
2. `MERCURE_PUBLIC_URL=https://delivery.example.com/.well-known/mercure` (admin SPA uses same-origin via nginx).
3. Google Cloud: Geocoding + Directions + Places → `GOOGLE_MAPS_API_KEY`.
4. SMTP → `MAILER_DSN` / `MAIL_FROM`. Test: admin → Email → Send test email.

```bash
curl -i "https://delivery.example.com/.well-known/mercure?topic=admin.routes"
```

Expect `200` with `text/event-stream` (or `401` without a subscriber JWT — proxy must still reach Mercure).

---

## 10. Smoke checks

```bash
systemctl is-active delivery-mercure.service

curl -s -o /dev/null -w "admin:%{http_code}\n" https://delivery.example.com/
curl -s -o /dev/null -w "driver:%{http_code}\n" https://driver.example.com/
curl -s -o /dev/null -w "api:%{http_code}\n" \
  -X POST https://delivery.example.com/api/login \
  -H "Content-Type: application/json" \
  -d '{}'
curl -s -o /dev/null -w "mercure:%{http_code}\n" \
  "https://delivery.example.com/.well-known/mercure?topic=admin.routes"
# 400/401 on api/mercure is OK; 502/404 = proxy problem
```

Browser: login on admin and driver; confirm realtime updates in admin.

---

## 11. Update

```bash
cd /var/www/delivery
git pull

cd backend
composer install --no-dev --optimize-autoloader --no-interaction
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:warmup --env=prod

cd ../admin_frontend && npm install --no-fund --no-audit && npm run build
cd ../driver_frontend && npm install --no-fund --no-audit && npm run build

bash scripts/download-mercure.sh
systemctl restart delivery-mercure.service
```

Optional host-specific automation draft: `scripts/deploy-test-server.sh` (adapt paths/domains).

---

## 12. Common issues

| Symptom | Check |
|---------|--------|
| 502 on `/api` | FPM socket, `SCRIPT_FILENAME`, `open_basedir`, `backend` permissions |
| 404 on `/api` | custom `location /api` missing / overwritten |
| SPA blank / welcome page | frontend not built, `open_basedir` blocks `../admin_frontend/dist` or `../driver_frontend/dist`, wrong document root, or `ADMIN_HOST`/`DRIVER_HOST` mismatch |
| Wrong SPA on a domain | `ADMIN_HOST` / `DRIVER_HOST` must equal the exact Host (no `www.` unless the cert/site uses it) |
| Mercure fails | `/.well-known/mercure` location, `delivery-mercure` on `:3000`, JWT secret match |
| CORS | `CORS_ALLOW_ORIGIN` domains |
| JWT 500 / login then **Your session has expired** + cannot load routes | `config/jwt/*.pem` missing or out of sync with `JWT_PASSPHRASE` (keys are not in git — generate on every machine). Fix: `mkdir -p config/jwt && php bin/console lexik:jwt:generate-keypair --overwrite --no-interaction && php bin/console lexik:jwt:check-config` |
| Login **200**, next `/api/.../routes` → **401 JWT Token not found** | `Authorization` header never reaches PHP/Lexik (CGI/proxy strip). SPA sends fallback `X-Delivery-Authorization`; `public/index.php` + `public/router.php` restore it. Dev: start API via `./scripts/start-dev.sh` (uses `public/router.php`). In Network → Request Headers both `Authorization` and `X-Delivery-Authorization` must be present. |
| Uploads / cache | `backend/var` permissions |

---

## Checklist

- [ ] Clone + ownership
- [ ] DB role/database + `.env.local`
- [ ] Composer, JWT, migrate, super admin
- [ ] Build admin + driver
- [ ] Mercure binary + systemd `delivery-mercure`
- [ ] Two panel sites + SSL + document roots
- [ ] Custom nginx: `/.well-known/mercure`, PHP `index.php` fallback
- [ ] Smoke tests + UI login
