#!/bin/bash
set -e

ln -sfn /etc/nginx/sites-available/driver.rawen.keenetic.pro /etc/nginx/sites-enabled/driver.rawen.keenetic.pro

python3 << 'PY'
from pathlib import Path
p = Path("/var/www/delivery.rawen.keenetic.pro/backend/.env.local")
text = p.read_text()
new_cors = "CORS_ALLOW_ORIGIN='^https?://(localhost|127\\.0\\.0\\.1|delivery\\.rawen\\.keenetic\\.pro|driver\\.rawen\\.keenetic\\.pro)(:[0-9]+)?$'"
lines = []
found = False
for line in text.splitlines():
    if line.startswith("CORS_ALLOW_ORIGIN="):
        lines.append(new_cors)
        found = True
    else:
        lines.append(line)
if not found:
    lines.append(new_cors)
p.write_text("\n".join(lines) + "\n")
print([l for l in lines if l.startswith("CORS")][0])
PY

nginx -t
systemctl reload nginx

echo "=== listening ==="
ss -tlnp | grep 8087 || true
echo "=== http checks ==="
curl -s -o /dev/null -w "root:%{http_code}\n" http://127.0.0.1:8087/
curl -s -o /dev/null -w "api:%{http_code}\n" -X POST http://127.0.0.1:8087/api/login -H "Content-Type: application/json" -d "{}"
echo "=== dist ==="
ls -la /var/www/delivery.rawen.keenetic.pro/driver_frontend/dist/
echo "=== site enabled ==="
ls -la /etc/nginx/sites-enabled/driver.rawen.keenetic.pro
