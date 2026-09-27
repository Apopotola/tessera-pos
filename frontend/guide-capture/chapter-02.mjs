// Chapter 2 "How everything connects": one business day, in order. Saves to images/overview.
// Needs the user-guide shop (UserGuideSeeder) served on GUIDE_WEB; see guide-capture/README.md.
import { BACK_OFFICE, TILL, WEB, launch, newPage, open, settle, shot, signIn, typePin } from "./lib.mjs";

const F = "overview";
const browser = await launch();

// ---------------------------------------------------------------- 1–2: set-up and stock (owner)
const office = await newPage(browser, BACK_OFFICE);
await signIn(office, "owner@tessera.test");

await open(office, "/admin/settings");
await shot(office, F, "01-settings-business", [
  [office.locator("main a").filter({ hasText: /^Business/ }).first(), 1],
  [office.locator('main input[value="Karen Wines & Spirits"]'), 2],
  [office.getByRole("button", { name: "Save" }).first(), 3],
]);

await open(office, "/admin/branches");
await shot(office, F, "02-branches-and-tills", [[office.getByText("Tills", { exact: true }).first(), 1, { box: false }]]);

await open(office, "/admin/users");
await shot(office, F, "03-users-and-roles", [
  [office.getByRole("button", { name: "Add user" }), 1],
  [office.getByRole("button", { name: "Actions for Amina Hassan" }), 2],
]);

await open(office, "/catalogue/products");
await shot(office, F, "04-products", [
  [office.getByRole("button", { name: "New product" }), 1],
  [office.getByPlaceholder("Search name, brand, SKU or barcode"), 2],
]);

await open(office, "/purchasing/orders");
await office.getByText("Received", { exact: true }).click();
await settle(office);
await shot(office, F, "05-purchase-order-received", [[office.locator("main table tbody tr:visible").first(), 1]]);

await open(office, "/inventory/stock");
await shot(office, F, "06-stock-on-hand", [[office.locator("main table tbody tr:visible").first(), 1]]);

// ---------------------------------------------------------------- 3–5: the till at Westlands (Amina)
const till = await newPage(browser, TILL);
await till.goto(`${WEB}/till`);
await settle(till);
await till.getByRole("link", { name: "Manager sign in" }).click();
await settle(till);
for (let attempt = 0; attempt < 3 && till.url().includes("/login"); attempt++) {
  await till.getByLabel("Email or phone number").fill("owner@tessera.test");
  await till.getByLabel(/^Password/).fill("password");
  await till.getByRole("button", { name: "Sign in" }).click();
  await till.waitForTimeout(3500);
}
await till.getByRole("heading", { name: "Set up this device as a till" }).waitFor();
await till.getByRole("textbox", { name: "Branch" }).click();
await till.getByRole("option", { name: /Westlands/ }).click();
await till.getByLabel("Till name").fill("Till 1");
await till.getByLabel("Location").fill("Front counter");
await till.getByRole("button", { name: "Set up till" }).click();
await till.getByRole("heading", { name: "Who's on the till?" }).waitFor();

await till.getByRole("button", { name: /Amina/ }).click();
await typePin(till, "36");
await shot(till, F, "07-till-cashier-signs-in", [
  [till.getByRole("button", { name: /Amina/ }), 1],
  [till.locator("[aria-label*='digits entered']"), 2, { box: false }],
  [till.getByRole("button", { name: "Start shift" }), 3],
]);
await typePin(till, "91");
await till.keyboard.press("Enter");
await till.getByText("Cart is empty").waitFor();

const search = till.getByPlaceholder(/Scan a barcode/);
await search.fill("Nederburg");
await till.getByText("NED-CS-750", { exact: true }).first().click();
await till.getByRole("button", { name: "One more" }).click();
await search.fill("Coca");
await till.getByText("COKE-500", { exact: true }).first().click();
await settle(till, 600);
await shot(till, F, "08-till-selling", [
  [search, 1],
  [till.getByText("Nederburg Cabernet", { exact: false }).last(), 2, { box: false }],
  [till.getByRole("button", { name: /^Pay / }), 3],
]);

