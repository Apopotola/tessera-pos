import { createTheme, type MantineColorsTuple } from "@mantine/core";

/** Tessera blue (primary actions). Shade 7 = #1F4E8C. The client's own colour replaces it (Settings → Branding). */
const tessera: MantineColorsTuple = [
  "#ebf1f9",
  "#d4e1f2",
  "#a9c3e5",
  "#7ea4d7",
  "#5a8acb",
  "#3f76c1",
  "#2c63ad",
  "#1f4e8c",
  "#1a4276",
  "#143560",
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
  /** Deep blue (not black): sidebar, sign-in panel, till sign-in. */
  navy: "#13355f",
  navyRaised: "#1b4374",
  cream: "#f4f2ec",
  /** Page background (back office and till): near-white, so white cards and tables stand out. */
  surface: "#f4f6f9",
  blue: "#1f4e8c",
  sky: "#5a8acb",
  amber: "#f2a93b",
  slate: "#3d5272",
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
