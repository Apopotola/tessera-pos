// UI conventions that ESLint cannot express. Runs as part of `pnpm lint`.
// 1. Every workspace view renders inside <WorkspacePage> (uniform frame, header and section label).
// 2. Every menu view_type seeded by the backend has a screen in ViewRegistry.tsx.
import { existsSync, readdirSync, readFileSync } from "node:fs";
import path from "node:path";

const root = path.resolve(import.meta.dirname, "..");
const problems = [];

function viewFiles(dir) {
  return readdirSync(dir, { withFileTypes: true }).flatMap((entry) => {
    const full = path.join(dir, entry.name);
    if (entry.isDirectory()) return viewFiles(full);
    return entry.name.endsWith("View.tsx") && full.includes(`${path.sep}views${path.sep}`) ? [full] : [];
  });
}

for (const file of [...viewFiles(path.join(root, "modules")), path.join(root, "components/workspace/PlaceholderView.tsx")]) {
  const source = readFileSync(file, "utf8");
  if (!source.includes("<WorkspacePage")) {
    problems.push(`${path.relative(root, file)}: must render inside <WorkspacePage> (components/shared/WorkspacePage.tsx)`);
  }
}

const seeder = path.resolve(root, "../backend/Modules/Authorization/database/seeders/AuthorizationDatabaseSeeder.php");
if (existsSync(seeder)) {
  const registry = readFileSync(path.join(root, "components/workspace/ViewRegistry.tsx"), "utf8");
  const registered = new Set([...registry.matchAll(/^\s{2}(\w+):/gm)].map((m) => m[1]));
  for (const [, viewType] of readFileSync(seeder, "utf8").matchAll(/'view_type' => '(\w+)'/g)) {
    if (!registered.has(viewType)) problems.push(`Menu view_type "${viewType}" is not registered in components/workspace/ViewRegistry.tsx`);
  }
}

if (problems.length) {
  console.error(`UI convention check failed:\n - ${problems.join("\n - ")}`);
  process.exit(1);
}
console.log("UI conventions OK");
