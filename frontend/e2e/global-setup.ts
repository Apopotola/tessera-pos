import { execFileSync } from "node:child_process";
import path from "node:path";
import { e2eApiEnv } from "../playwright.config";

/** Fresh E2E database for every run: demo catalogue, staff and the demo showcase. */
export default function globalSetup() {
  const php = process.env.TESSERA_PHP || "php";
  const run = (...args: string[]) =>
    execFileSync(php, ["artisan", ...args, "--force"], {
      cwd: path.resolve(__dirname, "../../backend"),
      env: { ...process.env, ...e2eApiEnv },
      stdio: "inherit",
    });

  run("migrate:fresh", "--seed");
  run("db:seed", "--class=DemoShowcaseSeeder");
}
