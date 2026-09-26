"use client";

import { ModalsProvider } from "@mantine/modals";
import { Notifications } from "@mantine/notifications";
import type { ReactNode } from "react";
import BrandedMantine from "@/app/providers/BrandedMantine";
import SessionBootstrap from "@/app/providers/SessionBootstrap";
import StoreProvider from "@/app/providers/StoreProvider";

export default function AppProviders({ children }: { children: ReactNode }) {
  return (
    <StoreProvider>
      <BrandedMantine>
        <ModalsProvider>
          <Notifications position="top-right" />
          <SessionBootstrap />
          {children}
        </ModalsProvider>
      </BrandedMantine>
    </StoreProvider>
  );
}
