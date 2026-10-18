# Delivery

Stack:
- `backend` — Symfony 7.x + PHP 8.4 + PostgreSQL 17 (native)
- `realtime` — Node.js WebSocket hub (driver → admin live updates, port 8090)
- `admin_frontend` — Tauri + Vue 3 + Tailwind 4, UI на базе [Admin One](https://github.com/justboil/admin-one-vue-tailwind) (port 1420)
- `driver_frontend` — Tauri + Vue + Tailwind (port 1430)

## Prerequisites

- Node.js LTS
- PHP 8.4+ with extensions: `pdo_pgsql`, `pgsql`, `openssl`, `mbstring`, `curl`, `zip`, `intl`, `fileinfo`, `sodium`
- Composer 2
- PostgreSQL 17 (native)
- Rust (for `npm run tauri dev`)

### Windows install (winget)

```powershell
winget install --id PHP.PHP.8.4
# Composer: https://getcomposer.org/download/  (or composer.phar + composer.bat on PATH)
winget install --id PostgreSQL.PostgreSQL.17
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

## Realtime WebSocket hub

Driver mutations (`route start`, `order complete`, `order refuse`) are pushed to the admin UI over a real WebSocket.

```bash
cd realtime
npm install
npm start
```

- Hub: `ws://127.0.0.1:8090/ws` (HTTP publish on `http://127.0.0.1:8090`)
- Backend env (see `backend/.env.local.example`): `REALTIME_INTERNAL_URL`, `REALTIME_PUBLIC_WS_URL`, `REALTIME_SECRET`
- Hub must use the **same** `REALTIME_SECRET` (set via env when starting, or use the default `dev-realtime-secret-change-me`):

```powershell
$env:REALTIME_SECRET="dev-realtime-secret-change-me"; npm start
```

Keep this process running alongside the API while developing.

Desktop windows:

```bash
cd admin_frontend && npm run tauri dev
cd driver_frontend && npm run tauri dev
```

## Backend + Postgres

1. Copy env and set DB URL / Google key:

```bash
copy backend\.env.local.example backend\.env.local
```

Default DB URL in `.env.local`:

```
DATABASE_URL="postgresql://delivery:delivery@127.0.0.1:5432/delivery?serverVersion=17&charset=utf8"
```

2. Start Postgres (if the Windows service is not running):

```powershell
.\scripts\start-postgres.ps1
```

Create role/database once (as superuser `postgres`):

```sql
CREATE ROLE delivery LOGIN PASSWORD 'delivery' CREATEDB;
CREATE DATABASE delivery OWNER delivery;
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

- API: http://127.0.0.1:8080/
- Postgres: `localhost:5432` / user `delivery` / password `delivery` / db `delivery`

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
- `GET /api/driver/routes` — маршруты текущего курьера
- `GET /api/driver/routes/{id}` — детали назначенного маршрута

### Email

В `backend/.env.local` задайте SMTP (или оставьте `null://null` для локальной разработки без доставки):

```
MAILER_DSN=smtp://user:pass@smtp.example.com:587
MAIL_FROM=noreply@your-domain.com
```

Для локальной проверки удобен [Mailpit](https://github.com/axllent/mailpit): `MAILER_DSN=smtp://localhost:1025`.

Тест: админ-панель → **Email** → «Send test email».

### Google Maps

В Google Cloud Console включите **Geocoding API**, **Directions API** и **Places API**, создайте API key.

Задайте ключ в `backend/.env.local`:

```
GOOGLE_MAPS_API_KEY=your_key_here
```

Ключ используется при импорте PDF-маршрутов (проверка адресов и расчёт времени/расстояния между остановками), а также для автозаполнения и валидации адресов складов.