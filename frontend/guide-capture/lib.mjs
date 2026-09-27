// Helpers for the user-guide screenshots (docs/user-guide/images). See guide-capture/README.md.
import { chromium } from "@playwright/test";
import fs from "node:fs";
import path from "node:path";

export const WEB = process.env.GUIDE_WEB || "http://localhost:3011";
export const IMAGES = path.resolve(import.meta.dirname, "../../docs/user-guide/images");
export const BACK_OFFICE = { width: 1440, height: 900 };
export const TILL = { width: 1366, height: 768 };

export async function launch() {
  const browser = await chromium.launch({ channel: process.env.E2E_BROWSER_CHANNEL || "msedge" });
  // On a failure, keep a picture of every open window to see what went wrong.
  const onFailure = async (error) => {
    const pages = browser.contexts().flatMap((c) => c.pages());
    await Promise.all(pages.map((p, i) => p.screenshot({ path: path.join(IMAGES, `_failure-${i}.png`) }).catch(() => undefined)));
    console.error(error);
    process.exit(1);
  };
  process.on("unhandledRejection", onFailure);
  process.on("uncaughtException", onFailure);
  return browser;
}

export async function newPage(browser, viewport = BACK_OFFICE) {
  const context = await browser.newContext({ viewport });
  return context.newPage();
}

export async function settle(page, ms = 900) {
  await page.waitForLoadState("networkidle").catch(() => undefined);
  await page.waitForTimeout(ms);
}

/** Back-office sign-in (retries once: the very first request of a fresh browser can race). */
export async function signIn(page, email, password = "password") {
  await page.goto(`${WEB}/login`);
  await settle(page);
  for (let attempt = 0; attempt < 3 && page.url().includes("/login"); attempt++) {
    await page.getByLabel("Email or phone number").fill(email);
    await page.getByLabel(/^Password/).fill(password);
    await page.getByRole("button", { name: "Sign in" }).click();
    await page.waitForTimeout(3500);
  }
  if (page.url().includes("/login")) throw new Error(`Could not sign in as ${email}`);
}

export async function signOut(page) {
  await page.context().clearCookies();
}

export async function open(page, route) {
  await page.goto(WEB + route);
  await settle(page, 1200);
}

/** A numbered red marker (and a box) on an element, referred to in the text as (1), (2)… */
export async function mark(page, target, n, { box = true } = {}) {
  const b = await target.boundingBox();
  if (!b) throw new Error(`mark ${n}: element not visible`);
  await page.evaluate(
    ({ b, n, box }) => {
      let layer = document.getElementById("guide-marks");
      if (!layer) {
        layer = document.createElement("div");
        layer.id = "guide-marks";
        layer.style.cssText = "position:fixed;inset:0;pointer-events:none;z-index:2147483647";
        document.body.appendChild(layer);
      }
      if (box) {
        const r = document.createElement("div");
        r.style.cssText = `position:fixed;left:${b.x - 4}px;top:${b.y - 4}px;width:${b.width + 8}px;height:${b.height + 8}px;border:3px solid #E03131;border-radius:8px`;
        layer.appendChild(r);
      }
      const c = document.createElement("div");
      c.textContent = String(n);
      const left = Math.max(2, Math.min(b.x + b.width - 8, window.innerWidth - 30));
      const top = Math.max(2, b.y - 16);
      c.style.cssText = `position:fixed;left:${left}px;top:${top}px;width:28px;height:28px;border-radius:50%;background:#E03131;color:#fff;font:700 15px/28px "Segoe UI",Arial,sans-serif;text-align:center;box-shadow:0 1px 4px rgba(0,0,0,.45)`;
      layer.appendChild(c);
    },
    { b, n, box },
  );
}

/** Screenshot to images/<folder>/<name>.png with optional markers [[locator, n], …]. */
export async function shot(page, folder, name, marks = []) {
  const dir = path.join(IMAGES, folder);
  fs.mkdirSync(dir, { recursive: true });
  await page.waitForTimeout(500);
  for (const [target, n, options] of marks) await mark(page, target, n, options);
  await page.screenshot({ path: path.join(dir, `${name}.png`) });
  await page.evaluate(() => document.getElementById("guide-marks")?.remove());
  console.log(`saved ${folder}/${name}.png`);
}

/** The till's PIN pad listens for real key presses. */
export async function typePin(page, pin) {
  for (const digit of pin) await page.keyboard.press(digit);
}
