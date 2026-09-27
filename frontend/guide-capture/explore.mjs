// Development aid: list the buttons, tabs and fields on screens, to pick screenshot markers.
// Usage: node guide-capture/explore.mjs <email> <route> [<route> …]
import { launch, newPage, open, signIn } from "./lib.mjs";

const [, , email, ...routes] = process.argv;
const browser = await launch();
const page = await newPage(browser);
await signIn(page, email);
for (const route of routes) {
  await open(page, route);
  const items = await page.evaluate(() =>
    [...document.querySelectorAll("main button, main a, main [role=tab], main input, main label")]
      .filter((e) => e.offsetParent !== null)
      .map((e) => `${e.tagName.toLowerCase()}${e.getAttribute("role") ? `[${e.getAttribute("role")}]` : ""}: ${(e.getAttribute("aria-label") || e.textContent || e.getAttribute("placeholder") || "").trim().slice(0, 50)}`)
      .filter((t) => !t.endsWith(": ")),
  );
  console.log(`\n== ${route}\n${[...new Set(items)].slice(0, 45).join("\n")}`);
}
await browser.close();
