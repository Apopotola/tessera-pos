"use client";

import { Button, Center, Loader, Text } from "@mantine/core";
import { useCallback, useEffect, useState } from "react";
import { ApiError, organisationApi, salesApi } from "@/api";
import { brand } from "@/app/theme";
import { useApiQuery } from "@/hooks/useApiQuery";
import PinScreen from "@/modules/till/components/PinScreen";
import ShiftScreen from "@/modules/till/components/ShiftScreen";
import TillSetup from "@/modules/till/components/TillSetup";
import { useAppSelector } from "@/store/hooks";
import type { Shift } from "@/types/till";
import { clearTillToken, getTillToken } from "@/utils/tillDevice";

/**
 * Till flow: unpaired device → manager setup; paired → "Who's on the till?" PIN screen;
 * signed in with an open shift → shift screen.
 */
export default function TillApp() {
  // Read once on mount; pairing bumps `deviceVersion` to re-read.
  const [deviceVersion, setDeviceVersion] = useState(0);
  const [hasToken, setHasToken] = useState<boolean | null>(null);
  const [shift, setShift] = useState<Shift | null>(null);
  const auth = useAppSelector((state) => state.auth);

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

  if (hasToken === null || auth.status === "idle" || auth.status === "loading" || (hasToken && context.loading)) {
    return (
      <Center style={background}>
        <Loader color="tessera.4" />
      </Center>
    );
  }

  if (!hasToken || unpaired) {
    return <TillSetup onPaired={() => setDeviceVersion((v) => v + 1)} />;
  }

  if (!context.data) {
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
    return <ShiftScreen context={context.data} shift={shift} onEnded={() => setShift(null)} />;
  }

  return <PinScreen context={context.data} onShiftStarted={setShift} />;
}
