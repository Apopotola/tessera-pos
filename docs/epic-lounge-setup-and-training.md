# Epic Lounge Social House: set-up and training guide

How to set up Tessera POS for **Epic Lounge Social House** and train the people who will use it, in the order to do it.

- **System:** https://pos.tesseratech.co.ke (back office) and https://pos.tesseratech.co.ke/till (till)
- **Owner:** Brian (`musungubrian9@gmail.com`)
- **Tessera super user:** `support@tesseratech.co.ke` (you)

The server is already reset to production (see [go-live-checklist.md](go-live-checklist.md)). The details of every screen are in the **Epic Lounge User Guide** ([Epic_Lounge_User_Guide.pdf](Epic_Lounge_User_Guide.pdf)); this guide says what to do and in what order.

| Part | What | Who | Time |
| --- | --- | --- | --- |
| 1 | Your super-user jobs | You | 20 min |
| 2 | Shop settings | You with Brian | 45 min |
| 3 | Staff and roles | You with Brian | 20 min |
| 4 | Products and prices | Brian / admin, you helping | 1–3 h |
| 5 | Opening stock | Storekeeper + manager | 1–2 h |
| 6 | Till, printer and scanner | You | 30 min |
| 7 | Practice run | Everyone | 30 min |
| 8 | Training: cashiers, managers, storekeeper, owner | You | 1–2 h per group |
| 9 | Daily, weekly and monthly routines | Brian and managers | — |
| 10 | Golden rules, problems and support | Everyone | — |

---

## Part 1. Your super-user jobs (Tessera support)

Your account is the **Tessera Admin** (super user). It can do everything the owner can, plus the Tessera-only settings that the owner cannot change. It is not a shop account: **never sell, receive stock or approve the shop's work with it.** Everything you do is in the audit log under your name.

### 1.1 First sign-in

1. Open https://pos.tesseratech.co.ke and sign in as `support@tesseratech.co.ke` with the first password the server printed.
2. Choose your own password (12+ characters). Keep it in a password manager.

### 1.2 Settings only you can change

*Administration → Settings*. These carry a lock for everyone else.

| Setting | Where | What to set |
| --- | --- | --- |
| **KRA PIN** | Business | The shop's KRA PIN (letter, 9 digits, letter), checked against the KRA certificate. It is printed on every receipt. |
| **VAT registered** | Business | On only if the shop is registered for VAT. Ask Brian for the certificate. |
| **Currency** | Business | KES (leave it). |
| **eTIMS invoicing** | Integrations | See below. |
| **Two-step login** | Staff and roles | **Off** for now, as agreed. Switch it on later for owners, admins and you. |

**eTIMS.** The connection to KRA is not built yet (the server runs with `ETIMS_DRIVER=disabled`). While *eTIMS invoicing* is on, every sale waits as *eTIMS pending* and nothing reaches KRA.

