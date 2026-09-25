import { createTheme, type MantineColorsTuple } from "@mantine/core";

/** Burgundy brand palette (light → dark). */
const wine: MantineColorsTuple = [
  "#fbecef",
  "#f1d6dc",
  "#e3aab6",
  "#d57b8e",
  "#c9546c",
  "#c23c56",
  "#bf2f4a",
  "#a9223c",
  "#971b35",
  "#84112c",
];

export const theme = createTheme({
  primaryColor: "wine",
  primaryShade: { light: 7, dark: 5 },
  colors: { wine },
  defaultRadius: "md",
  fontFamily: "var(--font-sans), system-ui, -apple-system, 'Segoe UI', Roboto, sans-serif",
  components: {
    Button: { defaultProps: { radius: "md" } },
    Paper: { defaultProps: { radius: "md" } },
  },
});
