# Go-live checklist: from the demo shop to a real shop

Use this to switch a hosted Tessera POS (for example `pos.tesseratech.co.ke`) from the demo shop to a real one. Allow about 30 minutes.

**Why the reset is needed.** With `TESSERA_DEMO=true`, the first start fills the database with the *Spanish Wines & Spirits* demo: fake products, two weeks of fake sales and cash-ups, demo staff with public passwords and PINs, a simulated M-PESA, and eTIMS switched off.

Sales, payments, stock movements and audit entries can never be edited or deleted. So the demo data cannot be cleaned up afterwards: the database must be **emptied before the first real sale**.

`docker/entrypoint.sh` seeds the database only when it has no users. Once you empty it, the next start builds a clean shop by itself, with:

- the roles and menus,
- one branch, **MAIN · Main Branch**,
- the shop's owner account, taken from `TESSERA_OWNER_EMAIL` and `TESSERA_OWNER_PASSWORD`.

In `APP_ENV=production` it creates no demo accounts and no demo data.

---

## 1. Before you start

- [ ] Agree a time with the client when nobody is using the system.
- [ ] Have the owner's **email address** and a **strong first password** ready (12+ characters). The owner must change this password at the first sign-in.
- [ ] Decide on **two-step login**. It is on by default for owners, admins and Tessera support, and only a Tessera support (super user) account can switch it off or on again (*Settings → Staff and roles*). If it stays on, each of them needs a **phone with an authenticator app** (Google Authenticator, Microsoft Authenticator or Authy).
- [ ] Decide the **M-PESA** setup:
  - `manual`: the cashier types the M-PESA code, and a manager matches it on *Payments → M-PESA reconciliation*. Use this to start today.
  - `daraja`: needs the client's Safaricom Daraja keys, shortcode and passkey (see `backend/.env.example`). Switch to it later.
- [ ] Decide on **eTIMS**. The real KRA connection is not built yet, so use `ETIMS_DRIVER=disabled`. Invoices then stay *pending* and are never shown as compliant. Agree with the client how they will issue KRA invoices until the integration is ready.
- [ ] Find out **where the server runs**: a VPS with Docker Compose (section 2A), or Render with a Neon database (section 2B).

## 2A. Server running Docker Compose (VPS)

Run these on the server, in the folder that holds `docker-compose.yml`.

**1. Back up the demo database.** This is optional, but cheap:

```bash
docker compose exec db pg_dump -U tessera -d tessera_pos -Fc > demo-backup-$(date +%F).dump
```

**2. Edit the root `.env`.** It sits next to `docker-compose.yml` and is never committed. Add or change these lines:

```dotenv
APP_ENV=production
APP_URL=https://pos.tesseratech.co.ke
TESSERA_DEMO=false
TESSERA_OWNER_EMAIL=owner@clientshop.co.ke
TESSERA_OWNER_PASSWORD="a-strong-first-password"
DB_PASSWORD="a-new-long-random-database-password"
MPESA_DRIVER=manual
MPESA_CALLBACK_TOKEN="a-long-random-string"
ETIMS_DRIVER=disabled
```

- Keep the existing `APP_KEY` if one is set. If none is set, the container keeps its own key in the `app-storage` volume, and step 3 deletes that volume, so a new key is made on the next start. That is fine for a fresh shop.
- Put any value that contains `#`, `$` or spaces in quotes.

**3. Delete the demo database and uploads, then start clean:**

```bash
docker compose down -v
docker compose up -d --build
```

`down -v` deletes the `db-data` volume (the database) and `app-storage` (uploaded logos and photos). Make sure step 1 is done first.

**4. Watch the first start.** It takes about a minute.

```bash
docker compose logs -f app
```

Wait until the migrations finish, then stop watching with Ctrl+C.

Behind Cloudflare, make sure the site is served over **HTTPS** and that Cloudflare forwards to the container's port (`APP_PORT`, default 8080).

## 2B. Render with a Neon database

**1. Neon** (console.neon.tech):

1. Create a **new database** in the project, or create a branch and reset it.
2. Alternatively, empty the existing one in the SQL editor:

   ```sql
   DROP SCHEMA public CASCADE; CREATE SCHEMA public;
   ```

   This deletes all demo data. Back it up first if you want to keep it (*Branches → Create branch* keeps a copy).

