// Print a user-guide HTML page to PDF with page numbers (Edge, headless).
// Usage: node guide-capture/pdf.mjs <in.html> <out.pdf> [--landscape]
import path from "node:path";
import { pathToFileURL } from "node:url";
import { chromium } from "@playwright/test";

const [, , input, output, ...flags] = process.argv;
const browser = await chromium.launch({ channel: process.env.E2E_BROWSER_CHANNEL || "msedge" });
const page = await browser.newPage();
await page.goto(pathToFileURL(path.resolve(input)).href, { waitUntil: "load" });
await page.pdf({
  path: output,
  format: "A4",
  landscape: flags.includes("--landscape"),
  printBackground: true,
  preferCSSPageSize: true,
  displayHeaderFooter: true,
  headerTemplate: "<span></span>",
  footerTemplate:
    '<div style="width:100%;font:8px Segoe UI,Arial,sans-serif;color:#6b6c78;padding:0 15mm;display:flex;justify-content:space-between">' +
    '<span>Tessera POS · User Guide</span><span><span class="pageNumber"></span> / <span class="totalPages"></span></span></div>',
});
await browser.close();
console.log("wrote", output);
