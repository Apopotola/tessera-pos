/**
 * Decouples the API client from the Redux store: the client emits, and the
 * auth layer (SessionWatcher) listens and signs the user out.
 */
const SESSION_EXPIRED_EVENT = "tessera:session-expired";

const TILL_LOCKED_EVENT = "tessera:till-locked";

export interface SessionExpiredDetail {
  status: number;
  /** Server's reason, e.g. "You were signed out after 30 minutes without activity." */
  message: string;
}

export function emitSessionExpired(status: number, message: string): void {
  if (typeof window === "undefined") return;
  window.dispatchEvent(new CustomEvent<SessionExpiredDetail>(SESSION_EXPIRED_EVENT, { detail: { status, message } }));
}

/** The server says this till is locked (423): the till shows its lock screen. */
export function emitTillLocked(): void {
  if (typeof window !== "undefined") window.dispatchEvent(new Event(TILL_LOCKED_EVENT));
}

export function onTillLocked(handler: () => void): () => void {
  if (typeof window === "undefined") return () => undefined;
  window.addEventListener(TILL_LOCKED_EVENT, handler);
  return () => window.removeEventListener(TILL_LOCKED_EVENT, handler);
}

export function onSessionExpired(handler: (detail: SessionExpiredDetail) => void): () => void {
  if (typeof window === "undefined") return () => undefined;

  const listener = (event: Event) => handler((event as CustomEvent<SessionExpiredDetail>).detail);
  window.addEventListener(SESSION_EXPIRED_EVENT, listener);
  return () => window.removeEventListener(SESSION_EXPIRED_EVENT, listener);
}