**2. Render** (dashboard → the service → *Environment*). Set:

- `APP_ENV` = `production`
- `TESSERA_DEMO` = `false`
- `TESSERA_OWNER_EMAIL` and `TESSERA_OWNER_PASSWORD`
- `APP_URL` = `https://pos.tesseratech.co.ke`
- `MPESA_DRIVER` = `manual`
- `ETIMS_DRIVER` = `disabled`

Keep `APP_KEY`, `DB_*` and `DB_SSLMODE=require`. If you created a new database, update `DB_DATABASE`.

**3. Upgrade the plan.** Move the service and the database off the free plan:

- The free web service sleeps after 15 minutes. A till that has to wait a minute in front of a customer is not acceptable.
- The free service has no disk, so uploaded logos and product photos are lost on every sleep or redeploy. Add a **persistent disk** mounted at `/var/www/tessera/backend/storage/app`.

**4. Redeploy.** Choose *Manual Deploy → Deploy latest commit* and wait for the health check on `/api/v1/ping`.

## 3. Check the clean server

- [ ] `https://pos.tesseratech.co.ke/api/v1/ping` answers `pong`.
- [ ] The login page no longer says *Spanish Wines & Spirits*.
- [ ] `owner@tessera.test` / `password` is **refused**.
- [ ] The owner signs in with the new email address and changes the password. While two-step login is on, they also scan the QR code and **write down the 8 recovery codes**.
- [ ] *Inventory → Stock levels* is empty and *Sales* has no sales.
- [ ] *Administration → Branches* shows only **MAIN · Main Branch**. Rename it to the shop's name before the first sale; the code `MAIN` appears in every document number.

## 4. Set up the shop (owner, in the back office)

Follow the user guide's chapter 12 (Administration) and chapters 6–7 (Products, Stock).

1. **Settings → Industry preset:** choose *Wines & spirits*, click *Preview changes*, then apply.
2. **Settings → Business:**
   - legal name, address, phone, email, KRA PIN and VAT status.
3. **Settings → Branding:**
   - shop name, logo and colours.
4. **Settings → Receipts:**
   - header and footer, paper size, and the **invoice number prefix**. The prefix locks after the first sale.
5. **Settings → Payments:**
   - the methods the shop accepts, the M-PESA till or paybill number, and cash rounding.
6. **Settings → Staff and roles:**
   - discount limits, which actions need a manager's PIN, and the cash-up rules.
7. **Settings → Products and stock:**
   - licensed trading hours, selling by the tot, and whether stock may go below zero (recommended: off).
8. **Administration → Users & roles:**
   - one account per person. Give managers a back-office password.
   - Give cashiers a 4-digit **till PIN** (⋯ → *Set till PIN*).
9. **Catalogue:**
   - categories and brands first, then the products.
   - For each product, enter its sizes, barcode, **tax class**, cost price and selling price.
   - Add suppliers under *Purchasing → Suppliers*.
10. **Opening stock:**
    - count the shelves and enter the counts on *Inventory → Breakages & adjustments* with the reason **Opening stock**.
    - A second person (a manager) approves the adjustment.
    - Check *Inventory → Stock levels* against the shelf.

## 5. Set up the till and do a test run

1. On the till computer, open `https://pos.tesseratech.co.ke/till` and choose **Manager sign in**. Name the till and set its opening float.
2. **Settings → Integrations → Receipt printer:**
   - *Browser printing* works with any printer.
   - *Direct printing* over USB is faster and also opens the cash drawer.
3. Test the barcode scanner on a few products.
4. Make one test sale paid in **cash** and one paid by **M-PESA**, then **return** both at the till.
5. End the shift, count the drawer, and have the manager sign off the cash-up under *Sales & Shifts → Shifts & cash-ups*.
6. The cashier signs in with their PIN. The shop is live.

## 6. After go-live

- [ ] Every evening: cash-ups signed off, and the dashboard and reports checked.
- [ ] Back up the database daily: `pg_dump` from a cron job on a VPS, or Neon's point-in-time restore.
- [ ] Switch `MPESA_DRIVER` to `daraja` when the Safaricom keys arrive.
  - Set a public `MPESA_CALLBACK_BASE_URL` and run `php artisan mpesa:register-c2b` once.
- [ ] Book the eTIMS setup with Tessera support once the KRA integration is ready.
