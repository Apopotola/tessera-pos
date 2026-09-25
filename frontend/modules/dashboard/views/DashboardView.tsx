"use client";

import { Badge, Group, Paper, SimpleGrid, Stack, Text, ThemeIcon } from "@mantine/core";
import { IconBuildingStore, IconShieldCheck, IconUser } from "@tabler/icons-react";
import { useCallback } from "react";
import { organisationApi } from "@/api";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import PageHeader from "@/components/shared/PageHeader";
import QueryState from "@/components/shared/QueryState";
import { useApiQuery } from "@/hooks/useApiQuery";
import { useAppSelector } from "@/store/hooks";

/**
 * Sales, stock and eTIMS KPIs arrive with their modules (see requirements, "Dashboard").
 * Until then the dashboard shows the signed-in context only — no placeholder figures.
 */
export default function DashboardView({ title }: WorkspaceViewProps) {
  const user = useAppSelector((state) => state.auth.user);
  const fetchBranches = useCallback(() => organisationApi.branches(), []);
  const { data: branches, loading, error, reload } = useApiQuery(fetchBranches);

  return (
    <Stack p="md" gap="md">
      <PageHeader title={`${title} — welcome, ${user?.name ?? ""}`} description="Business KPIs appear here as each module goes live." />

      <SimpleGrid cols={{ base: 1, sm: 3 }}>
        <Paper withBorder p="md">
          <Group>
            <ThemeIcon variant="light" size="lg">
              <IconUser size={20} />
            </ThemeIcon>
            <div>
              <Text size="xs" c="dimmed">
                Signed in as
              </Text>
              <Text fw={600}>{user?.roles.join(", ") || "No role assigned"}</Text>
            </div>
          </Group>
        </Paper>
        <Paper withBorder p="md">
          <Group>
            <ThemeIcon variant="light" size="lg">
              <IconShieldCheck size={20} />
            </ThemeIcon>
            <div>
              <Text size="xs" c="dimmed">
                Permissions
              </Text>
              <Text fw={600}>{user?.permissions.length ?? 0}</Text>
            </div>
          </Group>
        </Paper>
        <Paper withBorder p="md">
          <Group>
            <ThemeIcon variant="light" size="lg">
              <IconBuildingStore size={20} />
            </ThemeIcon>
            <div>
              <Text size="xs" c="dimmed">
                Your branches
              </Text>
              <Text fw={600}>{loading ? "…" : (branches?.length ?? 0)}</Text>
            </div>
          </Group>
        </Paper>
      </SimpleGrid>

      <Paper withBorder p="md">
        <Text fw={600} mb="sm">
          Branches you can work in
        </Text>
        <QueryState loading={loading} error={error} isEmpty={!branches?.length} emptyMessage="No branch assigned yet." onRetry={reload}>
          <Group gap="xs">
            {branches?.map((branch) => (
              <Badge key={branch.id} variant="light" size="lg">
                {branch.code} · {branch.name}
              </Badge>
            ))}
          </Group>
        </QueryState>
      </Paper>
    </Stack>
  );
}
