import path from "node:path";
import type { NextConfig } from "next";

const nextConfig: NextConfig = {
  basePath: process.env.NEXT_PUBLIC_BASE_PATH || undefined,
  output: "standalone",
  outputFileTracingRoot: path.resolve(__dirname),
  // Sanctum cookies are host-scoped: open the app on the same host as the API (localhost).
  allowedDevOrigins: ["localhost"],
  turbopack: {
    root: path.resolve(__dirname),
  },
  experimental: {
    // @tabler/icons-react is optimized by Next.js by default.
    optimizePackageImports: ["@mantine/core", "@mantine/hooks", "@mantine/dates"],
  },
};

export default nextConfig;