await till.getByRole("button", { name: /^Pay / }).click();
// Settings may ask for an age check first.
const age = till.getByRole("button", { name: "Yes, 18 or over" });
const tender = till.getByRole("dialog", { name: "Take payment" });
await age.or(tender).first().waitFor();
if (await age.isVisible()) {
  await shot(till, F, "09a-age-check", [[age, 1]]);
  await age.click();
}
await tender.waitFor();
await tender.getByLabel("Customer's M-PESA number").fill("0712 000 123");
await shot(till, F, "09-take-payment-mpesa", [
  [tender.getByText("M-PESA", { exact: true }).first(), 1],
  [tender.getByLabel("Customer's M-PESA number"), 2],
  [tender.getByRole("button", { name: /^Send .* request/ }), 3],
]);
await tender.getByRole("button", { name: /^Send .* request/ }).click();
await tender.getByText(/M-PESA received/).waitFor({ timeout: 30_000 });
await shot(till, F, "10-mpesa-received", [
  [tender.getByText(/M-PESA received/), 1],
  [tender.getByRole("button", { name: "Complete sale" }), 2],
]);
await tender.getByRole("button", { name: "Complete sale" }).click();
const done = till.getByRole("dialog", { name: /Sale .* complete/ });
await done.waitFor();
const saleNumber = ((await done.getByRole("heading").first().textContent()) ?? "").replace(/^Sale | complete$/g, "").trim();
await shot(till, F, "11-sale-complete-receipt", [
  [done.locator("[class*=receiptPreview]"), 1],
  [done.getByRole("button", { name: "Print receipt" }), 2],
  [done.getByRole("button", { name: "Next customer" }), 3],
]);
await done.getByRole("button", { name: "Next customer" }).click();

// ---------------------------------------------------------------- 5–6: what changed elsewhere (owner)
await open(office, "/compliance/etims");
await shot(office, F, "12-etims-monitor", [[office.locator("main table tbody tr:visible").first(), 1]]);
await open(office, "/inventory/ledger");
await shot(office, F, "13-stock-ledger", [[office.locator("main table tbody tr:visible").first(), 1]]);
await open(office, "/dashboard");
await office.getByRole("button", { name: /notifications/i }).click();
await settle(office, 800);
await shot(office, F, "14-notifications", [[office.getByRole("button", { name: /notifications/i }), 1], [office.locator(".mantine-Popover-dropdown").first(), 2]]);
await office.keyboard.press("Escape");

// ---------------------------------------------------------------- 7: a return, approved by the manager
await till.getByRole("button", { name: "Till menu" }).click();
await till.getByRole("menuitem", { name: /Return \/ reprint/ }).click();
const ret = till.getByRole("dialog", { name: "Return or reprint" });
await ret.getByLabel("Receipt number").fill(saleNumber);
await ret.getByRole("button", { name: "Find" }).click();
await ret.locator("table tbody tr").first().waitFor();
const qty = ret.locator("table tbody tr").first().locator("input").first();
await qty.fill("1");
await ret.getByLabel("Reason").fill("Customer returned one sealed bottle: bought the wrong wine.");
const refundText = (await ret.getByText(/^Refund Ksh/).textContent()) ?? "";
await shot(till, F, "15-return-find-sale", [
  [ret.getByLabel("Receipt number"), 1],
  [qty, 2],
  [ret.getByLabel("Reason"), 3],
  [ret.getByRole("button", { name: "Refund" }), 4],
]);
await ret.getByRole("button", { name: "Refund" }).click();
const approval = till.getByRole("dialog", { name: "Manager approval" });
if (await approval.isVisible({ timeout: 5000 }).catch(() => false)) {
  await approval.getByRole("textbox", { name: "Manager", exact: true }).click();
  await till.getByRole("option", { name: /Wanjiru/ }).click();
  await approval.getByLabel("Manager PIN").fill("4826");
  await shot(till, F, "16-manager-approves-refund", [
    [approval.getByRole("textbox", { name: "Manager", exact: true }), 1],
    [approval.getByLabel("Manager PIN"), 2],
    [approval.getByRole("button", { name: "Approve" }), 3],
  ]);
  await approval.getByRole("button", { name: "Approve" }).click();
}
// The refund is confirmed in a message at the top right (it fades after a few seconds).
const recorded = till.getByText(/^Return on .* recorded$/);
await recorded.waitFor();
await shot(till, F, "17-return-recorded", [[recorded.locator(".."), 1]]);
// Close the message: it covers the till menu button.
await till.locator(".mantine-Notification-closeButton").first().click();
await settle(till, 500);

