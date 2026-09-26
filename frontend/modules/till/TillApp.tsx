"use client";

import { Button, Center, Loader, Text } from "@mantine/core";
import { useCallback, useEffect, useState } from "react";
import { ApiError, organisationApi, salesApi } from "@/api";
import { brand } from "@/app/theme";
import { useApiQuery } from "@/hooks/useApiQuery";
import PinScreen from "@/modules/till/components/PinScreen";
import SellScreen from "@/modules/till/components/SellScreen";
import TillSetup from "@/modules/till/components/TillSetup";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { restoreOfflineSession } from "@/store/slices/authSlice";
import type { AuthUser } from "@/types/auth";
import type { Shift, TillContext } from "@/types/till";
import { offlineDelete, offlineGet, offlineSet } from "@/utils/offlineStore";
import { clearTillToken, getTillToken } from "@/utils/tillDevice";

/**
 * Till flow: unpaired device → manager setup; paired → "Who's on the till?" PIN screen;
 * signed in with an open shift → selling screen.
 */
export default function TillApp() {
  // Read once on mount; pairing bumps `deviceVersion` to re-read.
  const [deviceVersion, setDeviceVersion] = useState(0);
  const [hasToken, setHasToken] = useState<boolean | null>(null);
  const [shift, setShift] = useState<Shift | null>(null);
  const auth = useAppSelector((state) => state.auth);
  const dispatch = useAppDispatch();
  // Last working session on this device, used when the till starts without a connection.
  const [savedSession, setSavedSession] = useState<SavedSession | null | undefined>(undefined);

  // Production only: lets the till screen open without a connection (see public/sw.js).
  useEffect(() => {
    if (process.env.NODE_ENV === "production" && "serviceWorker" in navigator) {
      navigator.serviceWorker.register("/sw.js").catch(() => undefined);
    }
  }, []);

  useEffect(() => {
    // localStorage is only readable in the browser, after hydration.
    setHasToken(Boolean(getTillToken())); // eslint-disable-line react-hooks/set-state-in-effect
  }, [deviceVersion]);

  const fetchContext = useCallback(
    () => (hasToken ? organisationApi.tillContext() : Promise.resolve(null)),
    // deviceVersion forces a refetch after pairing.
    // eslint-disable-next-line react-hooks/exhaustive-deps
    [hasToken, deviceVersion],
  );
  const context = useApiQuery(fetchContext);
  const unpaired = context.error instanceof ApiError && context.error.status === 403;
  const unreachable = context.error instanceof ApiError && context.error.status === 0;

  // Offline start: load the saved session once the server has proved unreachable.
  useEffect(() => {
    if (!unreachable || savedSession !== undefined) return;
    void offlineGet<SavedSession>(SESSION_KEY).then((saved) => {
      setSavedSession(saved);
      if (saved?.shift.isOpen) {
        dispatch(restoreOfflineSession(saved.user));
        setShift(saved.shift);
      }
    });
  }, [unreachable, savedSession, dispatch]);

  // While the server is unreachable, keep checking so the till recovers by itself.
  const reloadContext = context.reload;
  useEffect(() => {
    if (!unreachable) return;
    const timer = window.setInterval(reloadContext, 15000);
    return () => window.clearInterval(timer);
  }, [unreachable, reloadContext]);

  // Save the working session so the till can resume selling offline after a reload.
  const userForSave = auth.user;
  useEffect(() => {
    if (context.data && shift && userForSave) {
      void offlineSet<SavedSession>(SESSION_KEY, { context: context.data, user: userForSave, shift });
    }
  }, [context.data, shift, userForSave]);
  // Retries clear the error and show "loading"; keep the saved context so the selling screen
  // (and its cart) stays mounted until the server answers again.
  const tillContext = context.data ?? savedSession?.context ?? null;

  useEffect(() => {
    if (unpaired) clearTillToken();
  }, [unpaired]);

  // A signed-in cashier returning to the till resumes their open shift.
  const userId = auth.user?.id;
  useEffect(() => {
    if (!context.data || auth.status !== "authenticated" || !userId) return;
    let active = true;
    salesApi
      .currentShift()
      .then((current) => {
        if (active && current?.user?.id === userId) setShift(current);
      })
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, [context.data, auth.status, userId]);

  const background = { background: brand.navy, minHeight: "100vh" };

  if (hasToken === null || auth.status === "idle" || auth.status === "loading" || (hasToken && context.loading && !tillContext) || (unreachable && savedSession === undefined)) {
    return (
      <Center style={background}>
        <Loader color="tessera.4" />
      </Center>
    );
  }

  if (!hasToken || unpaired) {
    return <TillSetup onPaired={() => setDeviceVersion((v) => v + 1)} />;
  }

  if (!tillContext) {
    return (
      <Center style={{ ...background, color: "white", flexDirection: "column", gap: 12 }}>
        <Text c="white">Could not reach the server. Check the connection.</Text>
        <Button variant="white" onClick={context.reload}>
          Try again
        </Button>
      </Center>
    );
  }

  if (shift && auth.status === "authenticated") {
    return (
      <SellScreen
        context={tillContext}
        shift={shift}
        onEnded={() => {
          // Shift ended or till locked: nothing to resume offline any more.
          setShift(null);
          void offlineDelete(SESSION_KEY);
        }}
      />
    );
  }

  if (!context.data) {
    // Offline with no open shift saved: signing in needs the server.
    return (
      <Center style={{ ...background, color: "white", flexDirection: "column", gap: 12, padding: 24, textAlign: "center" }}>
        <Text c="white" fw={700}>
          This till is offline.
        </Text>
        <Text c="gray.4" size="sm" maw={420}>
          Signing in needs the connection. A cashier who was already on the till when the connection dropped can keep selling.
        </Text>
        <Button variant="white" onClick={context.reload}>
          Try again
        </Button>
      </Center>
    );
  }

  return <PinScreen context={context.data} onShiftStarted={setShift} />;
}

const SESSION_KEY = "till-session";

interface SavedSession {
  context: TillContext;
  user: AuthUser;
  shift: Shift;
}
