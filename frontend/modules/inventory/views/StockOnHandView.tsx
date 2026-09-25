"use client";

import { ActionIcon, Badge, Button, Group, Menu, Modal, NumberInput, Pagination, Select, SimpleGrid, Stack, Switch, Table, Text, TextInput } from "@mantine/core";
import { useDebouncedValue } from "@mantine/hooks";
import { IconAdjustmentsHorizontal, IconAlertTriangle, IconDots, IconPlus, IconSearch } from "@tabler/icons-react";
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
import type { StockRow } from "@/types/inventory";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes } from "@/utils/money";

const fetchBranches = () => organisationApi.branches();

export default function StockOnHandView({ title, section }: WorkspaceViewProps) {
  const { can } = usePermissions();
  const { categoryOptions } = useCatalogueReference();
  const branches = useApiQuery(fetchBranches);
  const [search, setSearch] = useState("");
  const [debouncedSearch] = useDebouncedValue(search.trim(), 350);
  const [branchId, setBranchId] = useState<string | null>(null);
  const [categoryId, setCategoryId] = useState<string | null>(null);
  const [lowOnly, setLowOnly] = useState(false);
  const [page, setPage] = useState(1);
  const [reorderRow, setReorderRow] = useState<StockRow | null>(null);
  const [reporting, setReporting] = useState(false);

  const fetchStock = useCallback(
    () =>
      inventoryApi.stock({
        search: debouncedSearch || undefined,
        branchId: branchId ? Number(branchId) : undefined,
        categoryId: categoryId ? Number(categoryId) : undefined,
        lowOnly,
        page,
      }),
    [debouncedSearch, branchId, categoryId, lowOnly, page],
  );
  const { data, loading, error, reload } = useApiQuery(fetchStock);
  const showValue = can(PERMISSIONS.REPORTS_PROFIT_VIEW);
  const multiBranch = (branches.data?.length ?? 0) > 1;
  const branchOptions = useMemo(() => (branches.data ?? []).map((b) => ({ value: String(b.id), label: `${b.code} · ${b.name}` })), [branches.data]);

  const reset = <T,>(setter: (v: T) => void) => (v: T) => {
    setter(v);
    setPage(1);
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
      <Group gap="sm" wrap="wrap">
        <TextInput placeholder="Search name, SKU or barcode" leftSection={<IconSearch size={16} />} value={search} onChange={(e) => reset(setSearch)(e.currentTarget.value)} w={280} />
        {multiBranch && <Select placeholder="All branches" data={branchOptions} value={branchId} onChange={reset(setBranchId)} clearable w={200} />}
        <Select placeholder="All categories" data={categoryOptions} value={categoryId} onChange={reset(setCategoryId)} searchable clearable w={220} />
        <Switch label="Low stock only" checked={lowOnly} onChange={(e) => reset(setLowOnly)(e.currentTarget.checked)} />
      </Group>

      <DataCard>
        <QueryState loading={loading} error={error} isEmpty={!data?.items.length} emptyMessage={lowOnly ? "Nothing is below its reorder level." : "No items match."} onRetry={reload}>
          <Table highlightOnHover verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Item</Table.Th>
                {multiBranch && <Table.Th>Branch</Table.Th>}
                <Table.Th ta="right">Floor</Table.Th>
                <Table.Th ta="right">Store</Table.Th>
                <Table.Th ta="right">Available</Table.Th>
                <Table.Th ta="right">Quarantine</Table.Th>
                <Table.Th ta="right">In transit</Table.Th>
                <Table.Th ta="right">Reorder at</Table.Th>
                {showValue && <Table.Th ta="right">Value</Table.Th>}
                <Table.Th />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((row) => (
                <Table.Tr key={`${row.variantId}-${row.branchId}`}>
                  <Table.Td>
                    <Group gap={6} wrap="nowrap">
                      {row.isLow && <IconAlertTriangle size={16} color="var(--mantine-color-amber-6)" aria-label="Low stock" />}
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
                  {multiBranch && <Table.Td>{row.branchCode}</Table.Td>}
                  <Table.Td ta="right">{row.onFloor}</Table.Td>
                  <Table.Td ta="right">{row.inStore}</Table.Td>
                  <Table.Td ta="right">
                    <Badge color={row.isLow ? "amber" : row.available > 0 ? "tessera" : "gray"} variant={row.isLow ? "filled" : "light"} size="lg" radius="sm">
                      {row.available}
                    </Badge>
                  </Table.Td>
                  <Table.Td ta="right" c={row.quarantined ? undefined : "dimmed"}>
                    {row.quarantined}
                  </Table.Td>
                  <Table.Td ta="right" c={row.inTransit ? undefined : "dimmed"}>
                    {row.inTransit}
                  </Table.Td>
                  <Table.Td ta="right">{row.reorderLevel ?? "—"}</Table.Td>
                  {showValue && <Table.Td ta="right">{formatKes(row.valueCents)}</Table.Td>}
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

      {data && data.meta.lastPage > 1 && (
        <Group justify="space-between">
          <Text size="sm" c="dimmed">
            {data.meta.total} rows
          </Text>
          <Pagination value={page} onChange={setPage} total={data.meta.lastPage} size="sm" />
        </Group>
      )}

      {reorderRow && (
        <ReorderModal
          row={reorderRow}
          onClose={() => setReorderRow(null)}
          onSaved={() => {
            setReorderRow(null);
            reload();
          }}
        />
      )}
      {reporting && (
        <AdjustmentFormModal
          onClose={() => setReporting(false)}
          onSaved={() => {
            setReporting(false);
            reload();
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
