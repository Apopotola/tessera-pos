"use client";

import { Badge, Button, Group, Paper, SimpleGrid, Text } from "@mantine/core";
import { IconCalculator } from "@tabler/icons-react";
import dayjs from "dayjs";
import Link from "next/link";
import { useCallback } from "react";
import { organisationApi } from "@/api";
import { brand } from "@/app/theme";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes } from "@/utils/money";

/**
 * The till is a full-screen app (/till); this workspace view launches it and,
 * for managers, shows which tills are connected.
 */
export default function PosTillView({ title, section }: WorkspaceViewProps) {
  const { can } = usePermissions();

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Selling happens on the full-screen till. If this computer or tablet is set up as a till, cashiers see “Who's on the till?” and sign in with their PIN; if not, a manager can set it up in one step."
      actions={
        <Button component={Link} href="/till" size="md" color="amber.5" c={brand.navy} leftSection={<IconCalculator size={18} />}>
          Open till screen
        </Button>
      }
    >
      {can(PERMISSIONS.ORGANISATION_MANAGE) && <TillStatus />}
    </WorkspacePage>
  );
}

function TillStatus() {
  const fetchTills = useCallback(() => organisationApi.tills(), []);
  const { data } = useApiQuery(fetchTills);

  if (!data?.length) return null;

  return (
    <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }}>
      {data.map((till) => (
        <Paper key={till.id} withBorder radius="lg" p="md">
          <Group justify="space-between">
            <Text fw={700}>{till.name}</Text>
            <Badge color={till.isPaired ? "green" : "gray"} variant="light">
              {till.isPaired ? "Connected" : "Not connected"}
            </Badge>
          </Group>
          <Text size="sm" c="dimmed">
            {till.description ?? "—"} · float {formatKes(till.defaultFloatCents)}
          </Text>
          {till.lastSeenAt && (
            <Text size="xs" c="dimmed" mt={4}>
              Last seen {dayjs(till.lastSeenAt).format("DD MMM HH:mm")}
            </Text>
          )}
        </Paper>
      ))}
    </SimpleGrid>
  );
}
