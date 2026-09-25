"use client";

import { Button, Stack, Table, Text } from "@mantine/core";
import { modals } from "@mantine/modals";
import dayjs from "dayjs";
import { useCallback, useMemo } from "react";
import { organisationApi } from "@/api";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import DataCard from "@/components/shared/DataCard";
import StatusBadge from "@/components/shared/StatusBadge";
import WorkspacePage from "@/components/shared/WorkspacePage";
import QueryState from "@/components/shared/QueryState";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import type { Till } from "@/types/till";
import { formatKes } from "@/utils/money";

export default function BranchesView({ title, section }: WorkspaceViewProps) {
  const fetchBranches = useCallback(() => organisationApi.branches(), []);
  const fetchTills = useCallback(() => organisationApi.tills(), []);
  const { data: branches, loading, error, reload } = useApiQuery(fetchBranches);
  const tills = useApiQuery(fetchTills);
  const branchCode = useMemo(() => new Map((branches ?? []).map((b) => [b.id, b.code])), [branches]);

  const unpair = useApiMutation((till: Till) => organisationApi.unpairTill(till.id), {
    successMessage: "Till device disconnected.",
    onSuccess: tills.reload,
  });

  const confirmUnpair = (till: Till) =>
    modals.openConfirmModal({
      title: `Disconnect ${till.name}?`,
      children: (
        <Text size="sm">
          The device stops working as a till immediately. Use this for a lost or replaced device. Set it up again from the till screen.
        </Text>
      ),
      labels: { confirm: "Disconnect", cancel: "Cancel" },
      confirmProps: { color: "red" },
      onConfirm: () => void unpair.mutate(till),
    });

  return (
    <WorkspacePage section={section} title={title} description="Outlets and warehouses in this business.">
      <DataCard title="Branches">
        <QueryState loading={loading} error={error} isEmpty={!branches?.length} emptyMessage="No branches yet." onRetry={reload}>
          <Table striped highlightOnHover verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Code</Table.Th>
                <Table.Th>Name</Table.Th>
                <Table.Th>Type</Table.Th>
                <Table.Th>Phone</Table.Th>
                <Table.Th>Status</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {branches?.map((branch) => (
                <Table.Tr key={branch.id}>
                  <Table.Td fw={600}>{branch.code}</Table.Td>
                  <Table.Td>{branch.name}</Table.Td>
                  <Table.Td>{branch.isWarehouse ? "Warehouse" : "Outlet"}</Table.Td>
                  <Table.Td>{branch.phone ?? "—"}</Table.Td>
                  <Table.Td>
                    <StatusBadge active={branch.isActive} />
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </QueryState>
      </DataCard>

      <DataCard title="Tills" description="To add a till, open /till on the till device and sign in as a manager.">
        <QueryState loading={tills.loading} error={tills.error} isEmpty={!tills.data?.length} emptyMessage="No tills set up yet." onRetry={tills.reload}>
          <Table verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Till</Table.Th>
                <Table.Th>Branch</Table.Th>
                <Table.Th>Opening float</Table.Th>
                <Table.Th>Device</Table.Th>
                <Table.Th />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {tills.data?.map((till) => (
                <Table.Tr key={till.id}>
                  <Table.Td>
                    <Text size="sm" fw={600}>
                      {till.name}
                    </Text>
                    <Text size="xs" c="dimmed">
                      {till.description}
                    </Text>
                  </Table.Td>
                  <Table.Td>{branchCode.get(till.branchId) ?? `#${till.branchId}`}</Table.Td>
                  <Table.Td>{formatKes(till.defaultFloatCents)}</Table.Td>
                  <Table.Td>
                    {till.isPaired ? (
                      <Stack gap={0}>
                        <StatusBadge active activeLabel="Connected" />
                        <Text size="xs" c="dimmed">
                          Last seen {till.lastSeenAt ? dayjs(till.lastSeenAt).format("DD MMM HH:mm") : "never"}
                        </Text>
                      </Stack>
                    ) : (
                      <StatusBadge active={false} inactiveLabel="Not connected" />
                    )}
                  </Table.Td>
                  <Table.Td ta="right">
                    {till.isPaired && (
                      <Button size="xs" variant="light" color="red" onClick={() => confirmUnpair(till)}>
                        Disconnect
                      </Button>
                    )}
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </QueryState>
      </DataCard>
    </WorkspacePage>
  );
}
