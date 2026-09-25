import { createTheme, type MantineColorsTuple } from "@mantine/core";

/** Tessera brand purple (primary actions). Shade 7 = #5B3FA3. */
const tessera: MantineColorsTuple = [
  "#f3effc",
  "#e2daf6",
  "#c4b3ec",
  "#a58be3",
  "#8f74db",
  "#7a5bd0",
  "#6b4bbf",
  "#5b3fa3",
  "#4e358c",
  "#402b73",
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
  navy: "#1c1d2e",
  navyRaised: "#262739",
  cream: "#f4f2ec",
  purple: "#5b3fa3",
  lilac: "#8f74db",
  amber: "#f2a93b",
  slate: "#3b3c55",
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
