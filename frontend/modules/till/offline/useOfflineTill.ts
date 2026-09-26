"use client";

import { useCallback, useEffect, useMemo, useRef, useState } from "react";
import { ApiError, salesApi } from "@/api";
import type { SalePayload, ScanResult, TillItem } from "@/types/sales";
import type { QueuedEntry, TillSnapshot } from "@/types/till";
import { offlineGet, offlineSet } from "@/utils/offlineStore";

const SNAPSHOT_REFRESH_MS = 10 * 60 * 1000;
const PING_EVERY_MS = 10 * 1000;

/**
 * Offline till. Keeps a copy of the catalogue on the device, notices when the connection
 * drops (browser events, failed requests, a ping while offline), queues sales and item
 * removals, and sends them in order when the connection is back. Sales carry their
 * clientId, so a resend after a lost response is recorded once on the server.
 */
export function useOfflineTill(tillId: number, userId: number | undefined) {
  const [online, setOnline] = useState(true);
  const [snapshot, setSnapshot] = useState<TillSnapshot | null>(null);
  const [queue, setQueue] = useState<QueuedEntry[]>([]);
  const [loaded, setLoaded] = useState(false);
  const [syncing, setSyncing] = useState(false);
  const [lastSynced, setLastSynced] = useState<number>(0);
  const syncingRef = useRef(false);
  const queueRef = useRef<QueuedEntry[]>([]);

  const snapshotKey = `snapshot:${tillId}`;
  const queueKey = `queue:${tillId}`;

  // Load what is on the device.
  useEffect(() => {
    let active = true;
    void Promise.all([offlineGet<TillSnapshot>(snapshotKey), offlineGet<QueuedEntry[]>(queueKey)]).then(([snap, saved]) => {
      if (!active) return;
      if (snap) setSnapshot(snap);
      queueRef.current = saved ?? [];
      setQueue(queueRef.current);
      setLoaded(true);
    });
    return () => {
      active = false;
    };
  }, [snapshotKey, queueKey]);

  const saveQueue = useCallback(
    (next: QueuedEntry[]) => {
      queueRef.current = next;
      setQueue(next);
      void offlineSet(queueKey, next);
    },
    [queueKey],
  );

  /** Called whenever a request fails because the server could not be reached. */
  const markOffline = useCallback(() => setOnline(false), []);

  // Browser connectivity events, confirmed by a ping (captive Wi-Fi reports "online" too).
  useEffect(() => {
    const check = () =>
      salesApi
        .ping()
        .then(() => setOnline(true))
        .catch(() => setOnline(false));
    const goOffline = () => setOnline(false);
    window.addEventListener("online", check);
    window.addEventListener("offline", goOffline);
    if (!navigator.onLine) goOffline();
    return () => {
      window.removeEventListener("online", check);
      window.removeEventListener("offline", goOffline);
    };
  }, []);

  // While offline, keep asking whether the server is back.
  useEffect(() => {
    if (online) return;
    const timer = window.setInterval(() => {
      salesApi
        .ping()
        .then(() => setOnline(true))
        .catch(() => undefined);
    }, PING_EVERY_MS);
    return () => window.clearInterval(timer);
  }, [online]);

  // Keep the catalogue copy fresh while online.
  const refreshSnapshot = useCallback(async () => {
    try {
      const fresh = await salesApi.tillCatalogue();
      setSnapshot(fresh);
      await offlineSet(snapshotKey, fresh);
    } catch (e) {
      if (e instanceof ApiError && e.status === 0) setOnline(false);
    }
  }, [snapshotKey]);

  useEffect(() => {
    if (!online) return;
    void refreshSnapshot(); // eslint-disable-line react-hooks/set-state-in-effect
    const timer = window.setInterval(() => void refreshSnapshot(), SNAPSHOT_REFRESH_MS);
    return () => window.clearInterval(timer);
  }, [online, refreshSnapshot]);

  /** Local search, the same way the server searches: every word must match. */
  const search = useCallback(
    (term: string): TillItem[] => {
      const words = term.toLowerCase().split(/\s+/).filter(Boolean);
      return (snapshot?.items ?? []).filter((item) => words.every((w) => item.search.includes(w))).slice(0, 24);
    },
    [snapshot],
  );

  const scan = useCallback(
    (code: string): ScanResult | null => {
      const barcode = snapshot?.barcodes.find((b) => b.code === code.trim());
      const item = barcode && snapshot?.items.find((i) => i.variantId === barcode.variantId);
      return barcode && item ? { item, units: barcode.units, packName: barcode.packName } : null;
    },
    [snapshot],
  );

  /** Numbers printed on offline receipts until the server assigns the real one. */
  const nextLocalNumber = useCallback(async () => {
    const key = `seq:${tillId}`;
    const n = ((await offlineGet<number>(key)) ?? 0) + 1;
    await offlineSet(key, n);
    return `OFFLINE-${tillId}-${String(n).padStart(4, "0")}`;
  }, [tillId]);

  const enqueueSale = useCallback(
    async (payload: SalePayload, totalCents: number) => {
      if (!userId) throw new Error("No cashier signed in.");
      const localNumber = await nextLocalNumber();
      saveQueue([
        ...queueRef.current,
        { kind: "sale", clientId: payload.clientId, userId, localNumber, totalCents, queuedAt: new Date().toISOString(), payload, status: "pending", error: null },
      ]);
      // Shelf stock on this device drops too, so the till keeps showing sensible numbers.
      if (snapshot) {
        const sold = new Map<number, number>();
        payload.lines.filter((l) => l.unit === "bottle").forEach((l) => sold.set(l.variantId, (sold.get(l.variantId) ?? 0) + l.quantity));
        const next = { ...snapshot, items: snapshot.items.map((i) => (sold.has(i.variantId) ? { ...i, onFloor: i.onFloor - (sold.get(i.variantId) ?? 0) } : i)) };
        setSnapshot(next);
        void offlineSet(snapshotKey, next);
      }
      return localNumber;
    },
    [userId, nextLocalNumber, saveQueue, snapshot, snapshotKey],
  );

  const enqueueVoid = useCallback(
    (payload: { variantId: number; quantity: number; valueCents: number }) => {
      if (!userId) return;
      saveQueue([
        ...queueRef.current,
        { kind: "void", clientId: crypto.randomUUID(), userId, queuedAt: new Date().toISOString(), payload, status: "pending", error: null },
      ]);
    },
    [userId, saveQueue],
  );

  /** Sends this cashier's pending entries in order. Stops at the first connection problem. */
  const sync = useCallback(async (): Promise<number> => {
    if (syncingRef.current || !userId) return 0;
    syncingRef.current = true;
    setSyncing(true);
    let sent = 0;
    try {
      for (const entry of [...queueRef.current]) {
        if (entry.status !== "pending" || entry.userId !== userId) continue;
        try {
          if (entry.kind === "sale") await salesApi.completeSale(entry.payload);
          else await salesApi.logVoid(entry.payload.variantId, entry.payload.quantity, entry.payload.valueCents, "Removed while the till was offline", null);
          saveQueue(queueRef.current.filter((e) => e.clientId !== entry.clientId));
          sent += entry.kind === "sale" ? 1 : 0;
        } catch (e) {
          if (!(e instanceof ApiError)) break;
          if (e.status === 0) {
            setOnline(false);
            break;
          }
          if (e.status === 401 || e.status === 419 || e.status === 429 || e.status >= 500) break; // try again later
          // Refused (validation / permission): keep it for a person to look at.
          const message = Object.values(e.formErrors)[0] ?? e.message;
          saveQueue(queueRef.current.map((q) => (q.clientId === entry.clientId ? { ...q, status: "failed", error: message } : q)));
        }
      }
    } finally {
      syncingRef.current = false;
      setSyncing(false);
      if (sent > 0) setLastSynced(Date.now());
    }
    return sent;
  }, [userId, saveQueue]);

  const retryFailed = useCallback(() => {
    saveQueue(queueRef.current.map((q) => (q.status === "failed" ? { ...q, status: "pending", error: null } : q)));
  }, [saveQueue]);

  const mine = useMemo(() => queue.filter((q) => q.userId === userId), [queue, userId]);
  const pendingSales = mine.filter((q) => q.kind === "sale" && q.status === "pending").length;
  const pending = mine.filter((q) => q.status === "pending").length;
  const failed = mine.filter((q) => q.status === "failed");
  const othersWaiting = queue.filter((q) => q.userId !== userId && q.kind === "sale").length;

  // Send as soon as the connection is back (and whenever something new is queued online).
  useEffect(() => {
    if (online && loaded && pending > 0) void sync();
  }, [online, loaded, pending, sync]);

  return {
    online,
    markOffline,
    snapshot,
    search,
    scan,
    enqueueSale,
    enqueueVoid,
    sync,
    syncing,
    lastSynced,
    retryFailed,
    pendingSales,
    failed,
    othersWaiting,
    /** Anything of this cashier's not yet on the server (blocks ending the shift). */
    unsynced: mine.length,
  };
}

export type OfflineTill = ReturnType<typeof useOfflineTill>;