- Agree with Brian how he issues KRA invoices meanwhile (for example KRA's own eTIMS app or portal).
- If his receipts should not show *pending*, switch **eTIMS invoicing** off until the integration is ready, and note the date.

### 1.3 Your ongoing role

| When | You |
| --- | --- |
| Set-up | Do parts 1–7 with Brian, and run the training in part 8. |
| Every week | Check the nightly backup ran (`ls -lh ~/backups/tessera` on the server). Copy the latest one off the server. |
| When asked | Reset a forgotten password or a till that will not sign in (part 10), switch eTIMS or two-step login, change Tessera-only settings. |
| Updates | `git pull` and rebuild the server (as in the go-live checklist), out of trading hours, after a backup. |

---

## Part 2. Shop settings (you with Brian)

*Administration → Settings*. Check **Settings for: Whole business** at the top before every save. Each setting has its own **Save** button, and every change can be undone from its **⋯** menu.

### 2.1 Start from the preset

**Business → Industry preset → Wines & spirits → Preview changes → Apply.** This switches on:

- selling by the tot,
- the age check,
- the licensed-hours lock,
- crate deposits,
- the dark till theme.

Then go through the sections below.

### 2.2 Section by section

| Section | Setting | Recommended for Epic Lounge |
| --- | --- | --- |
| **Business** | Legal business name | Exactly as on the KRA certificate (already *Epic Lounge Social House*; correct it if the registered name differs). |
| | Address, phone, email | The lounge's own. They are printed on receipts. |
| | Financial year starts | Usually January; ask Brian's accountant. |
| **Branding** | Business display name | *Epic Lounge Social House* (done). |
| | App logo | Brian's logo, PNG or SVG, up to 1 MB. |
| | Receipt logo | A black-and-white PNG, up to 300 px wide. |
| | Colours | Pick from the list; custom colours must keep white text readable. |
| | Login background | *Shop photos*, or upload a photo of the lounge. |
| | Theme | Dark suits a lounge till; the back office can stay light. |
| **Receipts** | Header | 2–3 lines: the address, the phone, and *PIN: …* if wanted. |
| | Footer | For example *Thank you for visiting Epic Lounge. Drink responsibly.* |
| | Paper size | 80 mm (most receipt printers). |
| | **Invoice number prefix** | For example `ELSH-`. **It locks after the first sale.** Decide before the practice run. |
| | Print behaviour | *Ask* while training, then *Always* if every customer gets paper. |
| **Sales screen** | Layout, touch mode | Tiles, large (touch screen). |
| | Favourites | *Top sellers*, or pick the 12–20 drinks sold most. |
| | Category order | The order of the chips on the till: for example Beer, Spirits, Wine, Soft drinks. |
| | Age-check prompt | On. |
| | **Licensed hours** | Enter the hours on the liquor licence. With the lock on, the till refuses alcohol outside them. **If they are not entered, check the till still sells.** |
| | Tables, kitchen tickets, tips | *Coming later*. Use **Hold sale** with the table name for now (part 8). |
| **Products and stock** | Sell by tot | On if spirits are sold by the shot. |
| | Default low-stock level | 5, then set real levels per drink later. |
| | **Selling below zero stock** | *Needs approval* (the default): a manager's PIN is needed when the system says there is none left. This catches wrong opening stock. |
| **Payments** | Methods accepted | Cash, M-PESA, card (only if there is a card machine). |
| | M-PESA account type and number | *Buy Goods (Till)* or *Paybill*, and the number. |
| | **M-PESA STK Push** | **Off for now.** The server is on the manual M-PESA method: the customer pays to the till number and the cashier types the code. Switch it on when the Daraja keys are set up. |
| | Customer credit | Off, unless Brian gives regular customers a tab (then *On*, approval by manager). |
| | Cash rounding | 0 (exact), or round to KES 5/10 if the lounge does. |
| **Staff and roles** | **Maximum discount per role** | Cashier 0–5 %, Branch Manager 10–20 %, Owner 100 %. Above the limit, a manager's PIN is needed. |
| | Approvals | Refund: *needs approval*. Price change at the till: *needs approval*. Remove an item: allowed below KES 5,000. Open drawer without a sale: *needs approval*. |
| | Opening float | What the drawer starts with each day, for example KES 5,000. |
| | Blind cash-up | On: cashiers count before they see the expected amount. |
| | Allowed cash variance | KES 100 (the default). Bigger differences raise an alert. |
| | Back office signs out after | 30 minutes. |
| | Till locks after | 2 minutes (more at a busy bar if it gets in the way). |
| **Notifications** | Large refund | KES 5,000. |
| | Daily report time | After closing, for example 23:30. |
| **Integrations** | Receipt printer, scanner, cash drawer | Set in part 6. |

> **Common mistake:** Changing a setting for one till or branch when you meant the whole business, or the other way round. Read **Settings for** before each save.

### 2.3 The branch

*Administration → Branches*: the branch is already named **Epic Lounge Social House**. Its code is **MAIN**, which appears in every receipt number (`ELSH-MAIN-000001`). Leave it as it is.

---

## Part 3. Staff and roles (you with Brian)

*Administration → Users & roles → Add user*. **One account per person, never shared.** Everything is recorded against the person signed in.

### 3.1 Which role for whom

| Role | Give it to | Signs in at | Can | Cannot |
| --- | --- | --- | --- | --- |
| **Owner** | Brian | Back office + till | Everything in the shop; approves prices, promotions and anything a manager would. | Tessera-only settings. |
| **Admin** | An office manager, if any | Back office | Products, staff, purchasing, settings. | Approve prices and promotions. |
| **Branch Manager** | The lounge manager / supervisor on each shift | Back office + till (PIN) | Approve discounts, voids, refunds, cash drops, adjustments; sign off cash-ups; receive stock. | Change prices; sign off their own cash-up or approve their own adjustment. |
| **Cashier** | Bar and counter staff | Till only (PIN) | Sell, take payment, park sales, return with approval, cash drop and count. | Anything in the back office. |
| **Storekeeper** | Whoever runs the store room | Back office | Receive deliveries, record breakages, transfers, stock counts. | Approve their own adjustments; sell. |
| **Accountant** | Brian's bookkeeper | Back office | Purchase orders, supplier bills and payments, expenses, all reports. | Sell; change stock. |

A small lounge often starts with: **Brian (Owner)**, **one or two Branch Managers** (one per shift), **the cashiers**, and optionally a **Storekeeper**. There must be at least one manager on every shift; otherwise nobody can approve a refund or witness a cash drop.

### 3.2 Adding each person

1. **Add user**: full name (as it should appear on the till), role, email or phone number, branch *Epic Lounge Social House*.
2. **Back-office password** for owner, admin, managers, storekeeper and accountant: type a first password. They must change it when they first sign in. Leave it empty for cashiers.
3. **⋯ → Set till PIN** for everyone who uses the till (cashiers, managers, Brian): four digits, typed twice. Let each person choose their own; never use 1234, 0000 or a birthday.
4. Give each person their sign-in details privately.

> **Important:** When someone leaves, untick **Account active** that same day. Their history stays.

---

## Part 4. Products and prices (Brian or admin, you helping)

There is no import from a spreadsheet, so products go in one at a time. Start with **what sells most**: the drinks behind the bar today. Add the rest during the first week.

**Prepare first (on paper or in Excel):** for each drink, its sizes, barcode, cost price, selling price and tot price.

1. **Catalogue → Brands and categories.** Categories become the chips on the till, so keep them short: *Beer, Cider, Whisky, Vodka, Gin, Brandy, Rum, Tequila, Liqueur, Wine, Champagne, Soft drinks, Energy, Water, Snacks*.
2. **Catalogue → Products → New product.** Enter the name, brand, category and ABV.
3. For each size, enter the ml, container, a short SKU and the **tax class** (most alcohol is standard 16 % VAT; ask the accountant). **Scan** the barcode from the bottle rather than typing it, and enter the retail price.
4. **Tot prices** for spirits sold by the shot: on the size, **⋯ → Change price → Tot**.
5. **Photos:** on the product, **Upload photo**. Use a clear, square photo of the bottle; it shows on the till and makes ordering faster.
6. **Suppliers** (*Purchasing → Suppliers*): name, phone and payment terms for each supplier.
7. **Reorder levels** (*Inventory → Stock levels → ⋯*): about two weeks of sales for the best sellers.

> **Note:** Prices entered by anyone other than Brian wait for his approval (*Catalogue → Price changes → Pending*). Brian should approve them before the practice run, or the till will not have prices.

---

## Part 5. Opening stock (storekeeper + manager)

Stock in Tessera is never typed in directly; the opening count goes in as a stock adjustment.

1. Count every shelf and the store room, one area at a time, before opening or after closing.
2. *Inventory → Breakages & adjustments → New adjustment*. Choose **What happened:** *Opening stock*.
3. **Location:** *Shop floor* for what is behind the bar, *Back store* for the store room (a separate adjustment for each). Add each item and the quantity counted.
4. **Send for approval.** A **different person** (Brian or a manager) approves it. Staff can't approve their own; Brian and Tessera support can, once *Owners and Tessera support may approve their own work* is on (Settings → Staff, switched by Tessera support).
5. Check *Inventory → Stock levels*: every drink's quantity should match the shelf. The *Stock dashboard* now shows the stock value.

> **Important:** Wrong opening stock makes every report wrong afterwards. Count twice for the expensive bottles.

---

## Part 6. Till, printer and scanner (you)

1. On the counter computer or tablet, open https://pos.tesseratech.co.ke/till in **Chrome** or **Edge**.
2. Choose **Manager sign in** and sign in as Brian or a manager (not with the super user, so the till is set up in the shop's name).
3. Set the branch, a till name (*Till 1*), where it is (*Main bar*) and the opening float, then **Set up till**.
4. **Printer.** In *Settings → Integrations → Receipt printer*, choose *Print through the browser* (works with any installed printer) or *Direct USB/serial printing* (faster, and opens the cash drawer).
5. On the till, open the till menu and choose **Printer → Print test page**.
6. **Scanner:** plug it in and scan a bottle in the search box. The item should appear.
7. Make the browser **full screen** (F11). Add a shortcut to the till page on the desktop.

For a second till, repeat on the second device with another name.

---

## Part 7. Practice run (everyone)

Tessera has **no separate training mode**. Practice on the live till, then return every practice sale, so the stock and money are back where they were.

- Keep practice small and cheap: use a soft drink or a water.
- Each practice sale and its return stay in the history. That is normal and correct.
- Return each practice sale on the till: **till menu → Return / reprint**. If a manager's approval is needed, the manager enters their PIN.
- End the practice with a full **End shift → count → manager signs off the cash-up** so the drawer starts the first real day clean.

---

## Part 8. Training

Train **by doing**: the trainee holds the mouse or the screen, you talk. Each session ends with the trainee doing the checklist alone.

### 8.1 Cashiers (60–90 minutes, in groups of 2–3)

**What a cashier is responsible for:** every sale they ring, the cash in their drawer, and their own PIN. They never use someone else's open screen and never tell anyone their PIN.

| # | Teach | Show them |
| --- | --- | --- |
| 1 | **Sign in** | Tap your name → PIN → *Start shift*. One open shift per person. |
| 2 | **Find drinks** | Scan the barcode, or type part of the name, or tap a category chip. Card numbers show stock left. |
| 3 | **Tots** | Tap the tot button on a spirit's card. |
| 4 | **Change quantity, remove** | Tap the line → quantity, *Remove item*. |
| 5 | **Discount** | Only within their limit; above it, a manager types the PIN. |
| 6 | **Age check** | When the till asks: *18 or over?* Ask for ID if unsure. If not, *No – do not sell*. |
| 7 | **Cash** | Type what the customer gives, give the change shown, *Complete sale*. |
| 8 | **M-PESA (for now)** | The customer pays to the **till number**. Check the amount and name on their message, type the 10-character **M-PESA code**, *Complete sale*. Never accept a code without seeing the message. |
| 9 | **Card** | Charge on the card machine, type its approval code. |
| 10 | **Split payment** | *Split*: for example part cash, part M-PESA. |
| 11 | **Receipt** | *Print receipt* or *Share receipt* (WhatsApp link). *Next customer*. |
| 12 | **Tabs at a table** | **Hold sale** with the table name (*Table 4*) → later **Recall parked** → add drinks → take payment when they leave. |
| 13 | **Void** | Clears the whole sale (a manager's PIN for bigger amounts). |
| 14 | **Returns** | Till menu → *Return / reprint* → receipt number → quantity, sealed or not, reason → manager approves → refund is **cash**. |
| 15 | **Lock** | Till menu → *Lock screen* when stepping away; PIN to unlock. |
| 16 | **Hand over** | Till menu → *Switch cashier*, never "just use" the open screen. |
| 17 | **Cash drop** | Too much cash: till menu → *Cash drop to safe*, a manager witnesses. |
| 18 | **End of shift** | Till menu → *End shift* → count notes and coins → *Submit*. If short or over, write why. |
| 19 | **Internet down** | Keep selling cash and card; for M-PESA type the code. Don't close the browser until the offline sales have been sent. |

**Cashier sign-off checklist:** the trainee alone does:

- ☐ A cash sale with change.
- ☐ An M-PESA sale with a code.
- ☐ A sale with 2 tots and 1 bottle.
- ☐ A held table tab, recalled and paid.
- ☐ A return with a manager's approval.
- ☐ A lock and unlock.
- ☐ A switch cashier.
- ☐ An end-of-shift count.

### 8.2 Branch managers / supervisors (60 minutes)

**What a manager is responsible for:** approvals at the till, the safe, and checking each cashier's cash-up.

- **At the till, with their PIN:** discounts above the cashier's limit, voids, price changes, refunds, cash drops, payouts, unlocking another person's till. The manager types the PIN, never the cashier. Every approval carries the manager's name in the *Voids, discounts & price overrides* report.
- **Cash-up sign-off:** *Sales & Shifts → Shifts & cash-ups*. Check each closed shift, read the reason for any difference, add a note and sign off. Nobody can sign off their own.
- **Receiving stock:** *Inventory → Receive stock* against an approved purchase order. Count before posting; a posted delivery cannot be edited.
- **Adjustments:** approve or reject breakages (*Inventory → Breakages & adjustments*). Check the reason makes sense.
- **Stock counts:** start a blind count of one shelf each week; review and approve the differences.
- **Notifications bell:** low stock, cash shortages, unconfirmed M-PESA payments.
- **M-PESA reconciliation:** *Payments → M-PESA reconciliation*. Match each code the cashiers typed against the M-PESA statement. A code with no matching payment is a problem to chase the same day.

### 8.3 Storekeeper (45 minutes)

Covers: receive a delivery, record a breakage, move stock from the back store to the shop floor (*Transfers*), and run a stock count (chapter 7 of the user guide).

### 8.4 Brian, the owner (90 minutes)

**What the owner is responsible for:** prices and promotions, staff accounts, approvals no one else may give, and checking the money every day.

1. **Dashboard:** today's sales and profit, cash shortages, low stock, and shifts waiting for sign-off.
2. **Approvals waiting:** price changes (*Catalogue → Price changes*), promotions and purchase orders.
3. **Prices and promotions:** change a price with a start date; create a happy-hour promotion (days, hours, % off) and approve it.
4. **Staff:** add a user, set a PIN, deactivate a leaver, reset a forgotten password (*Edit* → new password).
5. **Reports** (*Reports*): the ones to know are in the table below.
6. **Purchasing:** raise a purchase order, approve it (a different person from the one who raised it), receive it, record the supplier invoice.
7. **Expenses and licences:** record expenses; enter the liquor licence and its expiry date so he is warned before it runs out.
8. **Audit log:** who did what, and when (*Administration → Audit log*).

| Report | Answers |
| --- | --- |
| *Sales summary* | What was sold, by day. |
| *Sales by cashier / tender* | How much each cashier took in cash, M-PESA and card. |
| *Profit* | The margin by product and category. |
| *Voids, discounts & price overrides* | Who gave what away, and who approved it. |
| *Low stock* | What to order. |
| *Stock count variance*, *Adjustments & losses by reason* | Where stock is disappearing. |

---

## Part 9. Routines

| When | Who | What |
| --- | --- | --- |
| **Opening** | Manager | Count the float into each drawer; cashiers sign in and start their shift. |
| **During the shift** | Cashiers / manager | Cash drop to the safe when the drawer gets full. Hold table tabs with the table name. |
| **Closing** | Cashiers | Finish or void every held sale, end shift, count the drawer. |
| **Closing** | Manager | Sign off every cash-up; match the day's M-PESA codes; check the notifications bell. |
| **Every evening** | Brian | Dashboard: sales, shortages, voids and discounts. |
| **Every week** | Manager / storekeeper | Blind count of one or two shelves; raise purchase orders for low stock. |
| **Every week** | You (Tessera) | Check the backups ran and copy one off the server. |
| **Every month** | Brian / accountant | Profit report, supplier balances, expenses, a full stock count; export for the accountant. |

---

## Part 10. Golden rules, common problems and support

### Golden rules (tell everyone)

1. **Your own account, your own PIN.** Never share, never sign in for someone else.
2. **A sale can never be edited or deleted.** Mistakes are fixed with a return, which keeps the record honest.
3. **Count before you post.** Deliveries, counts and adjustments cannot be changed afterwards.
4. **M-PESA: see the message.** Never complete an M-PESA sale without seeing the confirmation message.
5. **Lock the till when you step away.** Switch cashier for a hand-over.
6. **Don't clear the till browser's data.** Offline sales waiting there would be lost.

### Common problems

| Problem | Fix |
| --- | --- |
| Forgot back-office password | Brian or you: *Users & roles → ⋯ → Edit* → a new password; they change it at sign-in. There is no reset by email. |
| Forgot till PIN | *Users & roles → ⋯ → Change till PIN*. |
| "You still have an open shift on Till 1" | Go back to that till and end the shift, or ask a manager. |
| Till refuses a drink: "Outside licensed hours" | The licensed-hours lock. Check *Settings → Sales screen → Licensed hours*. |
| Till says the drink is out of stock | Opening stock is missing or wrong: a manager approves the sale, then fix it with a stock count. |
| Nothing prints | Printer on, paper in, plugged in → till menu → *Printer → Print test page*. Reprint from *Return / reprint*. |
| Lost or stolen till device | *Administration → Branches → Tills → Disconnect*. Set up the new device with the same till name. |
| Someone left | *Users & roles → Edit* → untick *Account active* the same day. |
| Site does not open | Check the internet. The till keeps selling cash and card offline. If the back office is down for everyone, call Tessera support. |

### Support

Brian's staff go to **Brian or the manager first**; Brian contacts **Tessera support** (you) with:

- the screen name,
- what they tried,
- a photo of the screen.
