"use client";

import { ActionIcon, Avatar, Button, Chip, Group, Menu, Modal, NumberInput, Pagination, Select, SimpleGrid, Stack, Table, Text, TextInput } from "@mantine/core";
import { useDebouncedValue } from "@mantine/hooks";
import { IconAdjustmentsHorizontal, IconBottle, IconDots, IconPlus, IconSearch } from "@tabler/icons-react";
import { useCallback, useMemo, useState } from "react";
import { inventoryApi, organisationApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import { useCatalogueReference } from "@/modules/catalogue/hooks/useCatalogueReference";
import AdjustmentFormModal from "@/modules/inventory/components/AdjustmentFormModal";
import StockStatusBadge from "@/modules/inventory/components/StockStatusBadge";
import type { StockFilters, StockRow } from "@/types/inventory";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes } from "@/utils/money";

const fetchBranches = () => organisationApi.branches();

type StatusFilter = "all" | NonNullable<StockFilters["stockStatus"]>;

/** Where the available units are, plus stock that cannot be sold yet. */
function whereabouts(row: StockRow): string {
  return [
    `Floor ${row.onFloor} · Store ${row.inStore}`,
    row.quarantined ? `${row.quarantined} quarantined` : null,
    row.inTransit ? `${row.inTransit} in transit` : null,
  ]
    .filter(Boolean)
    .join(" · ");
}

