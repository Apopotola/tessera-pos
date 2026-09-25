"use client";

import { Stack, Text, ThemeIcon } from "@mantine/core";
import { IconHourglass } from "@tabler/icons-react";
import DataCard from "@/components/shared/DataCard";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";

/**
 * Registered for screens whose module is not built yet, and the fallback
 * for any viewType missing from the registry.
 */
export default function PlaceholderView({ title, section }: WorkspaceViewProps) {
  return (
    <WorkspacePage section={section} title={title}>
      <DataCard padding="lg">
        <Stack gap="sm" maw={560}>
          <ThemeIcon size={48} radius="md" variant="light">
            <IconHourglass size={24} />
          </ThemeIcon>
          <Text fw={700} fz="lg">
            Coming in a later release
          </Text>
          <Text size="sm" c="dimmed">
            {title} is part of the approved plan but has not been built yet. It will appear here, with the same look as the rest of Tessera, when its module is ready.
          </Text>
        </Stack>
      </DataCard>
    </WorkspacePage>
  );
}
