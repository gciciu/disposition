# Mercure — quick start

Replace:

| Placeholder | Meaning |
|-------------|---------|
| `APP_USER` | file owner (CloudPanel site user) |
| `ADMIN_HOST` | admin domain, e.g. `delivery.produkt-konfigurator24.de` |
| `SECRET` | same secret in backend and hub (`openssl rand -hex 32`) |
| `/var/www/delivery` | project root — change everywhere if different |

Do not expose port `3000` publicly.

---

### 1. Backend env

In `backend/.env.local`:

```bash
MERCURE_URL=http://127.0.0.1:3000/.well-known/mercure
MERCURE_PUBLIC_URL=https://ADMIN_HOST/.well-known/mercure
MERCURE_JWT_SECRET="SECRET"
```

```bash
cd /var/www/delivery/backend
php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:warmup --env=prod
```

---

### 2. Binary

```bash
cd /var/www/delivery
bash scripts/download-mercure.sh
```

---

### 3. Hub env

`SECRET` **without quotes** (same value as `MERCURE_JWT_SECRET`):

```bash
cat > /var/www/delivery/mercure/.env <<'EOF'
SERVER_NAME=http://127.0.0.1:3000
MERCURE_PUBLISHER_JWT_KEY=SECRET
MERCURE_SUBSCRIBER_JWT_KEY=SECRET
MERCURE_PUBLISHER_JWT_ALG=HS256
MERCURE_SUBSCRIBER_JWT_ALG=HS256
MERCURE_EXTRA_DIRECTIVES="cors_origins *"
EOF
chown APP_USER:APP_USER /var/www/delivery/mercure/.env
chmod 600 /var/www/delivery/mercure/.env
```

---

### 4. systemd

Replace `APP_USER` in the unit:

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
ss -tlnp | grep 3000
```

---

### 5. nginx (admin site)

Custom Nginx Config:

```nginx
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
```

```bash
sudo nginx -t && sudo systemctl reload nginx
```

---

### 6. Verify / diagnose

```bash
cd /var/www/delivery   # or your real project root
ADMIN_HOST=ADMIN_HOST bash scripts/check-mercure.sh
```

The script checks binary (incl. Windows-on-Linux / `203/EXEC`), `.env` JWT match, systemd paths/User, `:3000`, local + public HTTP, and nginx `/.well-known/mercure`.

Quick manual probe:

```bash
systemctl is-active delivery-mercure.service
curl -s -o /dev/null -w "%{http_code}\n" \
  "https://ADMIN_HOST/.well-known/mercure?topic=admin.routes"
# 400/401 = OK; 502/404 = service or nginx
```

---

### Later (restart / update)

```bash
cd /var/www/delivery
bash scripts/download-mercure.sh
sudo systemctl restart delivery-mercure.service
```
