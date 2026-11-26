import { Box, Group, Paper, Text } from "@mantine/core";
import type { ReactNode } from "react";
import { fonts } from "@/app/theme";

interface DataCardProps {
  title?: string;
  description?: string;
  actions?: ReactNode;
  /** Inner padding; tables sit flush (the default), forms and text use "lg". */
  padding?: "lg" | 0;
  /** A coloured left edge for a card that needs to stand out (e.g. brand.amber for "needs attention"). */
  accent?: string;
  children: ReactNode;
}

/** The one card style for tables, lists and panels on cream workspace pages. */
export default function DataCard({ title, description, actions, padding = 0, accent, children }: DataCardProps) {
  return (
    <Paper withBorder radius="lg" bg="white" style={{ overflow: "hidden", ...(accent ? { borderLeft: `4px solid ${accent}` } : {}) }}>
      {(title || actions) && (
        <Group justify="space-between" align="center" px="lg" py="md" wrap="wrap" style={{ borderBottom: "1px solid var(--mantine-color-gray-2)" }}>
          <div>
            {title && (
              <Text fw={800} fz="lg" ff={fonts.display} style={{ letterSpacing: "-0.01em" }}>
                {title}
              </Text>
            )}
            {description && (
              <Text size="sm" c="dimmed">
                {description}
              </Text>
            )}
          </div>
          {actions}
        </Group>
      )}
      {/* Wide tables scroll sideways inside the card instead of being cut off on laptops. */}
      <Box p={padding} style={{ overflowX: "auto" }}>
        {children}
      </Box>
    </Paper>
  );
}
