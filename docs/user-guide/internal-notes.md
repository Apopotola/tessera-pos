# User guide: internal notes (not for clients)

Decisions, roadmap items and fixes found while preparing the client user guide. The client guide must not mention anything in the "Roadmap / not yet active" list.

Last updated: 27 September 2026.

## Roadmap / not yet active

These are hidden in the system or not built. Leave them out of the client guide until they work.

| Item | State in the system | Notes |
| --- | --- | --- |
| Receipts by SMS or email ("SMS or email only" print option) | **Hidden.** The option has been removed from Settings → Receipts → Print behaviour. | Needs an SMS/email provider and receipt sending. The notifications module logs SMS in demo mode only. |
| Batch and expiry tracking for new items | **Hidden** from Settings → Products and stock. It cannot be changed, and industry presets skip it. | Needs batch/expiry capture on goods received and first-expiry-first-out selling (Phase 2). |
| Settings marked "Coming later" | Shown greyed out with "Coming later": crate and bottle deposits, expiry alerts, prescription notes, weighing-scale items, quotations, tables/kitchen tickets, category order, custom web address, customer SMS wording, online store stock sync. | The guide says only that some settings are marked "Coming later" and cannot be used yet. |
| Real M-PESA (Safaricom Daraja) | Runs on the **demo** connection. | Waiting for the client's shortcode, passkey and API keys. |
| Real KRA eTIMS (VSCU/OSCU) | Runs on the **demo** connection. | Waiting for device registration, specification and sandbox access. |
| SMS/WhatsApp alerts | Logged under Messages sent, not delivered. | Provider not chosen. |
| Loyalty points | Not built. | Needs a legal check (consent, alcohol-promotion rules). |
| Public holidays in licensed hours | Not built. | Owners enter holiday hours by hand for now. |

## Coming soon

- **Storekeepers request purchase orders for approval.** Target process:
  1. The storekeeper creates a draft (requested) purchase order.
  2. The owner, admin or accountant approves and sends it.
  3. The storekeeper receives the goods (goods-received note).

  Today only the owner, admin or accountant can raise a purchase order; storekeepers can view orders and receive goods. The client guide documents the **current** permissions.

## Fixes needed (reported, not yet changed)

1. **Cashiers cannot add a new customer.** Target: cashiers may search, select a customer on a sale, and add a new customer (including the KRA PIN for business invoices). Checked on 27 September 2026, as a cashier:

   | Action | Result today | Target |
   | --- | --- | --- |
   | Search and select a customer at the till | Allowed | Allowed ✅ |
   | See the Customers list and a customer's details (name, KRA PIN, wholesale flag, number of sales; no money) | Allowed | Allowed ✅ |
   | **Add a new customer** | **Refused** (needs "manage customers") | **Allowed ❌ fix needed** |
   | Edit a customer | Refused | Refused ✅ |
   | Change credit limit or terms | Refused | Refused ✅ |
   | Delete (anonymise) a customer | Refused | Refused ✅ |
   | Export a customer's data | Refused | Refused ✅ |
   | See account balance, statement, all customer accounts, sales history | Refused | Refused ✅ |

   Fix: a separate "add customers" permission for cashiers (create only, not edit), plus an "Add customer" form in the till's customer picker (name, phone, KRA PIN). The till search shows each account customer's **credit available** so the cashier knows whether a sale can go on account. That is not the full balance or statement; confirm this is acceptable.

## Changed in the system for the guide (27 September 2026)

- Storekeepers now have the **Dashboard** menu item. Their dashboard shows stock tiles only; they were already landing on it as their home screen.
- "SMS or email only" receipts and batch/expiry tracking are hidden from Settings (see above).
- The demo walkthrough PDF now says the owner, admin or accountant raises purchase orders (storekeepers receive goods).
- **Stock ledger fix:** sales, customer returns, goods received, returns to supplier and opened bottles showed an empty "Type" badge and could not be picked in the type filter. Found while taking the chapter 2 screenshots; fixed in the web app.

## Found while capturing (not changed)

- The inputs on the Settings screen have no accessible label (the label is plain text beside the box). Screen readers cannot name them. Low priority.

## Decisions for the guide

| Topic | Decision |
| --- | --- |
| Cashiers and customers | Document only the cashier-level actions (search and select at the till). Add "add a customer" once the fix above is done. |
| Storekeeper home screen | Document the stock-only Dashboard as the storekeeper's home screen. |
| Purchase orders | Document the current permissions: owner, admin or accountant raise orders; a different person approves; storekeepers receive. |
| Prices | Branch managers cannot change prices: prices are set centrally by the owner or admin. The guide explains why (no unapproved price changes, branches stay consistent, eTIMS invoices stay correct). Managers can still approve discounts at the till up to their limit. |
| Screenshot sizes | Back office 1440×900; till 1366×768 (the till is built for a computer or tablet, not a phone). |
| Demo data | A separate guide database: "Karen Wines & Spirits", branches Karen and Westlands, Kenyan names, dummy phone numbers (0712 000 000) and dummy KRA PINs (P000000000X). The local demo data is not changed. |
| Output | Markdown chapters plus images, combined into `docs/Tessera_POS_User_Guide.docx` and `.pdf`. |
