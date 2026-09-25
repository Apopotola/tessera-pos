"use client";

import { Button, Group, Pagination, SegmentedControl, Table, Text } from "@mantine/core";
import { IconPlus } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { purchasingApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import DocStatusBadge from "@/components/shared/DocStatusBadge";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import PurchaseOrderFormModal from "@/modules/purchasing/components/PurchaseOrderFormModal";
import { PO_STATUS_COLOR, PO_STATUS_LABEL } from "@/modules/purchasing/constants";
import { purchaseOrderTab } from "@/modules/purchasing/routes";
import { useAppDispatch } from "@/store/hooks";
import { openTab } from "@/store/slices/tabsSlice";
import { PERMISSIONS } from "@/types/permissions";
import type { PurchaseOrder, PurchaseOrderStatus } from "@/types/purchasing";
import { formatKes } from "@/utils/money";

type Filter = "open" | PurchaseOrderStatus;

export default function PurchaseOrdersView({ title, section, tabId }: WorkspaceViewProps) {
  const dispatch = useAppDispatch();
  const { can } = usePermissions();
  const [filter, setFilter] = useState<Filter>("open");
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);

  const fetchOrders = useCallback(() => purchasingApi.orders(filter, page), [filter, page]);
  const { data, loading, error, reload } = useApiQuery(fetchOrders);
  const open = (order: Pick<PurchaseOrder, "id" | "number">) => dispatch(openTab(purchaseOrderTab(order, tabId)));

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Orders are raised as drafts, approved by someone else, then received into stock at the order price."
      actions={
        can(PERMISSIONS.PURCHASING_MANAGE) && (
          <Button leftSection={<IconPlus size={16} />} onClick={() => setCreating(true)}>
            New purchase order
          </Button>
        )
      }
    >
      <SegmentedControl
        w="fit-content"
        value={filter}
        onChange={(v) => {
          setFilter(v as Filter);
          setPage(1);
        }}
        data={[
          { value: "open", label: "Open" },
          { value: "draft", label: "Needs approval" },
          { value: "received", label: "Received" },
          { value: "cancelled", label: "Cancelled" },
        ]}
      />

      <DataCard>
        <QueryState loading={loading} error={error} isEmpty={!data?.items.length} emptyMessage="No purchase orders here." onRetry={reload}>
          <Table verticalSpacing="sm" highlightOnHover>
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Number</Table.Th>
                <Table.Th>Supplier</Table.Th>
                <Table.Th>Deliver to</Table.Th>
                <Table.Th ta="right">Items</Table.Th>
                <Table.Th ta="right">Total incl. VAT</Table.Th>
                <Table.Th>Status</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((o) => (
                <Table.Tr key={o.id} style={{ cursor: "pointer" }} onClick={() => open(o)}>
                  <Table.Td>
                    <Text size="sm" ff="monospace">
                      {o.number}
                    </Text>
                    <Text size="xs" c="dimmed">
                      {o.createdBy?.name} · {o.createdAt ? dayjs(o.createdAt).format("DD MMM YYYY") : ""}
                    </Text>
                  </Table.Td>
                  <Table.Td fw={600}>{o.supplier.name}</Table.Td>
                  <Table.Td>{o.location.name}</Table.Td>
                  <Table.Td ta="right">{o.lines.reduce((s, l) => s + l.quantityOrdered, 0)}</Table.Td>
                  <Table.Td ta="right">{formatKes(o.totals.grossCents)}</Table.Td>
                  <Table.Td>
                    <DocStatusBadge label={PO_STATUS_LABEL[o.status]} color={PO_STATUS_COLOR[o.status]} />
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

      {creating && (
        <PurchaseOrderFormModal
          onClose={() => setCreating(false)}
          onSaved={(order) => {
            setCreating(false);
            reload();
            open(order);
          }}
        />
      )}
    </WorkspacePage>
  );
}
