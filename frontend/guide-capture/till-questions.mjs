// Till questions briefing: the bill before paying, swapping a drink, and selling more than the shelf holds.
// Saves to <GUIDE_IMAGES>/questions. Makes two real sales on the guide shop; see guide-capture/README.md.
import fs from "node:fs";
import path from "node:path";
import { IMAGES, SHOP, TILL, WEB, launch, named, newPage, settle, shot, signBackIn, typePin } from "./lib.mjs";

const F = "questions";
const browser = await launch();
const till = await newPage(browser, TILL);
// The browser print dialog would block: keep what would have printed instead.
await till.addInitScript(() => {
  window.__prints = [];
  window.print = () => window.__prints.push(document.querySelector(".tessera-print")?.innerHTML ?? "");
});
const button = (name, exact = false) => till.getByRole("button", { name, exact }).first();
const dialog = (name) => till.getByRole("dialog", { name });
const search = till.getByPlaceholder(/Scan a barcode/);

/** The last printed bill or receipt, shown on a plain background and saved on its own. */
async function paper(name) {
  await till.waitForFunction(() => window.__prints.length > 0);
  await till.evaluate(() => {
    const html = window.__prints.pop();
    const layer = document.createElement("div");
    layer.id = "guide-paper";
    layer.style.cssText = "position:fixed;inset:0;z-index:2147483646;background:#e9ecef;display:flex;justify-content:center;align-items:flex-start;padding:24px;overflow:auto";
    layer.innerHTML = `<div style="background:#fff;width:340px;padding:16px;box-shadow:0 2px 10px rgba(0,0,0,.2)">${html}</div>`;
    document.body.appendChild(layer);
  });
  await till.waitForTimeout(500);
  fs.mkdirSync(path.join(IMAGES, F), { recursive: true });
  await till.locator("#guide-paper > div").screenshot({ path: path.join(IMAGES, F, `${name}.png`) });
  await till.evaluate(() => document.getElementById("guide-paper")?.remove());
  console.log(`saved ${F}/${name}.png`);
}

async function add(text, sku) {
  await search.fill(text);
  await settle(till, 1500);
  await till.getByText(sku, { exact: true }).first().click();
  await settle(till, 600);
}

async function pay() {
  await button(/^Complete sale/).click();
  const age = button("Yes, 18 or over");
  const tender = dialog("Take payment");
  await age.or(tender).first().waitFor();
  if (await age.isVisible()) await age.click();
  await tender.waitFor();
  await settle(till, 600);
  await tender.getByText("Cash", { exact: true }).first().click();
  await tender.getByRole("button", { name: /^[\d,]+$/ }).first().click();
  await settle(till, 400);
  return tender;
}

// ---------------------------------------------------------------- The till, signed in as a cashier
await till.goto(`${WEB}/till`);
await settle(till);
await till.getByRole("link", { name: "Manager sign in" }).click();
await settle(till);
for (let attempt = 0; attempt < 3 && till.url().includes("/login"); attempt++) {
  await till.getByLabel("Email or phone number").fill("owner@tessera.test");
  await till.getByLabel(/^Password/).fill("password");
  await button("Sign in").click();
  await till.waitForURL((url) => !url.pathname.startsWith("/login"), { timeout: 20000 }).catch(() => undefined);
}
await till.getByRole("heading", { name: "Set up this device as a till" }).waitFor();
await till.getByRole("textbox", { name: "Branch" }).click();
await till.getByRole("option", { name: named(SHOP.branch) }).click();
await till.getByLabel("Till name").fill("Till 1");
await till.getByLabel("Location").fill("Main counter");
await button("Set up till").click();
await till.getByRole("heading", { name: "Who's on the till?" }).waitFor();
await settle(till);
await button(/Otieno/).click();
await typePin(till, "2580");
await till.keyboard.press("Enter");
const empty = till.getByText("Cart is empty");
const locked = till.getByText("Till locked");
await empty.or(locked).first().waitFor({ timeout: 20000 });
if (await locked.isVisible()) {
  await typePin(till, "2580");
  await till.keyboard.press("Enter");
}
await settle(till, 1500);
if (!(await empty.isVisible())) await button("Void").click();
await empty.waitFor();

