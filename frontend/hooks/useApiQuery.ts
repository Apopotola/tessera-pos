import { useCallback, useEffect, useState } from "react";
import { ApiError } from "@/api";

interface Settled<T> {
  fetcher: () => Promise<T>;
  nonce: number;
  data: T | null;
  error: ApiError | null;
}

/**
 * Loads data for a screen. Pass a memoized fetcher (useCallback) — a new
 * fetcher identity (e.g. changed filters) triggers a reload.
 */
export function useApiQuery<T>(fetcher: () => Promise<T>) {
  const [nonce, setNonce] = useState(0);
  const [settled, setSettled] = useState<Settled<T> | null>(null);

  useEffect(() => {
    let active = true;

    fetcher()
      .then((data) => {
        if (active) setSettled({ fetcher, nonce, data, error: null });
      })
      .catch((error: unknown) => {
        if (!active) return;
        const apiError = error instanceof ApiError ? error : new ApiError("Unexpected error.", 0);
        setSettled({ fetcher, nonce, data: null, error: apiError });
      });

    return () => {
      active = false;
    };
  }, [fetcher, nonce]);

  const reload = useCallback(() => setNonce((n) => n + 1), []);
  const isCurrent = settled?.fetcher === fetcher && settled.nonce === nonce;

  return {
    data: settled?.data ?? null,
    error: isCurrent ? settled.error : null,
    loading: !isCurrent,
    reload,
  };
}
