// Settings demo briefing: one picture per Settings section, plus "Settings for" and a setting's history.
// Saves to <GUIDE_IMAGES>/settings. Read-only: nothing is saved. See guide-capture/README.md.
import { BACK_OFFICE, launch, newPage, open, settle, shot, signIn } from "./lib.mjs";

const F = "settings";
const browser = await launch();
const page = await newPage(browser, BACK_OFFICE);
const main = page.locator("main");
await signIn(page, "owner@tessera.test");
await open(page, "/admin/settings");

const section = async (name) => {
  await main.locator("a").filter({ hasText: new RegExp(`^${name}`) }).first().click();
  await settle(page, 1200);
};

await shot(page, F, "01-overview", [
  [main.getByRole("textbox", { name: /Settings for/ }).or(main.locator("input").first()), 1],
  [main.locator("a").filter({ hasText: /^Business/ }).first(), 2],
  [main.getByRole("button", { name: "Save" }).first(), 3],
]);

// "Settings for": the whole business, a branch or a till.
await main.locator("input").first().click();
await settle(page, 600);
await shot(page, F, "02-settings-for");
await page.keyboard.press("Escape");

// A setting's history: every change is logged and can be undone.
const more = main.getByRole("button", { name: /history|more|actions/i }).first();
await more.click();
await settle(page, 800);
await shot(page, F, "03-history");
await page.keyboard.press("Escape");
await settle(page, 400);

let n = 4;
for (const name of ["Branding", "Receipts", "Sales screen", "Products and stock", "Payments", "Staff and roles", "Notifications", "Integrations"]) {
  await section(name);
  await shot(page, F, `${String(n++).padStart(2, "0")}-${name.toLowerCase().replace(/ /g, "-")}`);
}

await browser.close();
