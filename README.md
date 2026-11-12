# Delivery

Stack:
- `backend` — Symfony 7.x + PHP 8.4 + MySQL 8 (native)
- `mercure` — official Mercure hub binary (driver → admin live updates, port 3000)
- `mailpit` — local SMTP catcher for email tests (SMTP 1025, UI 8025)
- `admin_frontend` — Tauri + Vue 3 + Tailwind 4, UI на базе [Admin One](https://github.com/justboil/admin-one-vue-tailwind) (port 1420)
- `driver_frontend` — Tauri + Vue + Tailwind (port 1430); Android APK can self-update via `/api/driver/app-version` + `/api/driver/app.apk`

## Prerequisites

- Node.js LTS
- PHP 8.4+ with extensions: `pdo_mysql`, `mysqli`, `openssl`, `mbstring`, `curl`, `zip`, `intl`, `fileinfo`, `sodium`
- Composer 2
- MySQL 8 (native)
- Rust (for `npm run tauri dev`)

### Ubuntu local-dev installer

On a clean (or existing) Ubuntu machine, download and run the bootstrap script. It creates a folder **next to the script**, clones the repo from GitHub, installs packages, asks for settings (`GOOGLE_MAPS_API_KEY`, DB, optional super-admin), then can start all dev processes:

```bash
curl -fsSL -o install-dev-ubuntu.sh \
  https://raw.githubusercontent.com/gciciu/disposition/main/scripts/install-dev-ubuntu.sh
chmod +x install-dev-ubuntu.sh
./install-dev-ubuntu.sh
```

From an existing checkout (no clone):

```bash
./scripts/install-dev-ubuntu.sh --inplace
```

Later starts (from the folder where you ran the installer — project lives in a sibling folder such as `./delivery/`):

```bash
./start-dev.sh
# or, from inside the project:
./scripts/start-dev.sh
```

- Admin: http://127.0.0.1:1420/
- Driver: http://127.0.0.1:1430/
- API: http://127.0.0.1:8080/
- Mercure: http://127.0.0.1:3000/.well-known/mercure
- Mailpit: http://127.0.0.1:8025/

### Windows install (winget)

```powershell
winget install --id PHP.PHP.8.4
# Composer: https://getcomposer.org/download/  (or composer.phar + composer.bat on PATH)
winget install --id Oracle.MySQL
```

Then enable PHP extensions, OpenSSL config, and JWT keys:

```powershell
.\scripts\setup-php-windows.ps1
```

This writes a user `php.ini` (`%LOCALAPPDATA%\PHP\8.4`), sets `PHPRC` / `OPENSSL_CONF`, and creates `backend/config/jwt/*.pem`. Open a new terminal afterwards.

## Frontend

```bash
cd admin_frontend && npm install && npm run dev
cd driver_frontend && npm install && npm run dev
```

- Admin: http://localhost:1420/
- Driver: http://localhost:1430/

## Mercure hub (realtime)

Driver mutations (`route start`, `order complete`, `order refuse`) are pushed to the admin UI over Mercure (Server-Sent Events).

```powershell
.\scripts\start-mercure.ps1
```

First run downloads the binary into `mercure/` and creates `mercure/.env` from `.env.example`.

- Hub: `http://127.0.0.1:3000/.well-known/mercure`
- Backend env (see `backend/.env.local.example`): `MERCURE_URL`, `MERCURE_PUBLIC_URL`, `MERCURE_JWT_SECRET`
- JWT secret must match `MERCURE_PUBLISHER_JWT_KEY` / `MERCURE_SUBSCRIBER_JWT_KEY` in `mercure/.env`

Keep this process running alongside the API while developing.

Desktop windows:

```bash
cd admin_frontend && npm run tauri dev
cd driver_frontend && npm run tauri dev
```

## Backend + MySQL

1. Copy env and set DB URL / Google key:

```bash
copy backend\.env.local.example backend\.env.local
```

Default DB URL in `.env.local`:

```
DATABASE_URL="mysql://delivery:delivery@127.0.0.1:3306/delivery?serverVersion=8.4.9&charset=utf8mb4"
```

2. Start MySQL (if the Windows service is not running):

```powershell
.\scripts\start-mysql.ps1
```

Create user/database once (as MySQL root):

```sql
CREATE DATABASE delivery CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER 'delivery'@'localhost' IDENTIFIED BY 'delivery';
GRANT ALL PRIVILEGES ON delivery.* TO 'delivery'@'localhost';
FLUSH PRIVILEGES;
```

3. Install PHP deps, migrate, seed, serve:

```bash
cd backend
composer install
# Windows: also run ..\scripts\setup-php-windows.ps1 once (extensions + JWT keys)
php bin/console doctrine:migrations:migrate --no-interaction
php bin/console doctrine:fixtures:load --no-interaction
php -S 127.0.0.1:8080 -t public
```

If login immediately shows **Your session has expired**, JWT keys on that machine are missing or out of sync with `JWT_PASSPHRASE`. Keys live in `backend/config/jwt/*.pem` (not in git) and must be generated on every new machine.

Windows:

```powershell
cd backend
php bin/console lexik:jwt:generate-keypair --overwrite --no-interaction
php bin/console lexik:jwt:check-config
# or: ..\scripts\setup-php-windows.ps1
```

Ubuntu / Linux:

```bash
cd backend
mkdir -p config/jwt
php bin/console lexik:jwt:generate-keypair --overwrite --no-interaction
php bin/console lexik:jwt:check-config
# if OpenSSL fails: php ../scripts/generate-jwt-keys.php --overwrite
```

- API: http://127.0.0.1:8080/
- MySQL: `localhost:3306` / user `delivery` / password `delivery` / db `delivery`

## Auth (users & roles)

Roles:
- `ROLE_SUPER_ADMIN` — Administrator
- `ROLE_ADMIN` — админ
- `ROLE_COURIER` — курьер

Admin panel (`admin_frontend`) allows only `ROLE_SUPER_ADMIN` and `ROLE_ADMIN`.

Seed users:

| Role | Email | Password |
|------|-------|----------|
| Administrator | `superadmin@delivery.local` | `SuperAdmin123!` |
| Админ | `admin@delivery.local` | `Admin123!` |
| Курьер | `courier@delivery.local` | `Courier123!` |

Create / update a super admin manually:

```bash
cd backend
php bin/console app:user:create-super-admin email@example.com secretPassword
php bin/console app:user:create-super-admin email@example.com secretPassword --name="Имя" --force
```

API:
- `POST /api/login` — `{ "email", "password" }` → JWT
- `GET /api/me` — текущий пользователь (Bearer token)
- `^/api/admin` — только админы
- `GET /api/admin/routes` — список маршрутов
- `GET /api/admin/routes/{id}` — маршрут с заказами
- `PUT /api/admin/routes/{id}` — обновить маршрут (`{ "courierId"?, "warehouseId"? }`; при смене склада пересчёт времени/дистанции)
- `POST /api/admin/routes/import` — импорт PDF маршрута (`multipart/form-data`, поле `file`; только первая таблица; адреса проверяются через Google Geocoding; склад по умолчанию)
- `DELETE /api/admin/routes/{id}` — удаление маршрута вместе с заказами
- `GET /api/admin/warehouses` — список складов
- `POST /api/admin/warehouses` — создать склад (`{ "name", "address", "placeId?" }`)
- `GET /api/admin/warehouses/{id}` — склад
- `PUT /api/admin/warehouses/{id}` — обновить склад
- `DELETE /api/admin/warehouses/{id}` — удалить склад
- `GET /api/admin/maps/autocomplete?input=…` — подсказки адресов Google Places
- `POST /api/admin/email/test` — тестовая отправка email (`{ "to"? }`; без `to` — email текущего админа)
- `POST /api/admin/email/test-confirmation` — тестовая Lieferbestätigung с demo-PDF (`{ "to"? }`)
- `GET /api/driver/routes` — маршруты текущего курьера
- `GET /api/driver/routes/{id}` — детали назначенного маршрута

### Email (Mailpit)

```powershell
.\scripts\start-mailpit.ps1
```

First run downloads the binary into `mailpit/`. Keep it running alongside the API.

In `backend/.env.local`:

```
MAILER_DSN=smtp://127.0.0.1:1025
MAIL_FROM=noreply@delivery.local
```

- SMTP: `127.0.0.1:1025`
- UI: http://127.0.0.1:8025/
- Test: admin → **Email** → «Send test email»

For production set a real `smtp://user:pass@host:587` and `MAIL_FROM`. Locally you can use `null://null` to discard mail without Mailpit.

### Google Maps

В Google Cloud Console включите **Geocoding API**, **Directions API** и **Places API**, создайте API key.

Задайте ключ в `backend/.env.local`:

```
GOOGLE_MAPS_API_KEY=your_key_here
```

Ключ используется при импорте PDF-маршрутов (проверка адресов и расчёт времени/расстояния между остановками), а также для автозаполнения и валидации адресов складов.