"use client";

import { Alert, Stack, Text, Title } from "@mantine/core";
import { IconTools } from "@tabler/icons-react";
import type { WorkspaceViewProps } from "@/components/workspace/types";

/**
 * Registered for modules whose screens are not built yet, and used as the
 * fallback for any viewType missing from the registry.
 */
export default function PlaceholderView({ title, viewType }: WorkspaceViewProps) {
  return (
    <Stack p="md" gap="md">
      <Title order={3}>{title}</Title>
      <Alert variant="light" color="gray" icon={<IconTools size={18} />} title="Not built yet">
        <Text size="sm">
          This screen is scaffolded but its module has not been implemented. View type: <code>{viewType}</code>
        </Text>
      </Alert>
    </Stack>
  );
}
