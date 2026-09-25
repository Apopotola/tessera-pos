# Tessera POS — AI Coding Instructions

Tessera POS is a point of sale for Kenyan wines & spirits retail: a modular Laravel API (`backend/`) with a Next.js frontend (`frontend/`). The approved requirements live in `docs/Tessera-POS-Requirements-Analysis.pdf`; do not add scope beyond its MVP without asking.

## Coding Rules

- Maintain the existing project architecture and module boundaries.
- Inspect relevant controllers, services, models, migrations, routes, stores, API clients, and views before implementing new functionality.
- Prefer the smallest focused change that solves the root cause.
- Do not rename, remove, or change the type of database columns unless explicitly requested.
- Do not create unnecessary new files, duplicate helpers, or parallel abstractions.
- Match existing naming, namespace, import, response, validation, and error-handling conventions.
- Keep secrets, credentials, tokens, dumps, and local environment files out of commits.

## Backend Rules

- The backend is Laravel 12 on PHP 8.4 and uses `nwidart/laravel-modules` 12 (API-only modules; stubs in `backend/stubs/nwidart-stubs`).
- Create modules with `php artisan module:make Name`; keep module code inside `backend/Modules/<Name>/`.
- Module classes autoload from each module's own `composer.json` via `wikimedia/composer-merge-plugin`; do not add a root-level `Modules\\` PSR-4 mapping.
- Module routes are mounted at `/api/v1` by the module `RouteServiceProvider`; group them under the module's kebab-case prefix.
- Keep controllers thin: validate (Form Requests), authorize, delegate to a service, return the `App\Traits\ApiResponse` envelope (`success`, `message`, `statusCode`, `data` | `errors`).
- Permission names live only in `Modules\Authorization\Support\Permissions`; default roles in `Roles`. Maker–checker actions use `.request` / `.approve` permission pairs and must be enforced in services.
- Protect endpoints with `auth:sanctum` and check permissions server-side on every write and sensitive read.
- Add OpenAPI attributes (`OpenApi\Attributes`) when an API contract changes; regenerate with `php artisan l5-swagger:generate`.
- After changing module manifests or autoloaded classes, run `composer dump-autoload` and `php artisan package:discover`.

## Financial and Stock Integrity

- Sales, payments, stock movements and audit logs are **append-only**. Never update or delete a posted row; correct with a reversing document.
- Stock on hand is derived from the stock ledger; never edit a balance directly.
- Write the audit entry (`Modules\AuditTrail\Services\AuditLogger`) in the same DB transaction as the action it records.
- Store money as integers (cents) or `numeric`, never floats. Use `timestampTz` columns.
- eTIMS submissions go through an outbox table committed with the sale; never call KRA inline in a request.

## Database Rules

- PostgreSQL is the only supported database (jsonb, triggers). Tests run against the `tessera_pos_test` PostgreSQL database.
- Prefer additive, reversible migrations with indexes and explicit foreign-key behaviour.
- Use transactions for multi-step writes. Avoid N+1 queries (`Model::shouldBeStrict()` is on outside production); paginate list endpoints.

## Frontend Rules

- The frontend is Next.js 16 (App Router) with React 19, TypeScript, Mantine 8, Redux Toolkit, Axios, and Tabler Icons. No Tailwind or second UI kit.
- Read `frontend/AGENTS.md` first: Next.js 16 differs from older versions; its docs are in `frontend/node_modules/next/dist/docs/`.
- All HTTP goes through `@/api` (`api/client.ts`); all URLs come from `api/urls.ts`. Never call axios or build URLs in components.
- Sanctum uses cookies: open the frontend and API on the same hostname (`localhost`) in local dev.
- Screens are workspace views: register each `viewType` in `components/workspace/ViewRegistry.tsx`, and keep it identical to the `view_type` seeded in `AuthorizationDatabaseSeeder`. Tabs stay mounted; do not reset state on tab switch.
- Type every API payload and response in `types/`, mirroring the Laravel Resource. No `any` or unsafe casts.
- Use `useApiQuery` + `QueryState` for loading, error, forbidden and empty states; use `useAppDispatch`/`useAppSelector` for the store.
- Submit buttons show `loading` and ignore repeat clicks; map 422 errors with `form.setErrors(error.formErrors)`; confirm success with `notifications.show`.

## Testing and Validation

- Add focused tests for changed behaviour, especially authorization, maker–checker, ledger writes and API contracts. Module tests live in `backend/Modules/<Name>/tests/`.
- Backend: `php artisan test` (all suites), `vendor/bin/pint --test`, `composer validate`.
- Frontend: `pnpm lint`, `pnpm typecheck`, `pnpm build`.
- Do not claim a check passed unless it was run; report skipped checks and pre-existing failures separately.

## Documentation

- Update `README.md` or `docs/` when setup, API contracts, or conventions change.
