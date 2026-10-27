#!/bin/bash
set -euo pipefail
APP=/var/www/delivery.rawen.keenetic.pro
cd "$APP"

echo "=== 1. backend .env.local mercure/mail ==="
python3 - <<'PY'
from pathlib import Path
import secrets
p = Path("/var/www/delivery.rawen.keenetic.pro/backend/.env.local")
text = p.read_text(encoding="utf-8-sig")
lines = text.splitlines()
kv = {}
order = []
for line in lines:
    if not line.strip() or line.strip().startswith("#") or "=" not in line:
        order.append(("raw", line))
        continue
    k, v = line.split("=", 1)
    kv[k] = v
    order.append(("kv", k))

secret = kv.get("MERCURE_JWT_SECRET") or kv.get("REALTIME_SECRET")
if not secret or secret.strip("'\"") in ("", "dev-realtime-secret-change-me", "!ChangeThisMercureHubJWTSecretKey!"):
    secret = f'"{secrets.token_hex(32)}"'
elif not (secret.startswith('"') or secret.startswith("'")):
    secret = f'"{secret}"'

updates = {
    "MERCURE_URL": "http://127.0.0.1:3000/.well-known/mercure",
    "MERCURE_PUBLIC_URL": "http://delivery.rawen.keenetic.pro:8086/.well-known/mercure",
    "MERCURE_JWT_SECRET": secret,
    "MAIL_FROM": kv.get("MAIL_FROM", "noreply@delivery.rawen.keenetic.pro"),
}
# Drop legacy websocket keys
drop = {"REALTIME_INTERNAL_URL", "REALTIME_PUBLIC_WS_URL", "REALTIME_SECRET", "MERCURE_JWT_ISSUER"}
seen = set()
out = []
for kind, val in order:
    if kind == "raw":
        out.append(val)
        continue
    k = val
    if k in drop:
        continue
    if k in updates:
        out.append(f"{k}={updates[k]}")
        seen.add(k)
    else:
        out.append(f"{k}={kv[k]}")
        seen.add(k)
for k, v in updates.items():
    if k not in seen:
        out.append(f"{k}={v}")
p.write_text("\n".join(out) + "\n", encoding="utf-8")
print("updated keys:", ", ".join(updates))
PY

echo "=== 2. composer + migrate + cache ==="
cd "$APP/backend"
composer install --no-dev --optimize-autoloader --no-interaction
mkdir -p var/uploads
sudo chown -R serg:www-data var
sudo chmod -R ug+rwX var
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console cache:clear --env=prod --no-warmup
php bin/console cache:warmup --env=prod

echo "=== 3. build admin frontend ==="
cd "$APP/admin_frontend"
npm install --no-fund --no-audit
npm run build

echo "=== 4. build driver frontend ==="
cd "$APP/driver_frontend"
npm install --no-fund --no-audit
npm run build

echo "=== 5. Mercure hub (binary) ==="
cd "$APP"
bash scripts/download-mercure.sh
python3 - <<'PY'
from pathlib import Path
secret = None
for line in Path("/var/www/delivery.rawen.keenetic.pro/backend/.env.local").read_text(encoding="utf-8-sig").splitlines():
    if line.startswith("MERCURE_JWT_SECRET="):
        secret = line.split("=", 1)[1].strip().strip("'\"")
if not secret:
    raise SystemExit("MERCURE_JWT_SECRET missing")
Path("/var/www/delivery.rawen.keenetic.pro/mercure/.env").write_text(
    "SERVER_NAME=http://127.0.0.1:3000\n"
    f"MERCURE_PUBLISHER_JWT_KEY={secret}\n"
    f"MERCURE_SUBSCRIBER_JWT_KEY={secret}\n"
    "MERCURE_PUBLISHER_JWT_ALG=HS256\n"
    "MERCURE_SUBSCRIBER_JWT_ALG=HS256\n"
    "MERCURE_EXTRA_DIRECTIVES=cors_origins *\n"
)
print("mercure/.env written")
PY
chmod 600 "$APP/mercure/.env"
chown serg:serg "$APP/mercure/.env" "$APP/mercure/mercure" 2>/dev/null || true

sudo tee /etc/systemd/system/delivery-mercure.service >/dev/null <<'UNIT'
[Unit]
Description=Delivery Mercure hub
After=network.target

[Service]
Type=simple
User=serg
WorkingDirectory=/var/www/delivery.rawen.keenetic.pro/mercure
EnvironmentFile=/var/www/delivery.rawen.keenetic.pro/mercure/.env
ExecStart=/var/www/delivery.rawen.keenetic.pro/mercure/mercure run --config Caddyfile
Restart=always
RestartSec=3

[Install]
WantedBy=multi-user.target
UNIT
sudo systemctl daemon-reload
sudo systemctl enable --now delivery-mercure.service
sudo systemctl restart delivery-mercure.service
sleep 1
systemctl is-active delivery-mercure.service

# Stop legacy hubs if present
sudo systemctl disable --now delivery-realtime.service 2>/dev/null || true
docker compose -f "$APP/backend/compose.prod.yaml" down 2>/dev/null || true

echo "=== 6. nginx /.well-known/mercure proxy ==="
python3 - <<'PY'
from pathlib import Path
snippet = """
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
"""
for name in ("delivery.rawen.keenetic.pro", "driver.rawen.keenetic.pro"):
    p = Path(f"/etc/nginx/sites-available/{name}")
    text = p.read_text()
    if "location /.well-known/mercure" in text:
        print(f"{name}: mercure already present")
        Path("/tmp/" + name + ".nginx").write_text(text)
        continue
    # Prefer inserting before SPA fallback; also strip old /ws block if present
    import re
    text = re.sub(r"\n\s*location /ws \{.*?\n\s*\}\n", "\n", text, count=1, flags=re.S)
    marker = "    location / {"
    if marker not in text:
        raise SystemExit(f"marker not found in {name}")
    text = text.replace(marker, snippet + "\n" + marker, 1)
    Path("/tmp/" + name + ".nginx").write_text(text)
    print(f"{name}: prepared")
PY
sudo cp /tmp/delivery.rawen.keenetic.pro.nginx /etc/nginx/sites-available/delivery.rawen.keenetic.pro
sudo cp /tmp/driver.rawen.keenetic.pro.nginx /etc/nginx/sites-available/driver.rawen.keenetic.pro
sudo nginx -t
sudo systemctl reload nginx

echo "=== 7. smoke checks ==="
curl -s -o /dev/null -w "admin:%{http_code}\n" http://127.0.0.1:8086/
curl -s -o /dev/null -w "driver:%{http_code}\n" http://127.0.0.1:8087/
curl -s -o /dev/null -w "api-login:%{http_code}\n" -X POST http://127.0.0.1:8086/api/login -H "Content-Type: application/json" -d '{}'
curl -s -o /dev/null -w "mercure:%{http_code}\n" "http://127.0.0.1:3000/.well-known/mercure"
ss -tlnp | grep -E '8086|8087|3000' || true
systemctl --no-pager -l status delivery-mercure.service | head -20
echo DONE
