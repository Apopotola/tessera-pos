/**
 * Decouples the API client from the Redux store: the client emits, and the
 * auth layer (SessionWatcher) listens and signs the user out.
 */
const SESSION_EXPIRED_EVENT = "tessera:session-expired";

export interface SessionExpiredDetail {
  status: number;
}

export function emitSessionExpired(status: number): void {
  if (typeof window === "undefined") return;
  window.dispatchEvent(new CustomEvent<SessionExpiredDetail>(SESSION_EXPIRED_EVENT, { detail: { status } }));
}

export function onSessionExpired(handler: (detail: SessionExpiredDetail) => void): () => void {
  if (typeof window === "undefined") return () => undefined;

  const listener = (event: Event) => handler((event as CustomEvent<SessionExpiredDetail>).detail);
  window.addEventListener(SESSION_EXPIRED_EVENT, listener);
  return () => window.removeEventListener(SESSION_EXPIRED_EVENT, listener);
}
