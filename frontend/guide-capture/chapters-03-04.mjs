// Chapters 3 and 4: selling at the till, and the till during and after a shift. Saves to images/till.
// Sets up a till at Kobil Imara Daima and makes one cash sale and one parked sale; ends locked.
// Needs the user-guide shop (guide-db.sh) served on GUIDE_WEB; see guide-capture/README.md.
import { TILL, WEB, launch, newPage, settle, shot, typePin } from "./lib.mjs";

const F = "till";
const browser = await launch();
const till = await newPage(browser, TILL);
const button = (name, exact = false) => till.getByRole("button", { name, exact }).first();
const dialog = (name) => till.getByRole("dialog", { name });
const escape = async () => {
  await till.keyboard.press("Escape");
  await settle(till, 500);
};
const menu = async (item) => {
  await button("Till menu").click();
  await settle(till, 400);
  if (item) {
    await till.getByRole("menuitem", { name: item }).click();
    await settle(till, 800);
  }
};

// ---------------------------------------------------------------- Chapter 3: setting up the till, signing in
await till.goto(`${WEB}/till`);
await settle(till);
await till.getByRole("link", { name: "Manager sign in" }).click();
await settle(till);
for (let attempt = 0; attempt < 3 && till.url().includes("/login"); attempt++) {
  await till.getByLabel("Email or phone number").fill("owner@tessera.test");
  await till.getByLabel(/^Password/).fill("password");
  await button("Sign in").click();
  await till.waitForTimeout(3500);
}
await till.getByRole("heading", { name: "Set up this device as a till" }).waitFor();
await till.getByRole("textbox", { name: "Branch" }).click();
await till.getByRole("option", { name: /Imara Daima/ }).click();
await till.getByLabel("Till name").fill("Till 1");
await till.getByLabel("Location").fill("Main counter");
await shot(till, F, "01-set-up-till", [
  [till.getByRole("textbox", { name: "Branch" }), 1],
  [till.getByLabel("Till name"), 2],
  [till.getByLabel("Location"), 3],
  [button("Set up till"), 4],
]);
await button("Set up till").click();
await till.getByRole("heading", { name: "Who's on the till?" }).waitFor();
await settle(till);
await shot(till, F, "02-whos-on-the-till", [[button(/Otieno/), 1]]);

await button(/Otieno/).click();
await typePin(till, "25");
await shot(till, F, "03-enter-pin", [[till.locator("[aria-label*='digits entered']"), 1, { box: false }]]);
await typePin(till, "80");
await till.keyboard.press("Enter");
await till.getByText("Cart is empty").waitFor();
await settle(till, 1500);

const search = till.getByPlaceholder(/Scan a barcode/);
const chips = till.getByRole("tablist", { name: "Categories" });
await shot(till, F, "04-sell-screen", [
  [search, 1],
  [chips, 2],
  [till.locator("[class*=results] button:visible").first(), 3],
  [button(/Walk-in customer/), 4],
  [button("Complete sale", true), 5],
]);
await chips.getByText("All", { exact: true }).click();
await settle(till, 900);
await shot(till, F, "05-category-all", [[chips.getByText("All", { exact: true }), 1]]);

// Adding items: by search, a tot from an open bottle, and one more of the same.
await search.fill("Johnnie Walker");
await settle(till, 1500);
const tot = till.getByText(/^Tot \d+ml$/).first();
const marks = [[search, 1]];
if (await tot.isVisible().catch(() => false)) marks.push([tot, 2]);
await shot(till, F, "06-search-results", marks);
await till.getByText("JWB-750", { exact: true }).first().click();
// A tot too, so the open-bottles screen (chapter 7) has a bottle on it.
if (await tot.isVisible().catch(() => false)) await tot.click();
await search.fill("Coca-Cola");
await settle(till, 1500);
await till.locator("[class*=results] button:visible").first().click();
await settle(till, 600);
await button("One more").click();
await settle(till, 800);
await shot(till, F, "07-cart", [
  [till.getByRole("button", { name: "One less" }).last(), 1],
  [till.getByRole("button", { name: "One more" }).last(), 2],
  [till.getByLabel("Sale total"), 3, { box: false }],
]);

