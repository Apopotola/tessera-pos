# User-guide screenshots

Scripts that take the screenshots in `docs/user-guide/images/` from the real system, so the guide can be updated whenever the screens change. They drive Microsoft Edge with Playwright, on a sample shop ("Spanish Wines & Spirits", branches Kobil Imara Daima and Westlands) kept in its own database. Your development data is never touched.

## 1. Build the sample shop

Create the database once (`createdb -U postgres tessera_pos_guide`), then rebuild it before each capture run:

```bash
TESSERA_PHP=/path/to/php8.4 sh frontend/guide-capture/guide-db.sh
```

This runs the normal seeders, `DemoShowcaseSeeder` and `DemoShopSeeder` (the full demo shop: about 90 items, suppliers, trade customers, two weeks of trading from `UserGuideSeeder`, and open paperwork, all made through the real services). It takes about three minutes. On Windows, `TESSERA_PHP` is Herd's `php84/php.exe` (XAMPP's PHP 8.2 is too old).

## 2. Serve it

The API runs on port 8011, pointed at the guide database. The web app is a production build on port 3011, built with `NEXT_PUBLIC_LOCAL_BACKEND_PORT=8011`:

```bash
cd backend && APP_ENV=local DB_DATABASE=tessera_pos_guide APP_URL=http://localhost:8011 FRONTEND_URL=http://localhost:3011 \
  SANCTUM_STATEFUL_DOMAINS=localhost:3011 CORS_ALLOWED_ORIGINS=http://localhost:3011 SESSION_COOKIE=tessera_guide_session \
  QUEUE_CONNECTION=sync MPESA_DRIVER=fake ETIMS_DRIVER=fake php artisan serve --host=127.0.0.1 --port=8011 --no-reload
cd frontend && NEXT_PUBLIC_LOCAL_BACKEND_PORT=8011 pnpm build && pnpm exec next start --port 3011
```

## 3. Capture

Run the scripts on a freshly built shop, in this order. The till scripts make real sales and returns, so rebuild the shop before starting again.

```bash
cd frontend
node guide-capture/chapter-02.mjs       # images/overview: one business day (Westlands)
node guide-capture/chapters-03-04.mjs   # images/till: the till at Kobil Imara Daima; sells a tot, ends locked
node guide-capture/chapters-05-12.mjs   # images/office: sign-in, layout and every back-office screen (read-only)
```

In Git Bash, prefix with `MSYS_NO_PATHCONV=1` so route arguments are not turned into Windows paths. If a step fails, `docs/user-guide/images/_failure-*.png` shows every open window at that moment.

- `lib.mjs`: sign-in, numbered red markers `(1)`, `(2)`…, and screenshots (back office 1440×900, till 1366×768).
- `explore.mjs`: lists the buttons and fields on screens, to pick markers.
- `pdf.mjs`: prints the guide's HTML to PDF with page numbers (`docs/user-guide/tools/md2html.py` builds the HTML).

## 4. Build the guide

```bash
cd docs/user-guide
python tools/md2html.py ../../frontend/guide-capture/.guide.html 0[1-9]-*.md 1[0-3]-*.md --cover --for="Spanish Wines & Spirits, Kobil Imara Daima"
node ../../frontend/guide-capture/pdf.mjs ../../frontend/guide-capture/.guide.html ../Tessera_POS_User_Guide.pdf
```

The Word copy needs neither Word nor extra packages: `tools/md2docx.py` writes it straight from the same chapters (pictures embedded; Word asks to update the table of contents when it is opened):

```bash
python tools/md2docx.py ../Tessera_POS_User_Guide.docx 0[1-9]-*.md 1[0-3]-*.md --for="Spanish Wines & Spirits, Kobil Imara Daima"
```

Python is not on the PATH on every machine: Laragon's is at `C:\laragon\bin\python\python-3.13\python.exe`.

## Client editions

A client can get the same guide under their own shop name, with one branch or two. An edition lives in `docs/user-guide/editions/<client>/`: `edition.json` (the shop's `GUIDE_*` settings and the sentences of the chapters that change) and its own `images/`. The Spanish Wines & Spirits guide above is the default and is not changed by an edition.

1. Build the shop in its own database with the edition's settings (an empty `GUIDE_SECOND_BRANCH` makes a one-branch shop: the transfer, delivery and stock count happen at the one branch, and chapter 2's cashier opens a second till there):

   ```bash
   createdb -U postgres tessera_pos_guide_epic
   GUIDE_DB=tessera_pos_guide_epic GUIDE_SHOP="Epic Lounge Wines & Spirits" GUIDE_BRANCH="Main Branch" GUIDE_ADDRESS="Nairobi, Kenya" \
     GUIDE_EMAIL="info@epiclounge.test" GUIDE_SECOND_BRANCH="" TESSERA_PHP=... sh frontend/guide-capture/guide-db.sh
   ```

2. Serve it as in step 2 (with `DB_DATABASE=tessera_pos_guide_epic`) and run the three capture scripts with the same `GUIDE_*` settings plus `GUIDE_IMAGES=../docs/user-guide/editions/epic-lounge/images`.
3. Build the guide from the edition's chapters:

   ```bash
   cd docs/user-guide
   python tools/edition.py editions/epic-lounge build/epic-lounge
   python tools/md2html.py ../../frontend/guide-capture/.guide.html build/epic-lounge/*.md --cover --for="Epic Lounge Wines & Spirits"
   node ../../frontend/guide-capture/pdf.mjs ../../frontend/guide-capture/.guide.html ../Epic_Lounge_User_Guide.pdf
   python tools/md2docx.py ../Epic_Lounge_User_Guide.docx build/epic-lounge/*.md --for="Epic Lounge Wines & Spirits"
   ```

`edition.py` stops if a sentence in `edition.json` is no longer in the chapters, so a reworded chapter is noticed.

`settings-demo.mjs` (read-only) takes the pictures for an edition's short Settings demo briefing (`editions/epic-lounge/settings-demo.md`, built to `docs/Epic_Lounge_Settings_Demo.pdf` with `md2html.py` without `--cover` and `pdf.mjs`).
