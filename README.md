# Delivery

Stack:
- `backend` — Symfony 7.x + PHP 8.4 + PostgreSQL 17 (native)
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

Enable PHP extensions in `php.ini` (`extension_dir = "ext"` + `extension=...` for the list above).

## Frontend

```bash
cd admin_frontend && npm install && npm run dev
cd driver_frontend && npm install && npm run dev
```

- Admin: http://localhost:1420/
- Driver: http://localhost:1430/

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
- `POST /api/admin/routes/import` — импорт PDF маршрута (`multipart/form-data`, поле `file`; только первая таблица; адреса проверяются через Google Geocoding)
- `DELETE /api/admin/routes/{id}` — удаление маршрута вместе с заказами

### Google Maps

В Google Cloud Console включите **Geocoding API** и **Directions API**, создайте API key.

Задайте ключ в `backend/.env.local`:

```
GOOGLE_MAPS_API_KEY=your_key_here
```

Ключ используется при импорте PDF-маршрутов (проверка адресов и расчёт времени/расстояния между остановками).