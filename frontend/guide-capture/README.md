# User-guide screenshots

Scripts that take the screenshots in `docs/user-guide/images/` from the real system, so the guide can be updated whenever the screens change. They drive Microsoft Edge with Playwright, on a sample shop ("Karen Wines & Spirits", branches Karen and Westlands) kept in its own database. Your development data is never touched.

## 1. Build the sample shop

Create the database once (`createdb -U postgres tessera_pos_guide`), then rebuild it before each capture run:

```bash
TESSERA_PHP=/path/to/php8.4 sh frontend/guide-capture/guide-db.sh
```

This runs the normal seeders, `DemoShowcaseSeeder` and `UserGuideSeeder` (two weeks of trading, made through the real services).

## 2. Serve it

The API runs on port 8011, pointed at the guide database. The web app is a production build on port 3011, built with `NEXT_PUBLIC_LOCAL_BACKEND_PORT=8011`:

```bash
cd backend && APP_ENV=local DB_DATABASE=tessera_pos_guide APP_URL=http://localhost:8011 FRONTEND_URL=http://localhost:3011 \
  SANCTUM_STATEFUL_DOMAINS=localhost:3011 CORS_ALLOWED_ORIGINS=http://localhost:3011 SESSION_COOKIE=tessera_guide_session \
  QUEUE_CONNECTION=sync MPESA_DRIVER=fake ETIMS_DRIVER=fake php artisan serve --host=127.0.0.1 --port=8011 --no-reload
cd frontend && NEXT_PUBLIC_LOCAL_BACKEND_PORT=8011 pnpm build && pnpm exec next start --port 3011
```

## 3. Capture

Run each chapter's script on a freshly built shop. The scripts make real sales and returns, so run them in order and rebuild the shop before starting again.

```bash
cd frontend
node guide-capture/chapter-02.mjs
```

In Git Bash, prefix with `MSYS_NO_PATHCONV=1` so route arguments are not turned into Windows paths. If a step fails, `docs/user-guide/images/_failure-*.png` shows every open window at that moment.

- `lib.mjs`: sign-in, numbered red markers `(1)`, `(2)`…, and screenshots (back office 1440×900, till 1366×768).
- `explore.mjs`: lists the buttons and fields on screens, to pick markers.
- `pdf.mjs`: prints the guide's HTML to PDF with page numbers (`docs/user-guide/tools/md2html.py` builds the HTML).
