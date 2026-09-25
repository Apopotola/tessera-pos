/**
 * Centralized backend URL resolution and endpoint constants.
 * Every API path in the app comes from here — do not build URLs in components.
 *
 * Sanctum cookies are host-scoped: localhost and 127.0.0.1 are different cookie
 * jars, so in local dev the API host always follows the browser's host.
 */

const LOCAL_HOSTS = ["localhost", "127.0.0.1", "::1"];

function resolveBackendOrigin(): string {
  const envOrigin = (process.env.NEXT_PUBLIC_BACKEND_URL || "").replace(/\/+$/, "").replace(/\/api$/i, "");
  const localPort = process.env.NEXT_PUBLIC_LOCAL_BACKEND_PORT || "8010";

  if (typeof window === "undefined") {
    return envOrigin || `http://localhost:${localPort}`;
  }

  const browserHost = window.location.hostname;
  const isBrowserLocal = LOCAL_HOSTS.includes(browserHost);

  if (!envOrigin) {
    if (isBrowserLocal) {
      return `${window.location.protocol}//${browserHost}:${localPort}`;
    }
    // Production behind a reverse proxy: same origin.
    return "";
  }

  try {
    const parsed = new URL(envOrigin);
    if (isBrowserLocal && LOCAL_HOSTS.includes(parsed.hostname) && parsed.hostname !== browserHost) {
      parsed.hostname = browserHost;
      return parsed.toString().replace(/\/+$/, "");
    }
  } catch {
    // Fall through to the configured value.
  }

  return envOrigin;
}

export const BACKEND_ORIGIN = resolveBackendOrigin();
export const API_BASE_URL = `${BACKEND_ORIGIN}/api/v1`;

/** Absolute: the CSRF endpoint lives outside the /api/v1 prefix. */
export const CSRF_COOKIE_URL = `${BACKEND_ORIGIN}/sanctum/csrf-cookie`;

/** Paths below are relative to API_BASE_URL. */
export const AUTH_URLS = {
  login: "/auth/login",
  logout: "/auth/logout",
  me: "/auth/me",
} as const;

export const AUTHORIZATION_URLS = {
  menus: "/authorization/menus",
} as const;

export const ORGANISATION_URLS = {
  branches: "/organisation/branches",
} as const;

export const AUDIT_TRAIL_URLS = {
  logs: "/audit-trail/logs",
} as const;
