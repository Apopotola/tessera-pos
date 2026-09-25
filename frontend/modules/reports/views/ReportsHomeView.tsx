"use client";

import { Badge, Group, Paper, SimpleGrid, Stack, Text, Title, UnstyledButton } from "@mantine/core";
import { IconArrowUpRight, IconBuildingWarehouse, IconCoin, IconReceipt, IconShieldCheck } from "@tabler/icons-react";
import { useCallback } from "react";
import { reportsApi } from "@/api";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiQuery } from "@/hooks/useApiQuery";
import { reportTab } from "@/modules/reports/routes";
import { useAppDispatch } from "@/store/hooks";
import { openTab } from "@/store/slices/tabsSlice";
import type { ReportCatalogueItem, ReportGroup } from "@/types/reports";

const GROUPS: { key: ReportGroup; title: string; icon: React.ReactNode }[] = [
  { key: "sales", title: "Sales", icon: <IconReceipt size={20} /> },
  { key: "inventory", title: "Inventory", icon: <IconBuildingWarehouse size={20} /> },
  { key: "financial", title: "Financial", icon: <IconCoin size={20} /> },
  { key: "compliance", title: "Compliance", icon: <IconShieldCheck size={20} /> },
];

/** Every report the user may run, grouped. Reports open in their own tab. */
export default function ReportsHomeView({ title, section, tabId }: WorkspaceViewProps) {
  const dispatch = useAppDispatch();
  const fetchCatalogue = useCallback(() => reportsApi.catalogue(), []);
  const { data, loading, error, reload } = useApiQuery(fetchCatalogue);

  const open = (item: ReportCatalogueItem) =>
    dispatch(openTab(item.link ? { title: item.link.title, path: item.link.path, view: item.link.view } : reportTab(item, tabId)));

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Every report reads the permanent sales, payment and stock records. Filter by branch, dates, category, brand or staff, then print or export."
    >
      <QueryState loading={loading} error={error} isEmpty={!data?.reports.length} emptyMessage="No reports are available to you." onRetry={reload}>
        <Stack gap="xl">
          {GROUPS.map((group) => {
            const items = data?.reports.filter((r) => r.group === group.key) ?? [];
            if (items.length === 0) return null;
            return (
              <Stack key={group.key} gap="sm">
                <Group gap="xs" c="tessera.7">
                  {group.icon}
                  <Title order={3} fz="lg" className="tessera-display">
                    {group.title}
                  </Title>
                </Group>
                <SimpleGrid cols={{ base: 1, sm: 2, lg: 3 }}>
                  {items.map((item) => (
                    <UnstyledButton key={item.key} onClick={() => open(item)}>
                      <Paper withBorder radius="lg" p="md" h="100%" style={{ transition: "border-color 120ms ease" }}>
                        <Group justify="space-between" align="flex-start" wrap="nowrap">
                          <Text fw={700}>{item.title}</Text>
                          {item.link ? (
                            <Badge variant="light" color="gray" rightSection={<IconArrowUpRight size={12} />}>
                              Screen
                            </Badge>
                          ) : null}
                        </Group>
                        <Text size="sm" c="dimmed" mt={4}>
                          {item.description}
                        </Text>
                      </Paper>
                    </UnstyledButton>
                  ))}
                </SimpleGrid>
              </Stack>
            );
          })}
        </Stack>
      </QueryState>
    </WorkspacePage>
  );
}
