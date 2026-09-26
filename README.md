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
| Tessera Support (all settings, incl. KRA PIN and eTIMS) | `support@tessera.test` / `password` | — |

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
| Sales | Till selling priced from the approved price list (idempotent on the till's sale id), cash / M-PESA code / card / split tenders, manager-PIN approvals (price override, discount above limit, high-value void, refund), returns by receipt (sealed → shop floor, opened → quarantine), void log, shift cash-up with expected cash, sales immutable in the database; sell by tot (open-bottle tracking with append-only pours and manager write-off); park / recall sales | Till: PIN screen, selling screen with tot buttons, park/recall, 80 mm receipt printing, returns/reprint; back office: Sales list with receipt detail, Shifts & cash-ups, Open bottles (tots) |
| AuditTrail | Append-only log (DB trigger), logger service, list API | Audit log |
| Catalogue | Brands, categories, products, size variants, packs, barcodes, barcode lookup, dated retail/wholesale prices with owner approval (append-only, DB trigger) | Products, product detail, price changes, brands & categories |
| Dashboard | Today's sales, takings by method and gross profit; stock and purchasing alerts | Dashboard |
| Inventory | Append-only stock ledger (DB trigger), balances, weighted average cost per branch, adjustments (breakage, losses, found, opening) with maker–checker, transfers via in-transit with shortfall → transit breakage, blind counts, reorder levels | Stock on hand, breakages & adjustments, transfers, stock counts + count sheet, stock ledger |
| Purchasing | Suppliers (last cost per item), purchase orders with VAT and maker–checker approval, goods received into stock at PO cost (damaged-on-arrival kept out), supplier invoices with three-way match, returns to supplier with credit note | Purchase orders + PO detail, supplier invoices, returns to supplier, suppliers |
| Payments | M-PESA Express (STK Push) with Daraja callback + status query, customer-initiated Till/Paybill (C2B) payments pooled until matched, M-PESA tender confirmed only by a Safaricom confirmation, back-office matching of typed codes; drivers `daraja`, `fake` (demo), `manual` | Till: send payment request / pick customer's payment; M-PESA reconciliation screen |
| Compliance | eTIMS transactional outbox (every sale and return queued in its own transaction), credit notes referencing the signed invoice, retry back-off 1→30 min, refused data held as "needs fixing", daily POS vs KRA-signed reconciliation, dashboard alerts; drivers `fake` (mock KRA) and `disabled` — VSCU/OSCU driver pending the KRA v2.0 spec | eTIMS monitor (invoices & credit notes, daily check); receipts print KRA invoice no., signature and QR |
| Reports | 14 reports off the immutable ledgers (sales summary, by item/product/category/brand, by cashier/branch/tender, voids-discounts-overrides, returns; stock on hand, valuation as at a date, losses by reason, count variance, transfers, low stock; gross profit, cash-ups, purchases by supplier) with branch/date/category/brand/staff filters, cost columns hidden without `reports.profit.view`, CSV export gated by `reports.export` and audit-logged | Report centre (grouped catalogue incl. links to existing screens) + one report tab per report with totals, Print / PDF and CSV |
| Settings | One registry of ~75 settings in 9 sections; scoped values (till → branch → business → default); admin levels (Tessera support / owner / branch manager); every change logged with history and undo; industry presets that keep the owner's own changes; encrypted secrets; branding uploads; locked-after-first-use rules | Settings screen; live branding (colours, logo, favicon, login page); settings drive the till, receipts, approvals, stock, payments, eTIMS and dashboard |
| Customers | Registered wholesale / B2B customers (business name, KRA PIN, wholesale flag); wholesale price tier and buyer PIN on the eTIMS invoice when picked at the till; purchase history; contact details for managers only, record views logged; Owner/Admin data export and anonymisation (sales kept); retention job anonymises customers inactive 24 months | Customers list + customer tab (history, export, anonymise); till customer picker |

## M-PESA (Payments module)

`MPESA_DRIVER` in `backend/.env` picks how M-PESA works:

| Driver | Use | Behaviour |
| --- | --- | --- |
| `fake` | Demo / local development | Payment requests are "approved" after 5 seconds (`MPESA_FAKE_DELAY`); a phone ending in `000` declines. The till shows a **Demo: simulate customer paying** button. No money moves. |
| `daraja` | Sandbox or live Safaricom | Needs the `MPESA_*` credentials in `.env.example`, a public HTTPS `MPESA_CALLBACK_BASE_URL` (ngrok in development) and a long random `MPESA_CALLBACK_TOKEN`. Run `php artisan mpesa:register-c2b` once per till/paybill. |
| `manual` | No integration yet | Cashier types the M-PESA code; the payment stays *unverified* until matched on the reconciliation screen. |

Tests always run with `manual` (set in `phpunit.xml`); the Payments tests switch drivers themselves.

## eTIMS (Compliance module)

`ETIMS_DRIVER` in `backend/.env`:

| Driver | Behaviour |
| --- | --- |
| `fake` | Mock KRA for demos: signs invoices (CU invoice number, signature, QR), refuses items with no KRA item class code, and `ETIMS_FAKE_OFFLINE=true` simulates KRA being unreachable. Receipts say **DEMO eTIMS — NOT A KRA INVOICE**. |
| `disabled` | Invoices are queued but never sent; they stay *pending* (never shown as compliant). |

Each sale is sent right after it commits. Retries and anything missed run from the scheduler — in development keep this running next to the server:

```bash
php artisan schedule:work
```

`php artisan etims:process` sends everything due immediately. The real VSCU/OSCU driver implements `Modules\Compliance\Contracts\EtimsGateway` once the KRA v2.0 spec and sandbox access (etims-sbx.kra.go.ke) are available; KRA field names in `EtimsPayloadBuilder` are marked REQUIRES VALIDATION.

The PostgreSQL session timezone is set to `APP_TIMEZONE` (config/database.php) so timestamps written by Laravel and by Postgres agree.

## Offline till

When the connection drops the till keeps selling **cash and card** from a copy of the catalogue saved on the device (IndexedDB): prices, barcodes, shelf stock and registered customers, refreshed every 10 minutes while online.

- Offline sales print a receipt with a provisional number (`OFFLINE-<till>-0001`) marked *recorded offline*; the official number and the eTIMS invoice follow when the sale reaches the server.
- Queued sales are sent in order as soon as the connection is back. The server keeps the time of sale, prices the sale as it was then, and records it once even if it is sent twice (`clientId`). Refused sales stay on the till as *needs attention* for a manager.
- Offline, these wait for the connection: M-PESA, manager approvals (overrides, big discounts, large voids, refunds), returns, parking, locking and ending the shift. A shift cannot end while its offline sales are still sending.
- A cashier already signed in when the connection dropped can keep selling, even after reloading the page (production builds cache the till page with `public/sw.js`). Signing in a new cashier needs the connection.
- Offline sales older than `SALES_OFFLINE_MAX_HOURS` (default 72, REQUIRES VALIDATION against KRA/VSCU rules) are refused on sync.

## Cash control

- **Cash drops:** during a shift the cashier moves excess notes to the safe from the till menu (*Cash drop to safe*); a manager witnesses it with their PIN. A drop can never exceed what the drawer should hold, and drops are append-only.
- **Closing:** a blind count by note and coin (KES 1,000 … KES 1). Expected cash = opening float + cash sales − cash refunds − drops to the safe.
- **Differences:** if the count is over or short, the cashier must say why before signing out.
- **Sign-off:** every closed cash-up waits for a manager in *Sales & Shifts → Shifts & cash-ups* (the dashboard shows how many). A difference needs the manager's note, and nobody can sign off their own cash-up.

## Settings

**Administration → Settings** (owner, admin, branch manager; Tessera support for platform items). The registry in `backend/Modules/Settings/app/Support/SettingsRegistry.php` defines every setting: its screen, type, default, who may change it and at which scopes. The screen and the API validation are generated from it.

- **Scopes:** a value set on a till beats its branch, which beats the business, which beats the default. Branch managers change their own branch and tills.
- **History and undo:** every change is logged (`setting_changes`, append-only) and written to the audit trail; secrets are stored encrypted and shown as `••••last4`.
- **Presets:** applying an industry preset previews the changes and keeps the owner's own changes unless they tick *Also replace my own changes*.
- **Branding:** primary and accent colours (custom colours must keep white text readable), logo, receipt logo, app icon, login page style, background and welcome text apply live.
- **What they drive:** the till gets its rules through `GET /organisation/till-context` (`Modules\Sales\Services\TillPolicy`) and the API re-checks them on every sale: invoice prefix (locked after the first sale), discount limit per role, approvals (price change, big discounts, removing items, refunds), selling below zero stock, accepted payment methods and order, split payments, cash rounding (`sales.rounding_cents`), STK Push on/off, eTIMS per branch (sales outside eTIMS are `not_required`), sell by tot, categories sold per branch, favourites, layout, touch mode, quick buttons, age check, receipt layout and printing, blind cash-up, allowed cash variance, low-stock default level, password length and dashboard tiles per role.
- Settings for features not built yet are shown with **Coming later** and change nothing.