// ---------------------------------------------------------------- 8: end of shift (cash drawer count)
const refundCents = Math.round(Number(refundText.replace(/[^\d.]/g, "")) * 100);
let left = 500000 - refundCents; // float 5,000 less the cash refund
await till.getByRole("button", { name: "Till menu" }).click();
await till.getByRole("menuitem", { name: /^End shift/ }).click();
const count = till.getByRole("dialog", { name: /End shift/ });
for (const [cents, label] of [[100000, "KES 1,000 notes"], [50000, "KES 500 notes"], [20000, "KES 200 notes"], [10000, "KES 100 notes"], [5000, "KES 50 notes"], [2000, "KES 20 coins"], [1000, "KES 10 coins"], [500, "KES 5 coins"], [100, "KES 1 coins"]]) {
  const pieces = Math.floor(left / cents);
  if (pieces > 0) await count.getByLabel(`Number of ${label}`).fill(String(pieces));
  left -= pieces * cents;
}
await shot(till, F, "18-end-shift-count", [
  [count.getByLabel("Number of KES 1,000 notes"), 1],
  [count.getByText("Total counted"), 2, { box: false }],
  [count.getByRole("button", { name: "Submit count" }), 3],
]);
await count.getByRole("button", { name: "Submit count" }).click();
const ended = till.getByRole("dialog", { name: "Shift ended" });
await ended.waitFor();
await shot(till, F, "19-shift-ended", [[ended.getByText("Expected", { exact: true }).locator(".."), 1], [ended.getByText("Counted", { exact: true }).locator(".."), 2], [ended.getByText("Variance", { exact: true }).locator(".."), 3]]);
await ended.getByRole("button", { name: "Done" }).click();

// ---------------------------------------------------------------- 8: the manager signs off the cash-up
const manager = await newPage(browser, BACK_OFFICE);
await signIn(manager, "manager@tessera.test");
await open(manager, "/sales/shifts");
await shot(manager, F, "20-shifts-and-cash-ups", [[manager.locator("main table tbody tr:visible").first(), 1]]);

// ---------------------------------------------------------------- 9–10: the owner's view and month end
await open(office, "/dashboard");
await shot(office, F, "21-dashboard", [
  [office.getByText("Sales today", { exact: true }).first(), 1, { box: false }],
  [office.getByText("Items low on stock", { exact: true }).first(), 2, { box: false }],
  [office.getByText("Owed to suppliers", { exact: true }).first(), 3, { box: false }],
]);
await open(office, "/reports");
await shot(office, F, "22-reports");
await office.getByText("Low stock & reorder suggestions", { exact: true }).click();
await settle(office, 1500);
await shot(office, F, "23-low-stock-report");
await open(office, "/purchasing/orders");
await office.getByRole("button", { name: "New purchase order" }).click();
await settle(office, 800);
const po = office.getByRole("dialog", { name: "New purchase order" });
await shot(office, F, "24-new-purchase-order", [
  [po.getByRole("textbox", { name: /^Supplier/ }), 1],
  [po.getByPlaceholder("Search or scan an item"), 2],
  [po.getByRole("button", { name: "Save draft" }), 3],
]);
await office.keyboard.press("Escape");
await open(office, "/reports");
await office.getByText("Sales summary", { exact: true }).click();
await settle(office, 1500);
await shot(office, F, "25-sales-summary");

await browser.close();
