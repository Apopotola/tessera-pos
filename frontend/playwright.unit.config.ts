import { defineConfig } from "@playwright/test";

/**
 * Unit tests for pure functions (e.g. the ESC/POS receipt printer). They run in Node on the
 * Playwright test runner already used for the e2e suite: no browser, server or database.
 */
export default defineConfig({
  testDir: "./modules",
  testMatch: "**/*.test.ts",
  reporter: [["list"]],
});
