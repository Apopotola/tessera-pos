import { expect, type Page, request, test } from "@playwright/test";

/**
 * A cashier's shift on a new till, through the real screens: set-up by the owner, PIN sign-in,
 * a cash sale with change, a promotion, a sale on account, lock / unlock, licensed hours and the cash-up.
 * Demo data comes from the seeders (see e2e/global-setup.ts); amounts are the demo prices.
 */
test.describe.configure({ mode: "serial" });

let page: Page;

test.beforeAll(async ({ browser }) => {
  page = await browser.newPage();
});

test.afterAll(async () => {
  await page.close();
});

/** The PIN pad listens for real key presses. */
async function enterPin(pin: string) {
  for (const digit of pin) await page.keyboard.press(digit);
}

/** The PIN pad's error line (empty until a PIN is refused). */
const pinError = () => page.locator("p[role='alert']");

async function addToCart(query: string, sku: string) {
  const search = page.getByPlaceholder(/Scan a barcode/);
  await search.fill(query);
  await page.getByText(sku, { exact: true }).first().click();
}

/** Opens the tender screen, answering the age check when Settings asks for it. */
async function startPayment() {
  await page.getByRole("button", { name: /^Pay / }).click();
  const ageCheck = page.getByRole("button", { name: "Yes, 18 or over" });
  const tender = page.getByRole("dialog", { name: "Take payment" });
  await expect(ageCheck.or(tender)).toBeVisible();
  if (await ageCheck.isVisible()) await ageCheck.click();
  await expect(tender).toBeVisible();
  return tender;
}

/** The owner changes business settings through the API, as the Settings screen does. */
async function ownerSettings(values: Record<string, unknown>) {
  const api = await request.newContext({
    baseURL: "http://localhost:8011",
    extraHTTPHeaders: { Accept: "application/json", Origin: "http://localhost:3011", Referer: "http://localhost:3011/" },
  });
  const xsrf = async () => ({ "X-XSRF-TOKEN": decodeURIComponent((await api.storageState()).cookies.find((c) => c.name === "XSRF-TOKEN")?.value ?? "") });
  await api.get("/sanctum/csrf-cookie");
  expect((await api.post("/api/v1/auth/login", { data: { login: "owner@tessera.test", password: "password" }, headers: await xsrf() })).ok()).toBe(true);
  for (const [key, value] of Object.entries(values)) {
    const saved = await api.put(`/api/v1/settings/values/${key}`, { data: { scope: "business", scopeId: 0, value }, headers: await xsrf() });
    expect(saved.ok(), `${key}: ${await saved.text()}`).toBe(true);
  }
  await api.dispose();
}

async function nextCustomer() {
  await page.getByRole("button", { name: "Next customer" }).click();
  await expect(page.getByText("Cart is empty")).toBeVisible();
}

test("the owner sets this device up as a till", async () => {
  await page.goto("/till");
  await page.getByRole("link", { name: "Manager sign in" }).click();

  await page.getByLabel("Email or phone number").fill("owner@tessera.test");
  await page.getByLabel(/^Password/).fill("password");
  await page.getByRole("button", { name: "Sign in" }).click();

  await expect(page.getByRole("heading", { name: "Set up this device as a till" })).toBeVisible();
  // A single-branch shop fills the branch by itself once the list loads.
  await expect(page.getByRole("textbox", { name: "Branch" })).toHaveValue(/MAIN/);
  await page.getByLabel("Till name").fill("E2E till");
  await page.getByRole("button", { name: "Set up till" }).click();

  // The owner is signed out; the till waits for a cashier.
  await expect(page.getByRole("heading", { name: "Who's on the till?" })).toBeVisible();
});

test("a wrong PIN is refused and the right one starts the shift", async () => {
  await page.getByRole("button", { name: /Otieno/ }).click();
  await enterPin("1111");
  await page.keyboard.press("Enter");
  await expect(pinError()).not.toBeEmpty();

  await enterPin("2580");
  await page.keyboard.press("Enter");
  await expect(page.getByText("Cart is empty")).toBeVisible();
});

test("a cash sale gives the right change", async () => {
  await addToCart("Tusker", "TUS-500");
  const tender = await startPayment();

  await tender.getByText("Cash", { exact: true }).click();
  await tender.getByLabel("Cash received (KES)").fill("1000");
  await tender.getByRole("button", { name: "Complete sale" }).click();

  const done = page.getByRole("dialog", { name: /Sale .* complete/ });
  await expect(done.getByText("Change to give")).toBeVisible();
  await expect(done.getByText(/750\.00/).first()).toBeVisible();
  await nextCustomer();
});

