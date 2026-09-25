import { Group, Stack, Text } from "@mantine/core";
import type { ReactNode } from "react";
import { brand } from "@/app/theme";

interface PageHeaderProps {
  title: string;
  description?: string;
  /** Small uppercase label above the title, e.g. the module name. */
  eyebrow?: string;
  actions?: ReactNode;
}

/** Standard title row for workspace views, in the brand display type. */
export default function PageHeader({ title, description, eyebrow, actions }: PageHeaderProps) {
  return (
    <Group justify="space-between" align="flex-end" wrap="wrap" gap="md">
      <Stack gap={4}>
        {eyebrow && (
          <Text size="xs" fw={700} c="tessera.7" tt="uppercase" style={{ letterSpacing: "0.12em" }}>
            {eyebrow}
          </Text>
        )}
        <h2 className="tessera-display" style={{ margin: 0, fontSize: 30, color: brand.navy }}>
          {title}
        </h2>
        {description && (
          <Text size="sm" c="dimmed" maw={640}>
            {description}
          </Text>
        )}
      </Stack>
      {actions}
    </Group>
  );
}
