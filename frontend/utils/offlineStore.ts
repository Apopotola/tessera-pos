/**
 * Tiny key–value store on IndexedDB for the offline till (catalogue snapshot, queued sales,
 * last session). IndexedDB survives reloads and holds far more than localStorage.
 * Every call fails soft: a browser without IndexedDB (private mode) just has no offline cache.
 */
const DB_NAME = "tessera-till";
const STORE = "kv";

let dbPromise: Promise<IDBDatabase> | null = null;

function openDb(): Promise<IDBDatabase> {
  if (typeof indexedDB === "undefined") return Promise.reject(new Error("IndexedDB is not available"));
  dbPromise ??= new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => request.result.createObjectStore(STORE);
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => {
      dbPromise = null;
      reject(request.error);
    };
  });
  return dbPromise;
}

function run<T>(mode: IDBTransactionMode, action: (store: IDBObjectStore) => IDBRequest): Promise<T> {
  return openDb().then(
    (db) =>
      new Promise<T>((resolve, reject) => {
        const request = action(db.transaction(STORE, mode).objectStore(STORE));
        request.onsuccess = () => resolve(request.result as T);
        request.onerror = () => reject(request.error);
      }),
  );
}

export async function offlineGet<T>(key: string): Promise<T | null> {
  try {
    return (await run<T | undefined>("readonly", (s) => s.get(key))) ?? null;
  } catch {
    return null;
  }
}

export async function offlineSet<T>(key: string, value: T): Promise<void> {
  try {
    await run("readwrite", (s) => s.put(value, key));
  } catch {
    // No offline cache on this browser; online selling is unaffected.
  }
}

export async function offlineDelete(key: string): Promise<void> {
  try {
    await run("readwrite", (s) => s.delete(key));
  } catch {
    // ignore
  }
}