export default function StockOnHandView({ title, section }: WorkspaceViewProps) {
  const { can } = usePermissions();
  const { categoryOptions } = useCatalogueReference();
  const branches = useApiQuery(fetchBranches);
  const [search, setSearch] = useState("");
  const [debouncedSearch] = useDebouncedValue(search.trim(), 350);
  const [branchId, setBranchId] = useState<string | null>(null);
  const [categoryId, setCategoryId] = useState<string | null>(null);
  const [status, setStatus] = useState<StatusFilter>("all");
  const [page, setPage] = useState(1);
  const [reorderRow, setReorderRow] = useState<StockRow | null>(null);
  const [reporting, setReporting] = useState(false);

  const fetchStock = useCallback(
    () =>
      inventoryApi.stock({
        search: debouncedSearch || undefined,
        branchId: branchId ? Number(branchId) : undefined,
        categoryId: categoryId ? Number(categoryId) : undefined,
        stockStatus: status === "all" ? undefined : status,
        page,
      }),
    [debouncedSearch, branchId, categoryId, status, page],
  );
  const fetchOverview = useCallback(() => inventoryApi.overview(branchId ? Number(branchId) : undefined), [branchId]);
  const { data, loading, error, reload } = useApiQuery(fetchStock);
  const overview = useApiQuery(fetchOverview);
  const showValue = can(PERMISSIONS.REPORTS_PROFIT_VIEW);
  const multiBranch = (branches.data?.length ?? 0) > 1;
  const branchOptions = useMemo(() => (branches.data ?? []).map((b) => ({ value: String(b.id), label: `${b.code} · ${b.name}` })), [branches.data]);
  const counts = overview.data;

  const reset = <T,>(setter: (v: T) => void) => (v: T) => {
    setter(v);
    setPage(1);
  };
  const reloadAll = () => {
    reload();
    overview.reload();
  };

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Available = shop floor + store. Quarantined (damaged/returns) and in-transit stock are shown separately and cannot be sold."
      actions={
        can(PERMISSIONS.INVENTORY_BREAKAGE_REPORT) || can(PERMISSIONS.INVENTORY_ADJUST) ? (
          <Button leftSection={<IconPlus size={16} />} onClick={() => setReporting(true)}>
            Report breakage / adjust
          </Button>
        ) : undefined
      }
    >
      <Group gap="sm" wrap="wrap" justify="space-between">
        <Group gap="sm" wrap="wrap">
          <TextInput placeholder="Search product, SKU or barcode" leftSection={<IconSearch size={16} />} value={search} onChange={(e) => reset(setSearch)(e.currentTarget.value)} w={280} />
          {multiBranch && <Select placeholder="All branches" data={branchOptions} value={branchId} onChange={reset(setBranchId)} clearable w={200} />}
          <Select placeholder="All categories" data={categoryOptions} value={categoryId} onChange={reset(setCategoryId)} searchable clearable w={220} />
        </Group>
        <Chip.Group multiple={false} value={status} onChange={(v) => reset(setStatus)(v as StatusFilter)}>
          <Group gap="xs">
            <Chip value="all" variant="outline">
              All{counts ? ` (${counts.items + counts.outCount})` : ""}
            </Chip>
            <Chip value="low" variant="outline" color="amber">
              Low stock{counts ? ` (${counts.lowCount})` : ""}
            </Chip>
            <Chip value="out" variant="outline" color="red">
              Out of stock{counts ? ` (${counts.outCount})` : ""}
            </Chip>
          </Group>
        </Chip.Group>
      </Group>

      <DataCard>
        <QueryState
          loading={loading}
          error={error}
          isEmpty={!data?.items.length}
          emptyMessage={status === "low" ? "Nothing is low on stock." : status === "out" ? "Nothing is out of stock." : "No items match."}
          onRetry={reload}
        >
          <Table highlightOnHover verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Product</Table.Th>
                <Table.Th>Category</Table.Th>
                {multiBranch && <Table.Th>Branch</Table.Th>}
                <Table.Th ta="right">In stock</Table.Th>
                <Table.Th ta="right">Reorder at</Table.Th>
                {showValue && <Table.Th ta="right">Buying</Table.Th>}
                <Table.Th ta="right">Selling</Table.Th>
                {showValue && <Table.Th ta="right">Margin</Table.Th>}
                {showValue && <Table.Th ta="right">Value (cost)</Table.Th>}
                <Table.Th>Status</Table.Th>
                <Table.Th />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((row) => (
                <Table.Tr key={`${row.variantId}-${row.branchId}`}>
                  <Table.Td>
                    <Group gap="sm" wrap="nowrap">
                      <Avatar src={row.imageUrl} alt="" radius="md" size={40} color="tessera">
                        <IconBottle size={20} />
                      </Avatar>
                      <div>
                        <Text size="sm" fw={600}>
                          {row.displayName}
                        </Text>
                        <Text size="xs" c="dimmed" ff="monospace">
                          {row.sku}
                        </Text>
                      </div>
                    </Group>
                  </Table.Td>
                  <Table.Td>{row.categoryName}</Table.Td>
                  {multiBranch && <Table.Td>{row.branchCode}</Table.Td>}
                  <Table.Td ta="right">
                    <Text size="sm" fw={700} c={row.status === "out" ? "red.7" : row.status === "low" ? "amber.8" : undefined}>
                      {row.available}
                    </Text>
                    <Text size="xs" c="dimmed" style={{ whiteSpace: "nowrap" }}>
                      {whereabouts(row)}
                    </Text>
                  </Table.Td>
                  <Table.Td ta="right">{row.reorderLevel ?? "—"}</Table.Td>
                  {showValue && <Table.Td ta="right">{formatKes(row.avgCostCents)}</Table.Td>}
                  <Table.Td ta="right">{formatKes(row.sellingPriceCents)}</Table.Td>
                  {showValue && <Table.Td ta="right">{row.marginPercent !== null ? `${row.marginPercent}%` : "—"}</Table.Td>}
                  {showValue && <Table.Td ta="right">{formatKes(row.valueCents)}</Table.Td>}
                  <Table.Td>
                    <StockStatusBadge status={row.status} />
                  </Table.Td>
                  <Table.Td>
                    {can(PERMISSIONS.INVENTORY_ADJUST_APPROVE) && (
                      <Menu position="bottom-end" withinPortal>
                        <Menu.Target>
                          <ActionIcon variant="subtle" color="gray" aria-label={`Actions for ${row.displayName}`}>
                            <IconDots size={16} />
                          </ActionIcon>
                        </Menu.Target>
                        <Menu.Dropdown>
                          <Menu.Item leftSection={<IconAdjustmentsHorizontal size={14} />} onClick={() => setReorderRow(row)}>
                            Set reorder level
                          </Menu.Item>
                        </Menu.Dropdown>
                      </Menu>
                    )}
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </QueryState>
      </DataCard>

      {data && (
        <Group justify="space-between">
          <Text size="sm" c="dimmed">
            {data.meta.total} rows
            {showValue && counts?.costValueCents != null ? ` · total stock value ${formatKes(counts.costValueCents)} at cost` : ""}
          </Text>
          {data.meta.lastPage > 1 && <Pagination value={page} onChange={setPage} total={data.meta.lastPage} size="sm" />}
        </Group>
      )}

      {reorderRow && (
        <ReorderModal
          row={reorderRow}
          onClose={() => setReorderRow(null)}
          onSaved={() => {
            setReorderRow(null);
            reloadAll();
          }}
        />
      )}
      {reporting && (
        <AdjustmentFormModal
          onClose={() => setReporting(false)}
          onSaved={() => {
            setReporting(false);
            reloadAll();
          }}
        />
      )}
    </WorkspacePage>
  );
}

function ReorderModal({ row, onClose, onSaved }: { row: StockRow; onClose: () => void; onSaved: () => void }) {
  const [level, setLevel] = useState<number | string>(row.reorderLevel ?? "");
  const [quantity, setQuantity] = useState<number | string>(row.reorderQuantity ?? "");

  const { mutate, pending } = useApiMutation(
    () => inventoryApi.setReorderLevel(row.branchId, row.variantId, level === "" ? null : Number(level), quantity === "" ? null : Number(quantity)),
    { successMessage: "Reorder level saved.", onSuccess: onSaved },
  );

  return (
    <Modal opened onClose={onClose} title={`Reorder level — ${row.displayName}`} centered>
      <Stack>
        <Text size="sm" c="dimmed">
          When available stock{row.branchCode ? ` at ${row.branchCode}` : ""} falls to this level, the item shows as low stock. Leave empty to turn off.
        </Text>
        <SimpleGrid cols={2}>
          <NumberInput label="Reorder at" min={0} value={level} onChange={setLevel} />
          <NumberInput label="Order quantity" min={1} value={quantity} onChange={setQuantity} />
        </SimpleGrid>
        <Group justify="flex-end">
          <Button variant="default" onClick={onClose} disabled={pending}>
            Cancel
          </Button>
          <Button loading={pending} onClick={() => void mutate()}>
            Save
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}
