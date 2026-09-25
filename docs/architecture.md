# Architecture

## Request flow

```
Browser (localhost:3010)
  └─ Next.js workspace view
       └─ @/api client (Axios, withCredentials, X-XSRF-TOKEN)
            └─ Laravel /api/v1/<module>/…  (Sanctum stateful session)
                 └─ Controller → Form Request → Service → Model / DB transaction
                      └─ AuditLogger (same transaction)
```

1. `GET /sanctum/csrf-cookie` sets `XSRF-TOKEN`.
2. `POST /api/v1/auth/login` starts a session (only for origins in `SANCTUM_STATEFUL_DOMAINS`).
3. `GET /api/v1/auth/me` and `GET /api/v1/authorization/menus` bootstrap the shell.

## Response envelope

Every endpoint returns `App\Traits\ApiResponse`:

```json
{ "success": true, "message": "…", "statusCode": 200, "data": {} }
{ "success": false, "message": "…", "statusCode": 422, "errors": { "field": ["…"] } }
```

`bootstrap/app.php` renders validation, authentication, authorization and not-found exceptions in the same shape. The frontend types it in `types/api.ts` and unwraps `data` in `api/client.ts`.

## Backend modules

| Module | Owns |
| --- | --- |
| Auth | Users, login/logout, session |
| Authorization | Permissions catalogue, roles, menus (`view_type`) |
| Organisation | Business, branches, stock locations, user–branch access |
| AuditTrail | Append-only `audit_logs`, `AuditLogger` |
| Catalogue | Brands, products, variants (pack sizes), barcodes, prices |
| Inventory | Stock ledger, balances, adjustments, counts, transfers |
| Purchasing | Suppliers, POs, GRNs, supplier invoices |
| Sales | Till sales, tenders, returns, shifts, cash-ups |
| Payments | M-PESA (Daraja) integration and reconciliation |
| Customers | Walk-in default, wholesale / B2B customers |
| Compliance | eTIMS outbox, adapter (OSCU/VSCU), reconciliation |
| Reports | Read models and exports |
| Dashboard | KPI endpoints |

Dependency direction: feature modules may depend on Auth, Authorization, Organisation and AuditTrail — not the reverse.

### Adding a module

```bash
cd backend
php artisan module:make Loyalty
composer dump-autoload
```

The generator (custom stubs in `stubs/nwidart-stubs`) creates an API-only module whose routes mount at `/api/v1`.

## Frontend workspace

- Menus come from the API. Each leaf carries a `viewType` and `path`.
- `components/workspace/ViewRegistry.tsx` maps `viewType` to a lazily loaded screen. Unknown types fall back to `PlaceholderView`.
- `store/slices/tabsSlice.ts` holds open tabs (max 8). `WorkspaceTabRenderer` keeps every tab mounted and hides inactive ones.
- `WorkspaceUrlSync` maps the URL to a tab (deep links, reload, back/forward) and the active tab back to the URL.

### Adding a screen

1. Add the menu item with its `view_type` and `permission` in `AuthorizationDatabaseSeeder`, then re-seed.
2. Create the view in `frontend/modules/<module>/views/`, typed with `WorkspaceViewProps`.
3. Register the same `viewType` in `ViewRegistry.tsx`.
4. Add the endpoint to `api/urls.ts` and an endpoint module in `api/endpoints/`, with types in `types/`.

## Integrity rules

- Posted sales, payments, stock movements and audit rows are never updated or deleted; `audit_logs` enforces this with a PostgreSQL trigger.
- Maker–checker: request and approval are different permissions and must be different users.
- eTIMS: the sale and its outbox row commit together; a queue worker submits to KRA with idempotent retries.
