/**
 * This browser's till identity. The token proves the device was set up by a manager;
 * it is kept per device in localStorage (and can be revoked from Branches → Tills).
 * Storage can be unavailable (private mode), so every access is guarded.
 */
const KEY = "tessera.tillDeviceToken";

export function getTillToken(): string | null {
  if (typeof window === "undefined") return null;
  try {
    return window.localStorage.getItem(KEY);
  } catch {
    return null;
  }
}

export function setTillToken(token: string): void {
  try {
    window.localStorage.setItem(KEY, token);
  } catch {
    // Without storage the device cannot stay paired; the till screen will ask again.
  }
}

export function clearTillToken(): void {
  try {
    window.localStorage.removeItem(KEY);
  } catch {
    // ignore
  }
}
