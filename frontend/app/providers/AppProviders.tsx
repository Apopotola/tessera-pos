"use client";

import { MantineProvider } from "@mantine/core";
import { ModalsProvider } from "@mantine/modals";
import { Notifications } from "@mantine/notifications";
import type { ReactNode } from "react";
import SessionBootstrap from "@/app/providers/SessionBootstrap";
import StoreProvider from "@/app/providers/StoreProvider";
import { theme } from "@/app/theme";

export default function AppProviders({ children }: { children: ReactNode }) {
  return (
    <StoreProvider>
      <MantineProvider theme={theme} defaultColorScheme="light">
        <ModalsProvider>
          <Notifications position="top-right" />
          <SessionBootstrap />
          {children}
        </ModalsProvider>
      </MantineProvider>
    </StoreProvider>
  );
}
