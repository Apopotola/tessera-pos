"use client";

import { MantineProvider, mergeThemeOverrides } from "@mantine/core";
import { usePathname } from "next/navigation";
import { type ReactNode, useEffect, useMemo } from "react";
import { theme } from "@/app/theme";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { clearAppSettings, loadAppSettings, loadBranding } from "@/store/slices/settingsSlice";
import { shadesFrom } from "@/utils/palette";
import { useBrand } from "@/components/brand/useBrand";

/**
 * Applies the client's branding everywhere: primary and accent colours (Mantine palette +
 * CSS variables for custom stylesheets), browser-tab icon and title. Public branding loads on
 * every page (login is branded); signed-in settings replace it once loaded.
 */
export default function BrandedMantine({ children }: { children: ReactNode }) {
  const dispatch = useAppDispatch();
  const pathname = usePathname();
  const authStatus = useAppSelector((state) => state.auth.status);
  const { primary, accent, name, favicon } = useBrand();

  useEffect(() => {
    void dispatch(loadBranding());
  }, [dispatch]);

  // The till loads its own (till-scoped) settings.
  const onTill = pathname?.startsWith("/till") ?? false;
  useEffect(() => {
    if (authStatus === "authenticated" && !onTill) void dispatch(loadAppSettings());
    if (authStatus === "unauthenticated") dispatch(clearAppSettings());
  }, [authStatus, onTill, dispatch]);

  const branded = useMemo(
    () => mergeThemeOverrides(theme, { colors: { tessera: shadesFrom(primary, 7), amber: shadesFrom(accent, 5) } }),
    [primary, accent],
  );

  // Next writes the static metadata title on navigation; keep the client's name in the tab.
  useEffect(() => {
    const apply = () => {
      if (document.title !== name) document.title = name;
    };
    apply();
    const observer = new MutationObserver(apply);
    observer.observe(document.head, { subtree: true, childList: true, characterData: true });
    return () => observer.disconnect();
  }, [name]);

  useEffect(() => {
    if (favicon) {
      let link = document.querySelector<HTMLLinkElement>("link[rel='icon']");
      if (!link) {
        link = document.createElement("link");
        link.rel = "icon";
        document.head.appendChild(link);
      }
      link.href = favicon;
    }
  }, [favicon]);

  return (
    <MantineProvider theme={branded} defaultColorScheme="light">
      {children}
    </MantineProvider>
  );
}
