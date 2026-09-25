# Tessera POS

Point of sale for Kenyan wines & spirits retail: bottle-size variants, an append-only stock ledger, M-PESA reconciliation and KRA eTIMS integration.

- Requirements (approved): [docs/Tessera-POS-Requirements-Analysis.pdf](docs/Tessera-POS-Requirements-Analysis.pdf)
- Conventions for contributors and AI assistants: [CLAUDE.md](CLAUDE.md)
- Architecture notes: [docs/architecture.md](docs/architecture.md)

## Stack

| Layer | Technology |
| --- | --- |
| API | Laravel 12, PHP 8.4, `nwidart/laravel-modules` 12, Sanctum (SPA cookies), spatie/laravel-permission, L5-Swagger |
| Web | Next.js 16 (App Router), React 19, TypeScript, Mantine 8, Redux Toolkit, Axios, Tabler Icons |
| Data | PostgreSQL 16, Redis (queues, later) |

## Repository layout

```
backend/            Laravel API
  app/              Cross-cutting code (ApiResponse, OpenAPI root, providers)
  Modules/          One folder per business module (API-only)
  stubs/            Customised module generator stubs
frontend/           Next.js app
  api/              Axios client, URL constants, endpoint modules
  app/              Routes: (auth)/login, (content)/[...slug] workspace
  components/       Layout, auth guard, workspace (tabs + ViewRegistry), shared UI
  modules/          Feature screens grouped by backend module
  store/            Redux Toolkit store and slices
  types/            API contracts mirroring Laravel Resources
docs/               Requirements and architecture
compose.dev.yaml    Local PostgreSQL + Redis (optional)
```

## Local development

Default ports are **3010** (web) and **8010** (API), so Tessera runs alongside NAMRIMS on 3000/8000.

### 1. Database

Use a local PostgreSQL 16, or start one with Docker:

```bash
docker compose -f compose.dev.yaml up -d
```

Create the databases `tessera_pos` and `tessera_pos_test` if your server does not have them.

### 2. Backend

```bash
cd backend
cp .env.example .env        # then set DB_PASSWORD
composer install
php artisan key:generate
php artisan migrate --seed
php artisan serve   # 127.0.0.1:8010 (SERVER_HOST/SERVER_PORT in .env)
```

Seeding creates the MAIN branch, the six default roles, the navigation menu and a local Owner account (`owner@tessera.test` / `password`, local and testing only). Outside local, set `TESSERA_OWNER_EMAIL` and `TESSERA_OWNER_PASSWORD` before seeding.

API docs: `php artisan l5-swagger:generate`, then open http://localhost:8010/api/documentation.

### 3. Frontend

```bash
cd frontend
cp .env.example .env.local  # optional; defaults work locally
pnpm install
pnpm dev
```

Open **http://localhost:3010** (use `localhost`, not `127.0.0.1`, so the Sanctum cookie is shared with the API).

### Local demo accounts (created by `migrate --seed` when `APP_ENV=local`)

| Person | Sign in | Till PIN |
| --- | --- | --- |
| Business Owner | `owner@tessera.test` / `password` | 1470 |
| Wanjiru Mwangi (Branch Manager) | `manager@tessera.test` / `password` | 4826 |
| Otieno Kamau (Cashier) | till only | 2580 |
| Amina Hassan (Cashier) | till only | 3691 |
| Njeri Wambui (Storekeeper) | `njeri@tessera.test` / `password` | — |

### Setting up a till

1. On the till device, open **http://localhost:3010/till** and choose **Manager sign in**.
2. Sign in as the owner or a manager with branch-management rights, then name the till (e.g. *Till 1 · Main counter*) and its opening float.
3. The manager is signed out and the device shows **Who's on the till?**. Cashiers tap their name, enter their PIN and start their shift.

Lost or replaced device: **Administration → Branches → Tills → Disconnect**. Staff and PINs: **Administration → Users & roles**.

## Checks

```bash
cd backend && php artisan test && vendor/bin/pint --test && composer validate
cd frontend && pnpm lint && pnpm typecheck && pnpm build
```

## Module status

| Module | Backend | Frontend view(s) |
| --- | --- | --- |
| Auth | Sign-in by email or phone, "keep me signed in", till PIN sign-in, staff accounts and PINs, throttling, audit | Login page, Users & roles |
| Authorization | Permissions, 6 default roles, permission-filtered menus | Sidebar |
| Organisation | Business, branches, locations, branch access, till devices (pair / disconnect) | Branches & tills, till setup |
| Sales | Shifts: open / resume / blind close (selling comes next) | Till: PIN screen and open-shift screen |
| AuditTrail | Append-only log (DB trigger), logger service, list API | Audit log |
| Catalogue | Brands, categories, products, size variants, packs, barcodes, barcode lookup, dated retail/wholesale prices with owner approval (append-only, DB trigger) | Products, product detail, price changes, brands & categories |
| Dashboard | — | Dashboard (context only; KPIs come with Sales/Inventory) |
| Inventory, Purchasing, Payments, Customers, Compliance, Reports | Module skeleton | Placeholder views |
