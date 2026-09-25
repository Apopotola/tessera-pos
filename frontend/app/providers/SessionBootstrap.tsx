"use client";

import { notifications } from "@mantine/notifications";
import { useEffect } from "react";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { bootstrapSession, sessionExpired } from "@/store/slices/authSlice";
import { resetTabs } from "@/store/slices/tabsSlice";
import { onSessionExpired } from "@/utils/sessionEvents";

/**
 * Restores the cookie session once on load and signs the user out locally
 * when any API call reports an expired session (401/419).
 */
export default function SessionBootstrap() {
  const dispatch = useAppDispatch();
  const status = useAppSelector((state) => state.auth.status);

  useEffect(() => {
    if (status === "idle") void dispatch(bootstrapSession());
  }, [dispatch, status]);

  useEffect(
    () =>
      onSessionExpired(() => {
        dispatch(sessionExpired());
        dispatch(resetTabs());
        notifications.show({
          id: "session-expired",
          color: "yellow",
          title: "Session expired",
          message: "Please sign in again.",
        });
      }),
    [dispatch],
  );

  return null;
}
