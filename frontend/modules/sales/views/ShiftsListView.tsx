"use client";

import { Group, Pagination, Table, Text, Tooltip } from "@mantine/core";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { salesApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import DocStatusBadge from "@/components/shared/DocStatusBadge";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiQuery } from "@/hooks/useApiQuery";
import { formatKes } from "@/utils/money";

/** Till shifts with takings by payment method and the blind cash-count result. */
export default function ShiftsListView({ title, section }: WorkspaceViewProps) {
  const [page, setPage] = useState(1);
  const fetchShifts = useCallback(() => salesApi.shifts(page), [page]);
  const { data, loading, error, reload } = useApiQuery(fetchShifts);

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Cashiers count the drawer without seeing the expected amount. Expected cash = opening float + cash sales − cash refunds."
    >
      <DataCard>
        <QueryState loading={loading} error={error} isEmpty={!data?.items.length} emptyMessage="No shifts yet." onRetry={reload}>
          <Table verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Till · cashier</Table.Th>
                <Table.Th>Shift</Table.Th>
                <Table.Th ta="right">Sales</Table.Th>
                <Table.Th ta="right">Cash</Table.Th>
                <Table.Th ta="right">M-PESA</Table.Th>
                <Table.Th ta="right">Card</Table.Th>
                <Table.Th ta="right">Expected</Table.Th>
                <Table.Th ta="right">Counted</Table.Th>
                <Table.Th ta="right">Variance</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((shift) => (
                <Table.Tr key={shift.id}>
                  <Table.Td>
                    <Text size="sm" fw={600}>
                      {shift.tillName}
                    </Text>
                    <Text size="xs" c="dimmed">
                      {shift.user?.name}
                    </Text>
                  </Table.Td>
                  <Table.Td>
                    <Text size="sm">{dayjs(shift.openedAt).format("DD MMM, h:mm a")}</Text>
                    {shift.isOpen ? (
                      <DocStatusBadge label="Open" color="blue" />
                    ) : (
                      <Text size="xs" c="dimmed">
                        to {dayjs(shift.closedAt).format(dayjs(shift.closedAt).isSame(shift.openedAt, "day") ? "h:mm a" : "DD MMM, h:mm a")}
                      </Text>
                    )}
                  </Table.Td>
                  <Table.Td ta="right">{shift.salesCount}</Table.Td>
                  <Table.Td ta="right">{formatKes(shift.takings.cashCents)}</Table.Td>
                  <Table.Td ta="right">{formatKes(shift.takings.mpesaCents)}</Table.Td>
                  <Table.Td ta="right">{formatKes(shift.takings.cardCents)}</Table.Td>
                  <Table.Td ta="right">{shift.isOpen ? "—" : formatKes(shift.expectedCashCents)}</Table.Td>
                  <Table.Td ta="right">{shift.isOpen ? "—" : formatKes(shift.countedCashCents)}</Table.Td>
                  <Table.Td ta="right">
                    {shift.isOpen ? (
                      "—"
                    ) : (
                      <Tooltip label={shift.closeNote ?? "No note"} disabled={!shift.closeNote} withArrow>
                        <Text size="sm" fw={700} c={shift.varianceCents === 0 ? "green.7" : "red.7"}>
                          {(shift.varianceCents ?? 0) > 0 ? "Over " : (shift.varianceCents ?? 0) < 0 ? "Short " : ""}
                          {formatKes(Math.abs(shift.varianceCents ?? 0))}
                        </Text>
                      </Tooltip>
                    )}
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </QueryState>
      </DataCard>

      {data && data.meta.lastPage > 1 && (
        <Group justify="flex-end">
          <Pagination value={page} onChange={setPage} total={data.meta.lastPage} size="sm" />
        </Group>
      )}
    </WorkspacePage>
  );
}
