"use client";

import { brand } from "@/app/theme";
import { useAppSelector } from "@/store/hooks";

/** The client's branding: signed-in settings when loaded, else the public login branding, else Tessera's. */
export function useBrand() {
  const app = useAppSelector((state) => state.settings.app);
  const pub = useAppSelector((state) => state.settings.branding);
  const v = app?.values ?? {};

  return {
    name: app?.businessName ?? pub?.displayName ?? "Tessera",
    logo: (v["branding.app_logo"] as string | null | undefined) ?? pub?.appLogo ?? null,
    receiptLogo: (v["branding.receipt_logo"] as string | null | undefined) ?? null,
    favicon: (v["branding.favicon"] as string | null | undefined) ?? pub?.favicon ?? null,
    primary: (v["branding.primary_color"] as string | undefined) ?? pub?.primaryColor ?? brand.purple,
    accent: (v["branding.accent_color"] as string | undefined) ?? pub?.accentColor ?? brand.amber,
    poweredBy: app?.poweredBy ?? pub?.poweredBy ?? { text: "Powered by Tessera", support: "support@tessera.co.ke" },
    /** True once the client has set their own name or logo (otherwise show the Tessera wordmark). */
    custom: Boolean(app?.values["branding.display_name"] || v["branding.app_logo"] || pub?.appLogo),
  };
}
