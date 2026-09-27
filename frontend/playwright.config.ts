import { defineConfig } from "@playwright/test";

/**
 * Till end-to-end tests. They run against their own database (tessera_pos_e2e), API (8011)
 * and production build of the web app (3011), so the development data is never touched.
 * PHP comes from TESSERA_PHP (defaults to `php` on PATH; it must be PHP 8.4).
 */
const php = process.env.TESSERA_PHP || "php";

export const e2eApiEnv: Record<string, string> = {
  APP_ENV: "local",
  DB_DATABASE: "tessera_pos_e2e",
  APP_URL: "http://localhost:8011",
  FRONTEND_URL: "http://localhost:3011",
  SANCTUM_STATEFUL_DOMAINS: "localhost:3011",
  CORS_ALLOWED_ORIGINS: "http://localhost:3011",
  SESSION_COOKIE: "tessera_e2e_session",
  QUEUE_CONNECTION: "sync",
  MPESA_DRIVER: "fake",
  ETIMS_DRIVER: "fake",
};

export default defineConfig({
  testDir: "./e2e",
  globalSetup: "./e2e/global-setup.ts",
  // One till, one shift: the steps build on each other.
  workers: 1,
  fullyParallel: false,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  reporter: [["list"], ["html", { open: "never" }]],
  use: {
    baseURL: "http://localhost:3011",
    // Uses the Microsoft Edge already installed; set E2E_BROWSER_CHANNEL=chrome for Chrome.
    channel: process.env.E2E_BROWSER_CHANNEL || "msedge",
    viewport: { width: 1366, height: 800 },
    trace: "retain-on-failure",
    screenshot: "only-on-failure",
  },
  webServer: [
    {
      // --no-reload passes the environment above through to the PHP server.
      command: `"${php}" artisan serve --host=127.0.0.1 --port=8011 --no-reload`,
      cwd: "../backend",
      url: "http://127.0.0.1:8011/up",
      env: e2eApiEnv,
      reuseExistingServer: false,
      timeout: 60_000,
    },
    {
      command: "pnpm build && pnpm exec next start --port 3011",
      url: "http://localhost:3011/login",
      env: { NEXT_PUBLIC_LOCAL_BACKEND_PORT: "8011" },
      reuseExistingServer: false,
      timeout: 300_000,
    },
  ],
});
