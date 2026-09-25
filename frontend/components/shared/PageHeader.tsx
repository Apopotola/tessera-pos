import { Box, Group, Paper, Stack, Text } from "@mantine/core";
import type { ReactNode } from "react";
import { brand } from "@/app/theme";
import BottleSkyline from "@/components/brand/BottleSkyline";
import classes from "./PageHeader.module.css";

interface PageHeaderProps {
  title: string;
  description?: string;
  /** Small uppercase label above the title, e.g. the module name. */
  eyebrow?: string;
  actions?: ReactNode;
}

/**
 * Standard header for every workspace view: the navy brand banner with the bottle
 * skyline, so all modules share the look of the till and dashboard.
 */
export default function PageHeader({ title, description, eyebrow, actions }: PageHeaderProps) {
  return (
    <Paper radius="xl" style={{ background: brand.navyRaised, overflow: "hidden" }}>
      <Group justify="space-between" align="flex-end" wrap="nowrap" gap="xl">
        <Stack gap="sm" px={{ base: "lg", md: "xl" }} py="lg" style={{ minWidth: 0, flex: 1 }}>
          {eyebrow && (
            <Text size="xs" fw={700} c={brand.lilac} tt="uppercase" style={{ letterSpacing: "0.12em" }}>
              {eyebrow}
            </Text>
          )}
          <h2 className="tessera-display" style={{ margin: 0, fontSize: 30, color: "white" }}>
            {title}
          </h2>
          {description && (
            <Text size="sm" c="gray.4" maw={640}>
              {description}
            </Text>
          )}
          {actions && (
            <Group gap="sm" mt={4} className={classes.actions}>
              {actions}
            </Group>
          )}
        </Stack>
        <Box visibleFrom="md" w={300} pr="xl" style={{ flexShrink: 0 }}>
          <BottleSkyline height={110} withShelf={false} />
        </Box>
      </Group>
    </Paper>
  );
}
