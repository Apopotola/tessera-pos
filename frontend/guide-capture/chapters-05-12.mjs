// Chapters 1 and 5–12: the back office (sign-in and layout, sales, catalogue, stock, purchasing,
// customers, money, reports, administration). Saves to images/office. Read-only: it opens screens
// and dialogs but submits nothing, so it can be run again on the same shop.
// Needs the user-guide shop (guide-db.sh) served on GUIDE_WEB; see guide-capture/README.md.
import { BACK_OFFICE, WEB, launch, newPage, open, settle, shot, signIn } from "./lib.mjs";

const F = "office";
const browser = await launch();
const page = await newPage(browser, BACK_OFFICE);
const main = page.locator("main");
const firstRow = () => main.locator("table tbody tr:visible").first();
const button = (name, exact = false) => page.getByRole("button", { name, exact }).first();
const closeDialog = async () => {
  await page.keyboard.press("Escape");
  await settle(page, 500);
};
/** Click a chip or segmented tab by its visible text. */
const chip = async (text) => {
  await main.getByText(text, { exact: true }).first().click();
  await settle(page, 900);
};

// ---------------------------------------------------------------- Chapter 1: signing in and the layout
await page.goto(`${WEB}/login`);
await settle(page, 1500);
await shot(page, F, "01-sign-in", [
  [page.getByLabel("Email or phone number"), 1],
  [page.getByLabel(/^Password/), 2],
  [page.getByText("Keep me signed in on this computer"), 3, { box: false }],
  [button("Sign in", true), 4],
]);

await signIn(page, "owner@tessera.test");
await open(page, "/dashboard");
// Open a few screens from the sidebar, as a person would: each one gets a tab.
for (const item of ["Sales", "Stock levels"]) {
  await page.locator("nav").getByText(item, { exact: true }).click();
  await settle(page, 1200);
}
await shot(page, F, "02-the-screen", [
  [page.locator("nav").first(), 1, { box: false }],
  [page.getByRole("tab", { name: /^Sales/ }), 2],
  [button(/notifications/i), 3],
  [button("Account menu"), 4],
  [page.locator("main h2:visible").first(), 5, { box: false }],
]);
await page.getByRole("tab", { name: /^Sales/ }).click({ button: "right" });
await settle(page, 500);
await shot(page, F, "03-tab-menu", [[page.getByRole("menu").first(), 1]]);
await page.keyboard.press("Escape");
await button("Account menu").click();
await settle(page, 500);
await shot(page, F, "04-account-menu", [[page.getByRole("menu").first(), 1]]);
await page.keyboard.press("Escape");

// ---------------------------------------------------------------- Chapter 5: sales and cash-ups
await open(page, "/sales");
await shot(page, F, "05-sales-list", [[main.getByPlaceholder("e.g. S-000123"), 1], [firstRow(), 2]]);
await firstRow().click();
await settle(page, 1200);
await shot(page, F, "06-sale-detail", [[button("Print copy"), 1], [button("Share receipt"), 2]]);
await closeDialog();
await open(page, "/sales/shifts");
await chip("All shifts");
await shot(page, F, "07-shifts", [[main.getByText("Waiting for sign-off", { exact: true }), 1, { box: false }], [firstRow(), 2]]);
await firstRow().click();
await settle(page, 1200);
await shot(page, F, "08-shift-detail");
await closeDialog();

// ---------------------------------------------------------------- Chapter 6: products, prices, promotions
await open(page, "/catalogue/products");
await shot(page, F, "09-products", [[button("New product"), 1], [main.getByPlaceholder("Search name, brand, SKU or barcode"), 2], [firstRow(), 3]]);
await main.getByPlaceholder("Search name, brand, SKU or barcode").fill("Johnnie Walker Black");
await settle(page, 1200);
await firstRow().click();
await settle(page, 1800);
await shot(page, F, "10-product-detail");
await open(page, "/catalogue/products");
await button("New product").click();
await settle(page, 800);
await shot(page, F, "11-new-product");
await closeDialog();
await open(page, "/catalogue/prices");
await chip("Approved");
await shot(page, F, "12-price-changes", [[main.getByText("Pending", { exact: true }), 1, { box: false }]]);
await open(page, "/catalogue/promotions");
await shot(page, F, "13-promotions", [[button("New promotion"), 1], [firstRow(), 2]]);
await button("New promotion").click();
await settle(page, 800);
await shot(page, F, "14-new-promotion");
await closeDialog();
await open(page, "/catalogue/setup");
await shot(page, F, "15-brands-categories", [[main.getByRole("tab", { name: "Categories", exact: true }), 1], [button("Add brand"), 2]]);