// ---------------------------------------------------------------- 1. The bill first, payment after
await add("Captain Morgan", "CM-750");
await add("Coca-Cola", "COKE-500");
await button("One more").last().click();
await settle(till, 800);
await shot(till, F, "01-print-bill", [[button("Print bill"), 1], [till.getByLabel("Sale total"), 2, { box: false }]]);
await button("Print bill").click();
await paper("02-the-bill");

await button("Hold sale").click();
const park = dialog(/^Park sale/);
await park.waitFor();
await park.getByLabel("Who is it for? (optional)").fill("Table 4");
await shot(till, F, "03-hold-for-table", [[park.getByLabel("Who is it for? (optional)"), 1], [park.getByRole("button", { name: "Park sale" }), 2]]);
await park.getByRole("button", { name: "Park sale" }).click();
await settle(till, 1200);

await button("Till menu").click();
await till.getByRole("menuitem", { name: /^Recall parked/ }).click();
await settle(till, 800);
const parked = dialog("Parked sales");
await shot(till, F, "04-recall-table", [[parked.getByText("Table 4"), 1]]);
await parked.getByText("Table 4").click();
await settle(till, 1200);

const tender = await pay();
await shot(till, F, "05-take-payment", [[tender.getByRole("button", { name: "Complete sale" }), 1]]);
// The customer and cashier receipts print by themselves when the sale completes.
await till.evaluate(() => (window.__prints = []));
await tender.getByRole("button", { name: "Complete sale" }).click();
const done = dialog(/Sale .* complete/);
await done.waitFor();
await settle(till, 800);
// Two print jobs: the customer's copy, then the cashier's. Show the customer's.
await till.waitForFunction(() => window.__prints.length >= 2);
await till.evaluate(() => window.__prints.pop());
await paper("06-the-receipt");
await signBackIn(till, /Otieno/, "2580");

// ---------------------------------------------------------------- 2. Swapping a drink before paying
await add("Captain Morgan", "CM-750");
await shot(till, F, "07-served", [[till.locator("[class*=cartLine]").first(), 1]]);
await till.locator("[class*=cartLine] button").first().click();
const edit = till.getByRole("dialog").last();
await edit.waitFor();
await settle(till, 500);
await shot(till, F, "08-remove-item", [[edit.getByRole("button", { name: "Remove item" }), 1]]);
await edit.getByRole("button", { name: "Remove item" }).click();
await settle(till, 1000);
await add("Hunter's", "HUN-330");
await shot(till, F, "09-swapped", [[till.locator("[class*=cartLine]").first(), 1], [till.getByLabel("Sale total"), 2, { box: false }]]);
await button("Void").click();
await settle(till, 1000);

// ---------------------------------------------------------------- 3. More than the shelf holds (borrowed from a neighbour)
await add("Four Cousins", "FC-ROSE-1500");
await till.locator("[class*=cartLine] button").first().click();
const qty = till.getByRole("dialog").last();
await qty.waitFor();
await qty.getByLabel("Quantity").fill("9");
await qty.getByRole("button", { name: "Save" }).click();
await settle(till, 800);
const paying = await pay();
await paying.getByRole("button", { name: "Complete sale" }).click();
const approval = dialog("Manager approval");
await approval.waitFor();
await approval.getByRole("textbox", { name: "Manager", exact: true }).click();
await till.getByRole("option", { name: /Wanjiru/ }).click();
await approval.getByLabel("Manager PIN").fill("4826");
await shot(till, F, "10-below-zero-approval", [
  [approval.getByRole("textbox", { name: "Manager", exact: true }), 1],
  [approval.getByLabel("Manager PIN"), 2],
  [approval.getByRole("button", { name: "Approve" }), 3],
]);
await approval.getByRole("button", { name: "Approve" }).click();
await dialog(/Sale .* complete/).waitFor();
await settle(till, 800);

await browser.close();
