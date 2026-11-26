import { createTheme, type MantineColorsTuple } from "@mantine/core";

/** Tessera forest green (primary actions). Shade 7 = #14664A. The client's own colour replaces it (Settings → Branding). */
const tessera: MantineColorsTuple = [
  "#e8f5ef",
  "#cfe9dd",
  "#a2d4bd",
  "#72be9b",
  "#4aab7f",
  "#2f9b6c",
  "#1f7d57",
  "#14664a",
  "#0f523c",
  "#0a3f2e",
];

/** Amber accent (highlights, secondary calls to action). Shade 5 = #F2A93B. */
const amber: MantineColorsTuple = [
  "#fff6e6",
  "#ffeac2",
  "#fcd98f",
  "#f8c65b",
  "#f5b742",
  "#f2a93b",
  "#dc942a",
  "#bb7a1e",
  "#9a6214",
  "#7a4c0c",
];

/** Brand tokens for surfaces that are not Mantine components (login panel, till). */
export const brand = {
  /** Deep forest green (not black): sidebar, sign-in panel, till sign-in. */
  navy: "#0f3d2e",
  navyRaised: "#175440",
  cream: "#f4f2ec",
  /** Page background (back office and till): near-white, so white cards and tables stand out. */
  surface: "#f3f6f4",
  green: "#14664a",
  mint: "#4aab7f",
  amber: "#f2a93b",
  slate: "#3d5a4e",
} as const;

/** Font stacks: Figtree (UI text), Bricolage Grotesque (display), JetBrains Mono (codes). Loaded in app/layout.tsx. */
export const fonts = {
  body: "var(--font-body), system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
  display: "var(--font-display), var(--font-body), system-ui, sans-serif",
  mono: "var(--font-mono), ui-monospace, SFMono-Regular, Consolas, monospace",
} as const;

const displayTitle = { fontFamily: fonts.display, fontWeight: 800, fontSize: 20, letterSpacing: "-0.02em" };

export const theme = createTheme({
  primaryColor: "tessera",
  primaryShade: 7,
  colors: { tessera, amber },
  defaultRadius: "md",
  fontFamily: fonts.body,
  fontFamilyMonospace: fonts.mono,
  headings: {
    fontFamily: fonts.display,
    fontWeight: "800",
  },
  components: {
    Button: { defaultProps: { radius: "md" }, styles: { label: { fontWeight: 600 } } },
    Paper: { defaultProps: { radius: "lg" } },
    Input: { defaultProps: { radius: "md" } },
    InputWrapper: { styles: { label: { fontWeight: 600, marginBottom: 4 } } },
    Modal: { defaultProps: { radius: "lg" }, styles: { title: displayTitle } },
    Drawer: { styles: { title: displayTitle } },
    NavLink: {
      defaultProps: { variant: "light" },
      styles: { root: { borderRadius: 10, marginBottom: 2 }, label: { fontWeight: 600 } },
    },
    Tabs: { styles: { tab: { fontWeight: 600 } } },
    Badge: { styles: { root: { fontWeight: 700, letterSpacing: "0.02em" } } },
    Table: {
      styles: {
        th: { color: "var(--mantine-color-dimmed)", fontWeight: 600, fontSize: 13 },
        // Aligned digits so money and quantity columns line up.
        td: { fontVariantNumeric: "tabular-nums" },
      },
    },
  },
});