// ---------------------------------------------------------------- Chapter 7: stock
await open(page, "/inventory/dashboard");
await shot(page, F, "16-stock-dashboard");
await open(page, "/inventory/stock");
await chip("Low stock (4)");
await shot(page, F, "17-stock-levels-low", [[main.getByText("Low stock (4)", { exact: true }), 1, { box: false }], [firstRow(), 2]]);
await open(page, "/inventory/receive");
await shot(page, F, "18-receive-stock", [[main.getByPlaceholder("Choose the order this delivery is for"), 1], [main.getByPlaceholder("e.g. DN-55821"), 2], [button("Post to stock"), 3]]);
await open(page, "/inventory/adjustments");
await shot(page, F, "19-adjustments-waiting", [[button("Approve"), 1], [button("Reject"), 2]]);
await button("New adjustment").click();
await settle(page, 800);
await shot(page, F, "20-new-adjustment");
await closeDialog();
await open(page, "/inventory/transfers");
await shot(page, F, "21-transfers-in-transit", [[firstRow(), 1], [button("Receive"), 2]]);
await open(page, "/inventory/counts");
await shot(page, F, "22-stock-counts", [[button("Start a count"), 1], [firstRow(), 2]]);
await firstRow().click();
await settle(page, 1800);
await shot(page, F, "23-count-sheet");
await open(page, "/inventory/open-bottles");
await shot(page, F, "24-open-bottles");
await open(page, "/inventory/ledger");
await shot(page, F, "25-stock-ledger", [[firstRow(), 1]]);

// ---------------------------------------------------------------- Chapter 8: purchasing and suppliers
await open(page, "/purchasing/orders");
await chip("Needs approval");
await shot(page, F, "26-orders-needing-approval", [[button("New purchase order"), 1], [firstRow(), 2]]);
await firstRow().click();
await settle(page, 1800);
await shot(page, F, "27-order-detail");
await open(page, "/purchasing/invoices");
await chip("Needs querying");
await shot(page, F, "28-invoice-on-query", [[firstRow(), 1]]);
await open(page, "/purchasing/returns");
await shot(page, F, "29-return-to-supplier", [[firstRow(), 1], [button("Approve"), 2]]);
await open(page, "/purchasing/suppliers");
await shot(page, F, "30-suppliers", [[button("Add supplier"), 1]]);
await open(page, "/purchasing/accounts");
await shot(page, F, "31-supplier-accounts", [[firstRow(), 1]]);
await firstRow().click();
await settle(page, 1800);
await shot(page, F, "32-supplier-statement");

// ---------------------------------------------------------------- Chapter 9: customers and credit
await open(page, "/customers");
await shot(page, F, "33-customers", [[button("Register customer"), 1]]);
await open(page, "/customers/accounts");
await shot(page, F, "34-customer-accounts", [[firstRow(), 1]]);
await firstRow().click();
await settle(page, 1800);
await shot(page, F, "35-customer-credit");

// ---------------------------------------------------------------- Chapter 10: M-PESA, expenses, licences, eTIMS
await open(page, "/payments/mpesa");
await shot(page, F, "36-mpesa-reconciliation", [[main.getByText("Not on a sale", { exact: true }), 1, { box: false }]]);
await open(page, "/expenses");
await shot(page, F, "37-expenses", [[button("Record expense"), 1], [button("Approve"), 2]]);
await button("Record expense").click();
await settle(page, 800);
await shot(page, F, "38-record-expense");
await closeDialog();
await open(page, "/compliance/licences");
await shot(page, F, "39-licences", [[button("Add licence or permit"), 1], [button("Renew"), 2]]);
await open(page, "/compliance/etims");
await shot(page, F, "40-etims-monitor");

// ---------------------------------------------------------------- Chapter 11: dashboard and reports
await open(page, "/reports");
await shot(page, F, "41-reports");
await page.getByText("Revenue, cost of goods & gross profit", { exact: true }).click();
await settle(page, 2000);
await shot(page, F, "42-report-profit", [[button("Export CSV"), 1], [button("Download PDF"), 2], [button("Print"), 3]]);

// ---------------------------------------------------------------- Chapter 12: administration
await open(page, "/admin/branches");
await shot(page, F, "43-branches-tills", [[button("Disconnect"), 1]]);
await open(page, "/admin/users");
await shot(page, F, "44-users", [[button("Add user"), 1]]);
await button("Add user").click();
await settle(page, 800);
await shot(page, F, "45-add-user");
await closeDialog();
await open(page, "/admin/settings");
await shot(page, F, "46-settings", [[main.locator("a").filter({ hasText: /^Business/ }).first(), 1], [button("Save").first(), 2]]);
for (const [section, name] of [["Receipts", "47-settings-receipts"], ["Sales screen", "48-settings-sales-screen"], ["Integrations", "49-settings-integrations"], ["Notifications", "50-settings-notifications"]]) {
  await main.locator("a").filter({ hasText: new RegExp(`^${section}`) }).first().click();
  await settle(page, 1200);
  await shot(page, F, name);
}
await open(page, "/admin/audit-log");
await shot(page, F, "51-audit-log", [[main.getByPlaceholder(/Filter by exact action/), 1]]);

await browser.close();
