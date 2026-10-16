import { Group, Stack, Text } from "@mantine/core";
import type { ReactNode } from "react";
import classes from "./PageHeader.module.css";

interface PageHeaderProps {
  title: string;
  description?: string;
  /** Small uppercase label above the title, e.g. the module name. */
  eyebrow?: string;
  actions?: ReactNode;
}

/**
 * Standard header for every workspace view: title and subtitle on the left, the screen's
 * actions on the right (they wrap below on phones), on the page background.
 */
export default function PageHeader({ title, description, eyebrow, actions }: PageHeaderProps) {
  return (
    <Group justify="space-between" align="flex-start" gap="md" className={classes.header}>
      <Stack gap={2} style={{ minWidth: 0, flex: "1 1 320px" }}>
        {eyebrow && (
          <Text size="xs" fw={700} c="tessera.6" tt="uppercase" style={{ letterSpacing: "0.12em" }}>
            {eyebrow}
          </Text>
        )}
        <h2 className={`tessera-display ${classes.title}`}>{title}</h2>
        {description && (
          <Text size="sm" c="dimmed" maw={720}>
            {description}
          </Text>
        )}
      </Stack>
      {actions && (
        <Group gap="sm" wrap="wrap" className={classes.actions}>
          {actions}
        </Group>
      )}
    </Group>
  );
}