// Changing a line: price and discount, with a manager's approval when over the cashier's limit.
await till.locator("[class*=cartLine] button").first().click();
const edit = till.getByRole("dialog").last();
await edit.waitFor();
await settle(till, 500);
const discount = edit.getByLabel("Discount on this line (KES)");
const hasDiscount = await discount.isVisible().catch(() => false);
if (hasDiscount) await discount.fill("500");
await shot(till, F, "08-change-line", hasDiscount ? [[edit.getByLabel("Quantity"), 1], [discount, 2], [edit.getByRole("button", { name: "Save" }), 3]] : [[edit.getByLabel("Quantity"), 1]]);
await edit.getByRole("button", { name: hasDiscount ? "Save" : "Cancel" }).click();
const approval = dialog("Manager approval");
if (await approval.isVisible({ timeout: 4000 }).catch(() => false)) {
  await approval.getByRole("textbox", { name: "Manager", exact: true }).click();
  await till.getByRole("option", { name: /Wanjiru/ }).click();
  await approval.getByLabel("Manager PIN").fill("4826");
  await shot(till, F, "09-manager-approval", [
    [approval.getByRole("textbox", { name: "Manager", exact: true }), 1],
    [approval.getByLabel("Manager PIN"), 2],
    [approval.getByRole("button", { name: "Approve" }), 3],
  ]);
  await approval.getByRole("button", { name: "Approve" }).click();
}
await settle(till, 1000);

// Choosing a customer.
await button(/Walk-in customer/).click();
await settle(till, 1000);
await shot(till, F, "10-customer", [[dialog("Customer").getByPlaceholder("Business name or KRA PIN"), 1]]);
await escape();

// The till menu.
await menu();
await shot(till, F, "11-till-menu", [[till.getByRole("menu").first(), 1, { box: false }]]);
await till.keyboard.press("Escape");

// Taking payment: cash.
await button("Complete sale", true).click();
const age = button("Yes, 18 or over");
const tender = dialog("Take payment");
await age.or(tender).first().waitFor();
if (await age.isVisible()) {
  await shot(till, F, "12-age-check", [[age, 1]]);
  await age.click();
}
await tender.waitFor();
await settle(till, 600);
await tender.getByText("Split", { exact: true }).click();
await settle(till, 500);
await shot(till, F, "13-split-payment", [[tender.getByText("Split", { exact: true }), 1]]);
await tender.getByText("Cash", { exact: true }).first().click();
await settle(till, 500);
const quick = tender.getByRole("button", { name: /^[\d,]+$/ }).first();
await quick.click();
await settle(till, 500);
await shot(till, F, "14-take-payment-cash", [
  [tender.locator("label").filter({ hasText: /^Cash$/ }), 1],
  [tender.getByLabel("Cash received (KES)"), 2],
  [quick, 3],
  [tender.getByRole("button", { name: "Complete sale" }), 4],
]);
await tender.getByRole("button", { name: "Complete sale" }).click();
const done = dialog(/Sale .* complete/);
await done.waitFor();
await settle(till, 800);
await shot(till, F, "15-sale-complete", [
  [done.getByRole("button", { name: "Print receipt" }), 1],
  [done.getByRole("button", { name: "Share receipt" }), 2],
  [done.getByRole("button", { name: "Next customer" }), 3],
]);
await done.getByRole("button", { name: "Share receipt" }).click();
await settle(till, 1500);
await shot(till, F, "16-share-receipt", [[till.getByRole("dialog").last(), 1, { box: false }]]);
await escape();
if (await done.isVisible().catch(() => false)) await done.getByRole("button", { name: "Next customer" }).click();
await settle(till, 800);

// Parking a sale and recalling it.
await search.fill("Tusker");
await settle(till, 1500);
await till.locator("[class*=results] button:visible").first().click();
await settle(till, 600);
await button("Hold sale").click();
const park = dialog(/^Park sale/);
await park.waitFor();
await park.getByLabel("Who is it for? (optional)").fill("Man in blue shirt");
await shot(till, F, "17-park-sale", [[park.getByLabel("Who is it for? (optional)"), 1], [park.getByRole("button", { name: "Park sale" }), 2]]);
await park.getByRole("button", { name: "Park sale" }).click();
await settle(till, 1200);
await menu(/^Recall parked/);
await shot(till, F, "18-recall-parked", [[dialog("Parked sales").getByText("Man in blue shirt"), 1, { box: false }]]);
await escape();

// ---------------------------------------------------------------- Chapter 4: during and after a shift
await menu("Return / reprint");
const ret = dialog("Return or reprint");
await shot(till, F, "19-return-reprint", [[ret.getByLabel("Receipt number"), 1], [ret.getByRole("button", { name: "Find" }), 2]]);
await escape();
await menu("Printer");
await shot(till, F, "20-printer");
await escape();
await menu(/^Cash drop to safe/);
await dialog("Cash drop to the safe").getByLabel("Amount (KES)").fill("10000");
await shot(till, F, "21-cash-drop", [[dialog("Cash drop to the safe").getByLabel("Amount (KES)"), 1]]);
await escape();
await menu(/^Pay out cash/);
await shot(till, F, "22-pay-out", [[dialog("Pay out cash for an expense").getByLabel("What for"), 1]]);
await escape();
await menu(/^Lock screen/);
await till.getByText("Till locked").waitFor();
await shot(till, F, "23-lock-screen");

await browser.close();
