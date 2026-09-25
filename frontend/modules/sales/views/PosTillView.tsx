"use client";

import { Badge, Button, Group, Paper, SimpleGrid, Stack, Text, Title } from "@mantine/core";
import { IconCalculator } from "@tabler/icons-react";
import dayjs from "dayjs";
import Link from "next/link";
import { useCallback } from "react";
import { organisationApi } from "@/api";
import { brand } from "@/app/theme";
import BottleSkyline from "@/components/brand/BottleSkyline";
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
    <WorkspacePage section={section} title={title} description="Selling happens on the full-screen till. Cashiers sign in there with their PIN.">

      <Paper radius="xl" style={{ background: brand.navyRaised, overflow: "hidden" }}>
        <Group justify="space-between" align="flex-end" wrap="nowrap" gap="xl">
          <Stack gap="md" p="xl" maw={520}>
            <Title order={2} c="white" fz={32} className="tessera-display">
              Open the till on this device
            </Title>
            <Text c="gray.4" size="sm">
              If this computer or tablet is set up as a till, cashiers will see &ldquo;Who&apos;s on the till?&rdquo;. If not, a manager can set it up in one step.
            </Text>
            <Button component={Link} href="/till" size="md" color="amber.5" c={brand.navy} leftSection={<IconCalculator size={18} />} w="fit-content">
              Open till screen
            </Button>
          </Stack>
          <Stack visibleFrom="md" w={360} pr="xl">
            <BottleSkyline height={150} withShelf={false} />
          </Stack>
        </Group>
      </Paper>

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
