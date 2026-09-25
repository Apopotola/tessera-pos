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

## Checks

```bash
cd backend && php artisan test && vendor/bin/pint --test && composer validate
cd frontend && pnpm lint && pnpm typecheck && pnpm build
```

## Module status

| Module | Backend | Frontend view(s) |
| --- | --- | --- |
| Auth | Login / logout / me, throttling, audit | Login page, session bootstrap |
| Authorization | Permissions, 6 default roles, permission-filtered menus | Sidebar |
| Organisation | Business, branches, locations, branch access | Branches |
| AuditTrail | Append-only log (DB trigger), logger service, list API | Audit log |
| Dashboard | — | Dashboard (context only; KPIs come with Sales/Inventory) |
| Catalogue, Inventory, Purchasing, Sales, Payments, Customers, Compliance, Reports | Module skeleton | Placeholder views |