test("two bottles of wine get the wine-weekend promotion and are paid by M-PESA", async () => {
  await addToCart("4th Street", "4TH-750");
  await page.getByRole("button", { name: "One more" }).click();

  // 2 × KES 1,050 less 10%.
  await expect(page.getByText("Discounts & promotions")).toBeVisible();
  await expect(page.getByRole("button", { name: /^Pay .*1,890\.00/ })).toBeVisible();

  // Demo M-PESA: the customer "approves" the prompt after a few seconds.
  const tender = await startPayment();
  await tender.getByLabel("Customer's M-PESA number").fill("0712345678");
  await tender.getByRole("button", { name: /^Send .* request/ }).click();
  await expect(tender.getByText(/M-PESA received .*1,890\.00/)).toBeVisible({ timeout: 30_000 });
  await tender.getByRole("button", { name: "Complete sale" }).click();
  await expect(page.getByRole("dialog", { name: /Sale .* complete/ }).getByText("Paid in full")).toBeVisible();
  await nextCustomer();
});

test("an account customer's sale goes on their account", async () => {
  await page.getByRole("button", { name: "Walk-in customer" }).click();
  await page.getByPlaceholder("Business name or KRA PIN").fill("Lounge");
  await page.getByText("Demo Lounge & Grill").first().click();

  await addToCart("Smirnoff", "SMR-750");
  const tender = await startPayment();
  await tender.getByText("On account", { exact: true }).click();
  await tender.getByRole("button", { name: "Complete sale" }).click();

  await expect(page.getByRole("dialog", { name: /Sale .* complete/ }).getByText("On Demo Lounge & Grill's account")).toBeVisible();
  await nextCustomer();
});

test("a locked till keeps the sale and needs the cashier's PIN", async () => {
  await addToCart("Tusker", "TUS-500");
  await page.getByRole("button", { name: "Till menu" }).click();
  await page.getByRole("menuitem", { name: /Lock screen/ }).click();

  await expect(page.getByText("Till locked")).toBeVisible();
  await enterPin("9999");
  await page.keyboard.press("Enter");
  await expect(pinError()).not.toBeEmpty();
  await expect(page.getByText("Till locked")).toBeVisible();

  await enterPin("2580");
  await page.keyboard.press("Enter");
  await expect(page.getByText("Till locked")).toBeHidden();
  // The sale is still there; clear it before the cash-up.
  await expect(page.getByRole("button", { name: /^Pay .*250\.00/ })).toBeVisible();
  await page.getByRole("button", { name: "Till menu" }).click();
  await page.getByRole("menuitem", { name: "Clear sale" }).click();
  await expect(page.getByText("Cart is empty")).toBeVisible();
});

test("outside licensed hours the till refuses alcohol", async () => {
  // No licensed hours on any day, then the lock on: alcohol is never allowed.
  await ownerSettings({
    "sales.licensed_hours": { mon: [], tue: [], wed: [], thu: [], fri: [], sat: [], sun: [] },
    "features.licensed_hours_lock": true,
  });
  await page.reload();
  await expect(page.getByText(/Outside licensed hours: alcohol cannot be sold/)).toBeVisible();

  await page.getByPlaceholder(/Scan a barcode/).fill("Tusker");
  await page.getByText("TUS-500", { exact: true }).first().click();
  await expect(page.getByText(/^Alcohol cannot be sold outside the licensed hours/)).toBeVisible();
  await expect(page.getByText("Cart is empty")).toBeVisible();

  await ownerSettings({ "features.licensed_hours_lock": false });
  await page.reload();
  await expect(page.getByText("Cart is empty")).toBeVisible();
  await expect(page.getByText(/Outside licensed hours/)).toBeHidden();
});

test("the cash-up balances: float plus cash sales only", async () => {
  await page.getByRole("button", { name: "Till menu" }).click();
  await page.getByRole("menuitem", { name: /^End shift/ }).click();

  // Float 5,000 + the Tusker paid in cash 250 = 5,250 (M-PESA and account sales are not in the drawer).
  await page.getByLabel("Number of KES 1,000 notes").fill("5");
  await page.getByLabel("Number of KES 200 notes").fill("1");
  await page.getByLabel("Number of KES 50 notes").fill("1");
  await page.getByRole("button", { name: "Submit count" }).click();

  const summary = page.getByRole("dialog", { name: "Shift ended" });
  await expect(summary.getByText("Variance")).toBeVisible();
  await expect(summary.getByText(/5,250\.00/).first()).toBeVisible();
  await summary.getByRole("button", { name: "Done" }).click();
  await expect(page.getByRole("heading", { name: "Who's on the till?" })).toBeVisible();
});
