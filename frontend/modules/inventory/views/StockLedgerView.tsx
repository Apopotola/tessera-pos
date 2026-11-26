"use client";

import { Badge, Group, Pagination, Select, Table, Text } from "@mantine/core";
import { DatePickerInput, type DatesRangeValue } from "@mantine/dates";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { inventoryApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiQuery } from "@/hooks/useApiQuery";
import VariantPicker, { type PickedVariant } from "@/modules/inventory/components/VariantPicker";
import { MOVEMENT_LABEL } from "@/modules/inventory/constants";
import type { MovementType } from "@/types/inventory";
import { formatKes } from "@/utils/money";

const TYPE_OPTIONS = Object.entries(MOVEMENT_LABEL).map(([value, label]) => ({ value, label }));

/** Every stock change, newest first. Rows can never be edited — corrections appear as new rows. */
export default function StockLedgerView({ title, section }: WorkspaceViewProps) {
  const [variant, setVariant] = useState<PickedVariant | null>(null);
  const [type, setType] = useState<MovementType | null>(null);
  const [range, setRange] = useState<DatesRangeValue<string>>([null, null]);
  const [page, setPage] = useState(1);

  const fetchMovements = useCallback(
    () =>
      inventoryApi.movements({
        variantId: variant?.id,
        type: type ?? undefined,
        from: range[0] ?? undefined,
        to: range[1] ?? undefined,
        page,
      }),
    [variant, type, range, page],
  );
  const { data, loading, error, reload } = useApiQuery(fetchMovements);
  const showCost = data?.items.some((m) => m.unitCostCents !== null) ?? false;

  return (
    <WorkspacePage section={section} title={title} description="Every stock movement with who made it and who approved it. Nothing here can be edited or deleted.">
      <Group gap="sm" wrap="wrap" align="flex-end">
        <div style={{ width: 320 }}>
          <VariantPicker
            value={variant}
            onChange={(v) => {
              setVariant(v);
              setPage(1);
            }}
            placeholder="All items"
          />
        </div>
        <Select
          placeholder="All movement types"
          data={TYPE_OPTIONS}
          value={type}
          onChange={(v) => {
            setType(v as MovementType | null);
            setPage(1);
          }}
          clearable
          w={200}
        />
        <DatePickerInput
          type="range"
          placeholder="Any date"
          value={range}
          onChange={(v) => {
            setRange(v);
            setPage(1);
          }}
          clearable
          valueFormat="DD MMM YYYY"
          w={260}
        />
      </Group>

      <DataCard>
        <QueryState loading={loading} error={error} isEmpty={!data?.items.length} emptyMessage="No stock movements match." onRetry={reload}>
          <Table verticalSpacing="xs">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>When</Table.Th>
                <Table.Th>Reference</Table.Th>
                <Table.Th>Type</Table.Th>
                <Table.Th>Item</Table.Th>
                <Table.Th>Location</Table.Th>
                <Table.Th ta="right">Qty</Table.Th>
                {showCost && <Table.Th ta="right">Unit cost</Table.Th>}
                <Table.Th>By / approved</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((m) => (
                <Table.Tr key={m.id}>
                  <Table.Td>
                    <Text size="sm">{dayjs(m.occurredAt).format("DD MMM YYYY HH:mm")}</Text>
                  </Table.Td>
                  <Table.Td>
                    <Text size="sm" ff="monospace">
                      {m.reference}
                    </Text>
                  </Table.Td>
                  <Table.Td>
                    <Badge variant="light" color="gray" radius="sm">
                      {MOVEMENT_LABEL[m.type]}
                    </Badge>
                  </Table.Td>
                  <Table.Td>
                    <Text size="sm">{m.variant.displayName}</Text>
                    {m.reason && (
                      <Text size="xs" c="dimmed" lineClamp={1}>
                        {m.reason}
                      </Text>
                    )}
                  </Table.Td>
                  <Table.Td>
                    <Text size="sm">
                      {m.location} <Text span c="dimmed">({m.branchCode})</Text>
                    </Text>
                  </Table.Td>
                  <Table.Td ta="right">
                    <Text fw={700} c={m.quantity > 0 ? "green.7" : "red.7"}>
                      {m.quantity > 0 ? `+${m.quantity}` : m.quantity}
                    </Text>
                  </Table.Td>
                  {showCost && <Table.Td ta="right">{formatKes(m.unitCostCents)}</Table.Td>}
                  <Table.Td>
                    <Text size="sm">{m.user?.name ?? "—"}</Text>
                    {m.approvedBy && (
                      <Text size="xs" c="dimmed">
                        ✓ {m.approvedBy.name}
                      </Text>
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
