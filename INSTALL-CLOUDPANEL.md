# Install & launch on CloudPanel (production)

English guide for deploying **Delivery** on a server managed by **CloudPanel**.

Replace domains/paths/users with yours if they differ. This document uses:

| Role | Domain |
|------|--------|
| Admin SPA + API + Mercure | `https://delivery.produkt-konfigurator24.de` |
| Driver SPA + API | `https://driver.produkt-konfigurator24.de` |

---

## Prerequisites (CloudPanel / server)

Already available on the host:

- CloudPanel with nginx + PHP-FPM
- **PHP 8.4+** with: `pdo_mysql`, `mysqli`, `openssl`, `mbstring`, `curl`, `zip`, `intl`, `fileinfo`, `sodium`
- Composer 2
- Node.js LTS (for frontend builds)
- MySQL 8 or MariaDB 10.11+
- systemd, curl, git
- DNS A/AAAA records for both domains pointing at the server

`APP_USER` below = CloudPanel **site username** that owns the files (and preferably both sites).

---

## Architecture

One git clone; both CloudPanel sites share the same document root.

```text
/var/www/delivery/
  backend/           # Symfony (API + SPA routing)
  admin_frontend/    # Admin Vue SPA → dist/
  driver_frontend/   # Driver Vue SPA → dist/
  mercure/           # Official Mercure hub binary (:3000)
```

```text
Browser
  → CloudPanel nginx (delivery.* / driver.*)
      document root → /var/www/delivery/backend/public
           ├─ /                    → PHP → Admin or Driver SPA (by Host)
           ├─ /spa/*, /driver-spa/* → static assets from Vite build
           ├─ /api                 → PHP → Symfony API
           └─ /.well-known/mercure → proxy → 127.0.0.1:3000
```

Symfony selects the SPA with `ADMIN_HOST` / `DRIVER_HOST` (must match the Host header exactly).

---

## 1. Create sites in CloudPanel

1. Create **two PHP sites** (PHP **8.4+**):
   - `delivery.produkt-konfigurator24.de`
   - `driver.produkt-konfigurator24.de`
