# Tessera POS: system inventory (approved)

Compiled 27 September 2026 from the code (menus, screens, forms, permissions) and checked against the running system (menus fetched while signed in as Owner, Branch Manager, Accountant and Storekeeper; reports listed by the running system). This file is the working checklist for the user guide. It is not part of the client guide.

**Role key:**

| Code | Role |
| --- | --- |
| Ow | Owner |
| Ad | Admin |
| BM | Branch Manager |
| Ca | Cashier |
| SK | Storekeeper (the brief's "stock clerk") |
| Ac | Accountant |
| TS | Tessera Support (Tessera's own staff, not the client) |

"See" means the menu item appears. Where roles can see a screen but not do everything on it, the Purpose column says who can do what.

## How the modules connect

The main steps (purple, and the till in amber) follow a business day from left to right. The white boxes are the records each step updates. Dotted arrows show what feeds the dashboard, reports and alerts. The source file is `diagrams/modules-flow.mmd` (Mermaid), so the chart can be edited and redrawn.

![Figure 1: How the Tessera POS modules connect, in the order of a business day](images/inventory/00-modules-flow.png)

1. **Set up once:** the owner fills in Settings, adds branches and tills, and creates users with their roles and till PINs.
2. **Catalogue:** products, sizes and barcodes are added. New prices and promotions wait for the owner's approval.
3. **Purchasing:** a purchase order is raised and approved by someone else, then the goods are received. Stock goes up in the stock ledger, and the supplier's bill goes to Supplier accounts.
4. **POS Till:** the cashier signs in with a PIN and opens the shift. Each sale lowers the stock, and its tax invoice is sent to KRA automatically (eTIMS monitor). M-PESA payments go to M-PESA reconciliation and sales on account to Customer accounts. Returns need a manager and put stock back.
5. **End shift:** the cashier counts the drawer. Payouts and expenses are taken into account, and a manager signs off the cash-up.
6. **See the business:** the dashboard, reports, notifications (low stock, licences, daily summary) and the audit log bring it all together.

## 1. Signing in and the account menu (no menu item)

| Module | Menu | Screen / form | Roles | Purpose |
| --- | --- | --- | --- | --- |
| Sign in | — | Back-office sign-in (email or phone + password, "Keep me signed in") | All except Ca | Sign in to the back office. |
| Sign in | — | Forgot password? | All | Explains that the owner or an administrator resets passwords. There is no self-service reset by email or SMS. |
| Sign in | — | Two-step login set-up (QR code, 6-digit code, recovery codes) | Ow, Ad, TS (TS always) | Extra security for owners and admins. The owner can switch it off under Settings. |
| Sign in | — | Change password (forced when expired or first sign-in) | All back-office users | Change your own password. |
| Account menu (top right) | — | Open till screen · Change password · Two-step login · Sign out | All back-office users | Personal actions. |
| Notifications | — | Bell with unread alerts | All back-office users | Alerts: low stock, licence expiry, large refunds, cash variance, eTIMS failures, daily summary. |
| Session security | — | Signed out after 30 minutes idle (back office) | All back-office users | Protects an unattended computer. |

## 2. The till (full-screen, opened from **POS Till** or `/till`)

| Module | Menu | Screen / form | Roles | Purpose |
| --- | --- | --- | --- | --- |
| POS Till | POS Till | Till launcher page | Ow, Ad, BM, Ca, TS | Opens the full-screen till on this computer. |
| POS Till | — | Set up this device as a till (branch, till name, location, opening float) | Ow, Ad, TS (manager sign-in) | One-time pairing of a computer as a till. |
| POS Till | — | Who's on the till? (choose name, PIN pad, start shift) | Ca, BM, Ow… (anyone with a PIN and selling rights) | Cashier sign-in by PIN; starts or resumes the shift. |
| POS Till | — | Selling screen: search/scan, favourites (best sellers), cart, quantity, customer, totals, Pay | Ca and above | Making sales. |
| POS Till | — | Line edit (quantity, discount, price change) + Manager approval (manager PIN) | Ca (within limit); manager approves beyond | Discounts, price overrides, removing items. |
| POS Till | — | Age check | Ca | Confirm the customer is 18 or over (setting). |
| POS Till | — | Take payment: M-PESA (payment request to phone, or pick a payment already made), Cash, Card, On account, Split; customer KRA PIN | Ca | Taking payment. |
| POS Till | — | Sale complete (change due, receipt preview, Print receipt, Next customer) | Ca | Finish the sale. |
| POS Till | — | Customer picker | Ca | Attach a registered customer (wholesale price, on-account, tax invoice). |
| POS Till | — | Park sale / Recall parked sales | Ca | Hold a sale while serving someone else. |
| POS Till | — | Sell by tot (open bottles) | Ca | Pour and sell tots from an open bottle (setting). |
| POS Till | — | Price check | Ca | Look up a price without selling. |
| POS Till | — | Return or reprint (find receipt, pick items, reason, refund) | Ca requests; manager approves (setting) | Customer returns and receipt reprints. |
| POS Till | — | Cash drop to the safe | Ca | Move cash from the drawer to the safe during a shift. |
| POS Till | — | Pay out cash for an expense (manager witnesses by PIN) | Ca + manager | Small expenses paid from the drawer. |
| POS Till | — | Lock screen (manual, or automatic after 2 minutes idle; sale kept) | Ca; manager can unlock | Leave the till safely. |
| POS Till | — | Switch cashier | Ca | Hand over without ending the shift. |
| POS Till | — | End shift: count the cash drawer (notes and coins) → Shift ended (expected, counted, variance, reason) | Ca | Cash-up at the end of a shift. |
| POS Till | — | Offline mode banner (still selling; cash and card only; sends when back online) | Ca | Keep selling when the internet is down. |
| POS Till | — | Licensed-hours banner (no alcohol outside licensed hours) | Ca | Shown when the owner has switched on the licensed-hours lock. |

## 3. Back-office menus

| Module | Menu | Screen / form | Roles (see) | Purpose |
| --- | --- | --- | --- | --- |
| Dashboard | Dashboard | KPI tiles, Needs attention, Shifts open now, Low stock, Best sellers/slow movers, Branch comparison, Cash variance by cashier, and others | Ow, Ad, BM, SK, Ac, TS | Today's business at a glance. Tiles depend on the role and the settings. The storekeeper's version shows stock tiles only and is their home screen. |
| Sales & Shifts | Sales | Sales list → Sale detail (lines, payments, receipt, returns) | Ow, Ad, BM, Ac, TS | Find and inspect any sale; reprint. |
| Sales & Shifts | Shifts & cash-ups | Shift list → sign off a cash-up (with cashier's reason) | Ow, Ad, BM, TS | Managers check and sign off every cash-up. |
| Catalogue | Products | Product list → New/Edit product, Sizes (add size), Add barcode, Add pack (e.g. crate of 12), Change price, Price history, Product detail tab | See: Ow, Ad, BM, SK, Ac, TS. Edit: Ow, Ad, TS | The items the shop sells. |
| Catalogue | Price changes | Pending price changes → Approve / Reject | Ow, Ad, TS (Ow and TS approve) | Maker–checker for prices. |
| Catalogue | Promotions | Promotion list, New promotion (percent/amount off, minimum quantity, dates, happy-hour times, categories/items), approve, discount given | Ow, Ad, BM, TS (Ow and TS approve) | Automatic discounts at the till. |
| Catalogue | Brands & categories | Add/Edit brand, Add/Edit category | Ow, Ad, TS | Organise products. |
| Inventory | Stock on hand | Stock per item and branch; reorder level per item | Ow, Ad, BM, SK, Ac, TS | What is in stock where. |
| Inventory | Breakages & adjustments | New stock adjustment (breakage, theft, expiry, damage, opening stock…) → Approve / Reject | See: Ow, Ad, BM, SK, Ac, TS. Record: SK, Ow, Ad. Approve: BM, Ow, Ad | Stock losses and corrections. |
| Inventory | Transfers | Request a transfer → Approve → Dispatch → Receive; Cancel | See: as above. Request: SK, Ow, Ad. Approve: BM, Ow, Ad | Move stock between branches. |
| Inventory | Stock counts | Start a stock count (blind count option) → Count sheet → Submit → Approve / Reject | See: as above. Count: SK, Ow, Ad. Approve: BM, Ow, Ad | Physical stock-take. |
| Inventory | Open bottles (tots) | Open bottles per branch, remaining ml, Write off | Ow, Ad, BM, SK, Ac, TS | Track bottles being sold by the tot. |
| Inventory | Stock ledger | Every stock movement with its document | Ow, Ad, BM, SK, Ac, TS | The full history of stock. |
| Purchasing | Purchase orders | New purchase order → Approve / Reject / Cancel → Receive goods (received, damaged) → Goods-received note; Purchase order detail tab | See: Ow, Ad, BM, SK, Ac, TS. Raise: Ow, Ad, Ac. Approve: BM, Ow, Ad (never their own). Receive: SK, BM, Ow, Ad | Order from suppliers and receive deliveries. |
| Purchasing | Supplier invoices | Record supplier invoice (matched to order and delivery) | See: as above. Record: Ow, Ad, Ac | Bills from suppliers. |
| Purchasing | Returns to supplier | Return to supplier → Approve / Reject → Credit note | See: as above | Send damaged or wrong goods back. |
| Purchasing | Suppliers | Supplier list → Add/Edit supplier | See: as above. Edit: Ow, Ad, Ac | Supplier details and terms. |
| Purchasing | Supplier accounts | Payables aging, Payment to supplier, Reverse payment, Statement | Ow, Ad, BM, Ac, TS (pay: Ow, Ad, Ac) | What the shop owes each supplier. |
| Payments | M-PESA reconciliation | M-PESA payments vs sales, unmatched payments, Match to a sale | Ow, Ad, BM, Ac, TS | Make sure every M-PESA payment belongs to a sale. |
| Customers | Customers | Customer list → Register/Edit customer, Customer detail tab, Credit account (limit, terms), Anonymise | See: Ow, Ad, BM, Ca, Ac, TS. Add/Edit: Ow, Ad, BM. Credit: Ow, Ad, Ac. Anonymise: Ow, Ad. Cashiers: search and select only (no balances); adding customers is a fix needed (internal notes) | Registered customers (businesses, wholesale, account customers). |
| Customers | Customer accounts | Receivables aging, Payment from customer, Statement | Ow, Ad, BM, Ac, TS | What customers owe the shop. |
| Expenses | Expenses | Expense list and totals, Record an expense, Approve / Reject, Reverse | Ow, Ad, BM, Ac, TS (approve: BM, Ow, Ad; never their own) | Petty cash, bank and M-PESA spending. |
| Compliance | eTIMS monitor | Signed / pending / refused invoices, daily check, retry | Ow, Ad, BM, Ac, TS | KRA eTIMS status of every sale and return. |
| Compliance | Licences & permits | Licence register, add, Renew, Remove, print on receipt | See: Ow, Ad, BM, Ac, TS. Manage: Ow, Ad, TS | Licence expiry reminders. |
| Reports | Reports | Report catalogue (22 entries) → each report with filters, CSV export and print | Ow, Ad, BM, Ac, TS (profit and financial reports need the financial permission) | See the list in section 4. |
| Administration | Branches | Branches, Tills per branch, Disconnect a till | Ow, Ad, TS | Branches and till devices. |
| Administration | Users & roles | User list → Add/Edit user (role, branches), Till PIN, Reset two-step login | Ow, Ad, TS | Staff accounts and access. |
| Administration | Settings | Nine sections: Business, Branding, Receipts, Sales screen, Products and stock, Payments, Staff and roles, Notifications, Integrations. Also: settings per business, branch or till; Industry preset; History and Undo; "Locked after first use" list | Ow, Ad, TS (BM: branch-level settings only) | How the system looks and works. Settings for unfinished features are hidden (see internal notes). |
| Administration | Messages sent | Log of SMS / WhatsApp / in-app alerts sent | Ow, Ad, TS | Check what alerts went out (SMS runs in demo mode). |
| Administration | Audit log | Who did what and when, with filters | Ow, Ad, BM, Ac, TS | Accountability and investigations. |

## 4. Reports (as listed by the running system)

| Group | Report |
| --- | --- |
| Sales | Sales summary |
| Sales | Sales by item, product, category or brand |
| Sales | Sales by cashier, branch or payment method |
| Sales | Voids, discounts & price overrides |
| Sales | Returns & refunds register |
| Inventory | Current stock by branch |
| Inventory | Stock valuation as at a date |
| Inventory | Adjustments & losses by reason |
| Inventory | Stock count variance |
| Inventory | Transfers & discrepancies |
| Inventory | Low stock & reorder suggestions |
| Inventory | Stock movement ledger (links to Inventory → Stock ledger) |
| Financial | Revenue, cost of goods & gross profit |
| Financial | Shift cash-up & variance |
| Financial | Purchases by supplier |
| Financial | Receivables aging (customer accounts) |
| Financial | Payables aging (supplier balances) |
| Financial | Expenses (petty cash) |
| Financial | M-PESA reconciliation (links to Payments) |
| Compliance | eTIMS status, failures & daily check (links to Compliance) |
| Compliance | Licence & permit expiry list (links to Compliance) |
| Compliance | Audit trail (links to Administration → Audit log) |

## 5. Screens (screenshots from the running system)

**How these were taken:**
- **Back office (Figures 2 to 35):** 1440×900, signed in as the owner on the local demo system, just looking (nothing was changed).
- **Till (Figures 36 to 53):** 1366×768, on the separate test system, with a cashier (Otieno) making a real test sale.

The small "N" badge in the bottom-left corner appears only in development mode. The final guide will be shot without it, on the "Karen Wines & Spirits" demo data once you approve it.

### 5.1 Back office

![Figure 2: Back-office sign-in](images/inventory/01-sign-in.png)
![Figure 3: Dashboard (owner view)](images/inventory/02-dashboard.png)
![Figure 4: POS Till: opens the till screen on this computer](images/inventory/03-pos-till.png)
![Figure 5: Sales & Shifts → Sales](images/inventory/04-sales.png)
![Figure 6: Sales & Shifts → Shifts & cash-ups](images/inventory/05-shifts-cashups.png)
![Figure 7: Catalogue → Products](images/inventory/06-products.png)
![Figure 8: Catalogue → Price changes](images/inventory/07-price-changes.png)
![Figure 9: Catalogue → Promotions](images/inventory/08-promotions.png)
![Figure 10: Catalogue → Brands & categories](images/inventory/09-brands-categories.png)
![Figure 11: Inventory → Stock on hand](images/inventory/10-stock-on-hand.png)
![Figure 12: Inventory → Breakages & adjustments](images/inventory/11-breakages-adjustments.png)
![Figure 13: Inventory → Transfers](images/inventory/12-transfers.png)
![Figure 14: Inventory → Stock counts](images/inventory/13-stock-counts.png)
![Figure 15: Inventory → Open bottles (tots)](images/inventory/14-open-bottles.png)
![Figure 16: Inventory → Stock ledger](images/inventory/15-stock-ledger.png)
![Figure 17: Purchasing → Purchase orders](images/inventory/16-purchase-orders.png)
![Figure 18: Purchasing → Supplier invoices](images/inventory/17-supplier-invoices.png)
![Figure 19: Purchasing → Returns to supplier](images/inventory/18-returns-to-supplier.png)
![Figure 20: Purchasing → Suppliers](images/inventory/19-suppliers.png)
![Figure 21: Purchasing → Supplier accounts](images/inventory/20-supplier-accounts.png)
![Figure 22: Payments → M-PESA reconciliation](images/inventory/21-mpesa-reconciliation.png)
![Figure 23: Customers → Customers](images/inventory/22-customers.png)
![Figure 24: Customers → Customer accounts](images/inventory/23-customer-accounts.png)
![Figure 25: Expenses](images/inventory/24-expenses.png)
![Figure 26: Compliance → eTIMS monitor](images/inventory/25-etims-monitor.png)
![Figure 27: Compliance → Licences & permits](images/inventory/26-licences-permits.png)
![Figure 28: Reports](images/inventory/27-reports.png)
![Figure 29: Administration → Branches](images/inventory/28-branches.png)
![Figure 30: Administration → Users & roles](images/inventory/29-users-roles.png)
![Figure 31: Administration → Settings](images/inventory/30-settings.png)
![Figure 32: Administration → Messages sent](images/inventory/31-messages-sent.png)
![Figure 33: Administration → Audit log](images/inventory/32-audit-log.png)
![Figure 34: Notifications (the bell, top right)](images/inventory/33-notifications.png)
![Figure 35: Account menu (top right)](images/inventory/34-account-menu.png)

### 5.2 The till

![Figure 36: A computer that is not yet a till](images/inventory/35-till-device-not-set-up.png)
![Figure 37: Setting up the computer as a till (manager signed in)](images/inventory/36-till-set-up-form.png)
![Figure 38: Who's on the till? The cashier enters a PIN](images/inventory/37-till-pin-screen.png)
![Figure 39: Selling screen with this week's best sellers](images/inventory/38-till-selling-screen-best-sellers.png)
![Figure 40: Searching for a product](images/inventory/39-till-search-results.png)
![Figure 41: Two bottles of wine with the promotion applied](images/inventory/40-till-cart-with-promotion.png)
![Figure 42: Age check before payment](images/inventory/41-till-age-check.png)
![Figure 43: Take payment: M-PESA (demo)](images/inventory/42-till-take-payment-mpesa.png)
![Figure 44: Take payment: cash, with the change worked out](images/inventory/43-till-take-payment-cash.png)
![Figure 45: Sale complete: change to give and the receipt](images/inventory/44-till-sale-complete-receipt.png)
![Figure 46: Choosing a registered customer](images/inventory/45-till-choose-customer.png)
![Figure 47: The till menu](images/inventory/46-till-till-menu.png)
![Figure 48: Return or reprint](images/inventory/47-till-return-or-reprint.png)
![Figure 49: Pay out cash for an expense](images/inventory/48-till-pay-out-cash.png)
![Figure 50: Cash drop to the safe](images/inventory/49-till-cash-drop.png)
![Figure 51: Till locked (the sale is kept)](images/inventory/50-till-locked.png)
![Figure 52: End shift: counting the cash drawer](images/inventory/51-till-end-shift-count.png)
![Figure 53: Shift ended: expected, counted and difference](images/inventory/52-till-shift-ended.png)

## 6. Features in the brief that do not exist (will not be documented)

| Brief item | Finding |
| --- | --- |
| Change language | Not built: the system is English only. |
| Reset password by yourself | Not built: "Forgot password?" tells you to ask the owner or an administrator, who sets a new password in Users & roles. |
| Receipts by SMS or email | Not built. The option has been hidden from Settings until it works (internal notes). |
| Expiry alerts, batch tracking | Not built. Batch tracking is hidden from Settings; "Expiry alerts" shows as Coming later. |
| X-report / Z-report | No reports with these names. The shift cash-up (end-of-shift count) and the "Shift cash-up & variance" report do this job. |
| Real M-PESA and KRA eTIMS | Built, but running on **demo connections** until the client supplies credentials. Screens show "Demo M-PESA" notes; no real money or tax data moves. |
| Phone / mobile app | None. The till is designed for a computer or tablet screen. |

## 7. Decisions (27 September 2026)

| # | Question | Decision |
| --- | --- | --- |
| 1 | Approve the inventory | Approved. Write "How everything connects" first, then Sales/POS. |
| 2 | "SMS or email only" receipts and batch/expiry tracking | **Hidden** from Settings until they work. Not in the client guide; listed in `internal-notes.md`. |
| 3 | Cashiers and the Customers menu | Intended, with limited rights. Checked: cashiers can search and select but **cannot add** a customer (fix needed, internal notes), and cannot edit, delete, export or see balances (correct). |
| 4 | Storekeeper dashboard | Their home screen. The missing menu item was a bug and has been **fixed** (Dashboard added to the storekeeper menu). |
| 5 | Who raises purchase orders | Document the current permissions (owner, admin or accountant). Storekeeper requests are "coming soon" (internal notes). The demo PDF has been corrected. |
| 6 | Managers and prices | Intended: prices are set centrally. The guide explains why, and that managers still approve till discounts up to their limit. |
| 7 | "Coming later" settings | Mentioned only as not available yet. |
| 8 | Screenshot sizes | Back office 1440×900, till 1366×768. |
| 9 | Demo data | Separate guide database, "Karen Wines & Spirits" (Karen and Westlands branches). |
| 10 | Output | Word and PDF from the Markdown source. |