2. Enable **SSL** (Let's Encrypt) for both.
3. Prefer **one site user** that can own `/var/www/delivery` (simpler permissions). If CloudPanel creates two users, pick one as `APP_USER` and use it for ownership + the Mercure systemd unit.

Note the site home paths CloudPanel created, for example:

```text
/home/<site-user>/htdocs/delivery.produkt-konfigurator24.de
/home/<site-user>/htdocs/driver.produkt-konfigurator24.de
```

---

## 2. Clone the project

```bash
sudo mkdir -p /var/www
sudo git clone <REPOSITORY_URL> /var/www/delivery
sudo chown -R APP_USER:APP_USER /var/www/delivery
```

Point **both** site document roots at Symfony `public/`:

```bash
# Adjust home paths / site-user to match CloudPanel
rm -rf /home/APP_USER/htdocs/delivery.produkt-konfigurator24.de
ln -sfn /var/www/delivery/backend/public \
  /home/APP_USER/htdocs/delivery.produkt-konfigurator24.de

rm -rf /home/APP_USER/htdocs/driver.produkt-konfigurator24.de
ln -sfn /var/www/delivery/backend/public \
  /home/APP_USER/htdocs/driver.produkt-konfigurator24.de
```

If CloudPanel recreates empty htdocs folders after UI changes, re-create the symlinks.

---

## 3. Database

In CloudPanel → **Databases**, create:

- Database: `delivery`
- User: `delivery`
- Password: strong secret
- Host: `127.0.0.1` / localhost

Or via MySQL CLI:

```bash
sudo mysql <<'SQL'
CREATE DATABASE delivery CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'delivery'@'localhost' IDENTIFIED BY 'REPLACE_PASSWORD';
GRANT ALL PRIVILEGES ON delivery.* TO 'delivery'@'localhost';
FLUSH PRIVILEGES;
SQL
```

---

## 4. Backend configuration

```bash
cd /var/www/delivery/backend
cp .env.local.example .env.local
nano .env.local   # or your editor
```

Production values for `.env.local`:

```bash
APP_ENV=prod
APP_SECRET=<random_string_32+_chars>

DATABASE_URL="mysql://delivery:REPLACE_PASSWORD@127.0.0.1:3306/delivery?serverVersion=8.4.9&charset=utf8mb4"
# MariaDB example:
# DATABASE_URL="mysql://delivery:REPLACE_PASSWORD@127.0.0.1:3306/delivery?serverVersion=10.11.2-MariaDB&charset=utf8mb4"

JWT_PASSPHRASE=<long_random_string>

CORS_ALLOW_ORIGIN='^https?://(localhost|127\.0\.0\.1|tauri\.localhost|delivery\.produkt-konfigurator24\.de|driver\.produkt-konfigurator24\.de)(:[0-9]+)?$'

ADMIN_HOST=delivery.produkt-konfigurator24.de
DRIVER_HOST=driver.produkt-konfigurator24.de

GOOGLE_MAPS_API_KEY=<Google_Cloud_key>

# Outbound email — see section 5 for full SMTP setup
MAILER_DSN=smtp://USER:PASSWORD@smtp.example.com:587
MAIL_FROM=noreply@produkt-konfigurator24.de

# Optional: letterhead on Lieferbestätigung PDF
COMPANY_NAME="MySpiegel-Lieferservice"
COMPANY_ADDRESS="Holländische Str. 207b, 34127 Kassel"
COMPANY_PHONE="+49561 2207980"
COMPANY_EMAIL="info@myspiegel.de"
COMPANY_WEBSITE="www.myspiegel.de"

MERCURE_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_PUBLIC_URL=https://delivery.produkt-konfigurator24.de/.well-known/mercure
MERCURE_JWT_SECRET="<random_secret_same_as_hub>"
```

Install and initialize:

```bash
cd /var/www/delivery/backend
composer install --no-dev --optimize-autoloader --no-interaction

mkdir -p var/uploads config/jwt
php bin/console lexik:jwt:generate-keypair --overwrite --no-interaction
# If OpenSSL fails: php ../scripts/generate-jwt-keys.php  (from repo scripts/)
php bin/console lexik:jwt:check-config
# Expected: The configuration seems correct.

php bin/console doctrine:migrations:migrate --no-interaction

php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:warmup --env=prod

php bin/console app:user:create-super-admin you@example.com 'StrongPassword!' --name="Admin" --force

chown -R APP_USER:APP_USER /var/www/delivery
chmod -R ug+rwX var
chmod 640 config/jwt/private.pem
chmod 644 config/jwt/public.pem
```

---

## 5. Outbound email (SMTP)

CloudPanel **does not** provide a mail server (by design). Delivery sends mail via **Symfony Mailer** using `MAILER_DSN` / `MAIL_FROM` in `backend/.env.local`. Use an external SMTP provider.

Used for:

- Admin → **Email** → test messages
- Customer **Lieferbestätigung** (PDF attachment) after delivery

### 5.1 Provider and mailbox

Pick one:

| Option | Examples |
|--------|----------|
| Hosted mailbox | Google Workspace, Microsoft 365, Zoho Mail, Amazon WorkMail |
| Transactional SMTP | Amazon SES, SendGrid, Mailgun, Postmark, SMTP2GO |

Create a dedicated sender address, e.g. `noreply@produkt-konfigurator24.de` (or reuse an existing company mailbox). Prefer a real domain you control so SPF/DKIM can be set.

From the provider, collect:

- SMTP host (e.g. `smtp.gmail.com`, `email-smtp.eu-west-1.amazonaws.com`)
- Port: **587** (STARTTLS) or **465** (SMTPS)
- Username (often the full email)
- Password or SMTP/API credential

### 5.2 `MAILER_DSN` and `MAIL_FROM`

In `/var/www/delivery/backend/.env.local`:

```bash
# Port 587 + STARTTLS (most providers)
MAILER_DSN=smtp://USER:PASSWORD@smtp.example.com:587

# Port 465 + implicit TLS
# MAILER_DSN=smtps://USER:PASSWORD@smtp.example.com:465

MAIL_FROM=noreply@produkt-konfigurator24.de
```

Rules:

- `MAIL_FROM` must be a **valid email**; it becomes the From address (display name: `Delivery`).
- Prefer that `MAIL_FROM` matches a mailbox/domain the SMTP provider is allowed to send for (avoids reject/spam).
- If the password contains `@`, `:`, `/`, `#`, `%`, etc., **URL-encode** it in the DSN  
  (e.g. `@` → `%40`, `#` → `%23`, `/` → `%2F`).
- `null://null` accepts messages but **does not deliver** — never use in production.

Examples:

```bash
# Google Workspace / Gmail (app password if 2FA)
MAILER_DSN=smtp://noreply%40produkt-konfigurator24.de:APP_PASSWORD@smtp.gmail.com:587

# Microsoft 365
MAILER_DSN=smtp://noreply%40produkt-konfigurator24.de:PASSWORD@smtp.office365.com:587

# Generic provider STARTTLS
MAILER_DSN=smtp://apikey:SECRET_KEY@smtp.sendgrid.net:587
```

After changing mail env vars:

```bash
cd /var/www/delivery/backend
php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:warmup --env=prod
```

### 5.3 DNS (deliverability)

At the DNS host for `produkt-konfigurator24.de` (or the domain of `MAIL_FROM`):

1. **SPF** — TXT on the apex (or subdomain) allowing your SMTP provider, e.g.  
   `v=spf1 include:_spf.google.com ~all` (adjust to your provider’s include).
2. **DKIM** — enable in the provider UI and publish the TXT/CNAME records they give you.
3. **DMARC** (recommended) — e.g.  
   `v=DMARC1; p=none; rua=mailto:you@produkt-konfigurator24.de`

Without SPF/DKIM, confirmation emails often land in spam or are rejected.

### 5.4 Test

1. Log in to admin: `https://delivery.produkt-konfigurator24.de/`
2. Open **Email** (E-Mail-Test).
3. Send **test email** to your own address.
4. Optionally send **test confirmation** (sample Lieferbestätigung + PDF).

Or via API (with a valid admin JWT):

```bash
curl -s -X POST https://delivery.produkt-konfigurator24.de/api/admin/email/test \
  -H "Authorization: Bearer YOUR_JWT" \
  -H "Content-Type: application/json" \
  -d '{"to":"you@example.com"}'
```

Expect success and a real inbox message. If the response mentions `null transport`, `MAILER_DSN` is still `null://null`.

### 5.5 Optional company letterhead

`COMPANY_*` in `.env.local` only affect the **PDF letterhead**, not SMTP. Align them with the live brand before going live with customer confirmation emails.

---

## 6. Build frontends

`VITE_API_URL` must stay empty in production (same-origin `/api`).

```bash
cd /var/www/delivery/admin_frontend
npm install --no-fund --no-audit
npm run build

cd /var/www/delivery/driver_frontend
npm install --no-fund --no-audit
npm run build
```

Builds:

- serve `*/dist/index.html` via Symfony by host
- copy hashed assets into `backend/public/spa/` (admin) and `backend/public/driver-spa/` (driver)

---

## 7. Start Mercure (systemd)

Mercure runs on **localhost:3000** only. Browsers reach it through nginx on the admin domain.

```bash
cd /var/www/delivery
bash scripts/download-mercure.sh
```

Create `mercure/.env` — JWT secret must match `MERCURE_JWT_SECRET` in `backend/.env.local` (**no quotes** around the secret here):

```bash
cat > /var/www/delivery/mercure/.env <<'EOF'
SERVER_NAME=http://127.0.0.1:3000
MERCURE_PUBLISHER_JWT_KEY=YOUR_SECRET
MERCURE_SUBSCRIBER_JWT_KEY=YOUR_SECRET
MERCURE_PUBLISHER_JWT_ALG=HS256
MERCURE_SUBSCRIBER_JWT_ALG=HS256
MERCURE_EXTRA_DIRECTIVES="cors_origins *"
EOF
chown APP_USER:APP_USER /var/www/delivery/mercure/.env
chmod 600 /var/www/delivery/mercure/.env
```

Install and start the service (replace `APP_USER`):

```bash
sudo tee /etc/systemd/system/delivery-mercure.service >/dev/null <<'UNIT'
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

sudo systemctl daemon-reload
sudo systemctl enable --now delivery-mercure.service
systemctl status delivery-mercure.service
ss -tlnp | grep 3000
```

Do **not** open port 3000 publicly.

Useful commands later:

```bash
sudo systemctl restart delivery-mercure.service
sudo systemctl status delivery-mercure.service
journalctl -u delivery-mercure.service -f
```

---

## 8. CloudPanel nginx (both sites)

In each site → **Vhost** → **Custom Nginx Config**, paste **only** locations (CloudPanel already wraps `server { }`).

Find the PHP-FPM socket first:

```bash
grep fastcgi_pass /etc/nginx/sites-enabled/*produkt-konfigurator24.de*
# or: ls /home/clp/php*/var/run/*.sock
```

Use that socket in `fastcgi_pass` below.

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
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;   # ← replace with your CloudPanel socket
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

If CloudPanel already routes PHP correctly and custom PHP location conflicts, keep at least:

- `client_max_body_size 50m;`
- the `/.well-known/mercure` block (required on the **delivery** site; optional on driver)

Then:

```bash
sudo nginx -t && sudo systemctl reload nginx
```

### open_basedir

If PHP `open_basedir` is enabled, allow:

- `/var/www/delivery/backend`
- `/var/www/delivery/admin_frontend`
- `/var/www/delivery/driver_frontend`
- `/tmp`

PHP must read both `admin_frontend/dist` and `driver_frontend/dist`.

---

## 9. Launch checklist (first start)

After the steps above, the stack is running when:

1. Both CloudPanel sites are **Active** + SSL OK  
2. `delivery-mercure.service` is **active** and listens on `127.0.0.1:3000`  
3. nginx reloaded with Mercure + PHP config  
4. Frontends built; `ADMIN_HOST` / `DRIVER_HOST` match the domains  
5. SMTP works: admin → **Email** → test message arrives  

Smoke tests:

```bash
systemctl is-active delivery-mercure.service

curl -s -o /dev/null -w "admin:%{http_code}\n" \
  https://delivery.produkt-konfigurator24.de/
curl -s -o /dev/null -w "driver:%{http_code}\n" \
  https://driver.produkt-konfigurator24.de/
curl -s -o /dev/null -w "api:%{http_code}\n" \
  -X POST https://delivery.produkt-konfigurator24.de/api/login \
  -H "Content-Type: application/json" \
  -d '{}'
curl -s -o /dev/null -w "mercure:%{http_code}\n" \
  "https://delivery.produkt-konfigurator24.de/.well-known/mercure?topic=admin.routes"
# 400/401 on api/mercure is OK; 502/404 = proxy / service problem
```

In the browser:

- Log in on admin and driver
- Confirm live updates in admin when a driver changes a route

---

## 10. Updates (redeploy)

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

cd /var/www/delivery
bash scripts/download-mercure.sh
sudo systemctl restart delivery-mercure.service
```

---

## 11. Optional: driver Android APK

1. Build/sign the APK; bump `version` in `driver_frontend/src-tauri/tauri.conf.json`.
2. Copy to `backend/var/driver/driver.apk`.
3. Set in `backend/.env.local`:

```bash
DRIVER_APP_VERSION_CODE=2
DRIVER_APP_VERSION_NAME=0.1.1
DRIVER_APP_FORCE_UPDATE=0
DRIVER_APP_CHANGELOG=Bugfixes
DRIVER_APP_APK_FILENAME=driver.apk
```

4. `php bin/console cache:clear --env=prod`

Install page: `https://delivery.produkt-konfigurator24.de/download`

---

## Common issues

| Symptom | What to check |
|---------|----------------|
| 502 on `/api` | PHP-FPM socket, `SCRIPT_FILENAME`, `open_basedir`, ownership of `/var/www/delivery` |
| Blank SPA / CloudPanel default page | Symlink to `backend/public`, frontends built, `open_basedir` includes both `*_frontend` dirs |
| Wrong app on a domain | `ADMIN_HOST` / `DRIVER_HOST` must equal Host exactly (no extra `www.` unless the site uses it) |
| Mercure 502/404 | `delivery-mercure` running, nginx Mercure location, secret match between hub and Symfony |
| Login OK then “session expired” | JWT keys missing — regenerate `config/jwt/*.pem` and re-check `JWT_PASSPHRASE` |
| Uploads / cache errors | `chmod -R ug+rwX backend/var` and correct owner |
| Email not delivered / “null transport” | Real `MAILER_DSN` (not `null://null`), valid `MAIL_FROM`, URL-encoded password; then `cache:clear` / `cache:warmup` |
| SMTP auth / TLS errors | Port 587 vs `smtps://…:465`, correct host/user; check provider allows SMTP from the server IP |
| Mail goes to spam | SPF + DKIM (+ DMARC) for the `MAIL_FROM` domain at your DNS host |

---

## Final checklist

- [ ] Two CloudPanel PHP 8.4 sites + SSL
- [ ] Both document roots → `/var/www/delivery/backend/public` (symlinks)
- [ ] Database + `backend/.env.local` (`ADMIN_HOST`, `DRIVER_HOST`, Mercure, DB, JWT, mail)
- [ ] SMTP configured (`MAILER_DSN` / `MAIL_FROM`); SPF/DKIM set; admin Email test OK
- [ ] `composer install`, JWT keys, migrations, super admin
- [ ] `npm run build` for admin + driver
- [ ] Mercure binary + `delivery-mercure.service` enabled
- [ ] Custom nginx (Mercure proxy + PHP fallback) on both sites
- [ ] `open_basedir` allows backend + both frontends
- [ ] Smoke curls + browser login on both domains
