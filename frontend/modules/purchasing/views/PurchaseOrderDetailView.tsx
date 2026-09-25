"use client";

import { Alert, Button, Group, SimpleGrid, Stack, Table, Text } from "@mantine/core";
import { modals } from "@mantine/modals";
import { IconPackageImport, IconSend } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useEffect, useState } from "react";
import { purchasingApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import DocStatusBadge from "@/components/shared/DocStatusBadge";
import NoteModal from "@/components/shared/NoteModal";
import QueryState from "@/components/shared/QueryState";
import StatTile from "@/components/shared/StatTile";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import ReceiveGoodsModal from "@/modules/purchasing/components/ReceiveGoodsModal";
import { PO_STATUS_COLOR, PO_STATUS_LABEL } from "@/modules/purchasing/constants";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { updateTab } from "@/store/slices/tabsSlice";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes } from "@/utils/money";

export default function PurchaseOrderDetailView({ tabId, title, section, props }: WorkspaceViewProps) {
  const orderId = Number(props?.orderId);
  const dispatch = useAppDispatch();
  const { can } = usePermissions();
  const userId = useAppSelector((state) => state.auth.user?.id);
  const fetchOrder = useCallback(() => purchasingApi.order(orderId), [orderId]);
  const { data: order, loading, error, reload } = useApiQuery(fetchOrder);
  const [receiving, setReceiving] = useState(false);
  const [cancelling, setCancelling] = useState(false);

  const number = order?.number;
  useEffect(() => {
    if (number) dispatch(updateTab({ tabId, title: number }));
  }, [dispatch, tabId, number]);

  const approve = useApiMutation(() => purchasingApi.approveOrder(orderId), { successMessage: "Purchase order approved.", onSuccess: reload });
  const send = useApiMutation(() => purchasingApi.sendOrder(orderId), { successMessage: "Marked as sent to the supplier.", onSuccess: reload });

  if (!Number.isFinite(orderId) || orderId <= 0) {
    return (
      <WorkspacePage section={section} title={title}>
        <Alert color="red">This tab has no purchase order attached.</Alert>
      </WorkspacePage>
    );
  }

  const status = order?.status;
  const canApprove = status === "draft" && can(PERMISSIONS.PURCHASING_APPROVE) && order?.createdBy?.id !== userId;
  const canReceive = (status === "approved" || status === "sent" || status === "partially_received") && can(PERMISSIONS.INVENTORY_RECEIVE);
  const canCancel = (status === "draft" || status === "approved" || status === "sent") && (can(PERMISSIONS.PURCHASING_MANAGE) || can(PERMISSIONS.PURCHASING_APPROVE));

  return (
    <WorkspacePage
      section={section}
      title={order ? `${order.number} · ${order.supplier.name}` : title}
      description={order ? `Deliver to ${order.location.name}${order.expectedDate ? ` · expected ${dayjs(order.expectedDate).format("DD MMM YYYY")}` : ""}` : undefined}
      actions={
        order && (
          <Group gap="xs">
            <DocStatusBadge label={PO_STATUS_LABEL[order.status]} color={PO_STATUS_COLOR[order.status]} />
            {canApprove && (
              <Button
                loading={approve.pending}
                onClick={() =>
                  modals.openConfirmModal({
                    title: `Approve ${order.number}?`,
                    children: <Text size="sm">Commit to buying {formatKes(order.totals.grossCents)} (incl. VAT) from {order.supplier.name}.</Text>,
                    labels: { confirm: "Approve", cancel: "Cancel" },
                    onConfirm: () => void approve.mutate(),
                  })
                }
              >
                Approve
              </Button>
            )}
            {status === "approved" && can(PERMISSIONS.PURCHASING_MANAGE) && (
              <Button variant="default" leftSection={<IconSend size={16} />} loading={send.pending} onClick={() => void send.mutate()}>
                Mark as sent
              </Button>
            )}
            {canReceive && (
              <Button leftSection={<IconPackageImport size={16} />} onClick={() => setReceiving(true)}>
                Receive goods
              </Button>
            )}
            {canCancel && (
              <Button variant="subtle" color="red" onClick={() => setCancelling(true)}>
                Cancel order
              </Button>
            )}
          </Group>
        )
      }
    >
      <QueryState loading={loading && !order} error={error} isEmpty={false} onRetry={reload}>
        {order && (
          <Stack gap="lg">
            {order.status === "draft" && order.createdBy?.id === userId && (
              <Alert variant="light">You raised this order, so someone else with approval rights must approve it.</Alert>
            )}
            {order.cancelReason && <Alert color="gray">Cancelled: {order.cancelReason}</Alert>}

            <SimpleGrid cols={{ base: 2, md: 4 }}>
              <StatTile label="Subtotal excl. VAT" value={formatKes(order.totals.netCents)} />
              <StatTile label="VAT" value={formatKes(order.totals.vatCents)} />
              <StatTile label="Total" value={formatKes(order.totals.grossCents)} />
              <StatTile
                label="Still to arrive"
                value={order.lines.reduce((s, l) => s + l.outstanding, 0)}
                hint={`of ${order.lines.reduce((s, l) => s + l.quantityOrdered, 0)} units`}
              />
            </SimpleGrid>

            <DataCard title="Items">
              <Table verticalSpacing="sm">
                <Table.Thead>
                  <Table.Tr>
                    <Table.Th>Item</Table.Th>
                    <Table.Th ta="right">Ordered</Table.Th>
                    <Table.Th ta="right">Received</Table.Th>
                    <Table.Th ta="right">Damaged</Table.Th>
                    <Table.Th ta="right">To come</Table.Th>
                    <Table.Th ta="right">Unit cost</Table.Th>
                    <Table.Th ta="right">VAT</Table.Th>
                    <Table.Th ta="right">Line total</Table.Th>
                  </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                  {order.lines.map((l) => (
                    <Table.Tr key={l.id}>
                      <Table.Td>
                        <Text size="sm" fw={600}>
                          {l.variant.displayName}
                        </Text>
                        <Text size="xs" c="dimmed" ff="monospace">
                          {l.variant.sku}
                        </Text>
                      </Table.Td>
                      <Table.Td ta="right">{l.quantityOrdered}</Table.Td>
                      <Table.Td ta="right">{l.quantityReceived}</Table.Td>
                      <Table.Td ta="right" c={l.quantityDamaged ? "red.7" : "dimmed"}>
                        {l.quantityDamaged}
                      </Table.Td>
                      <Table.Td ta="right" fw={l.outstanding ? 700 : undefined}>
                        {l.outstanding}
                      </Table.Td>
                      <Table.Td ta="right">{formatKes(l.unitCostCents)}</Table.Td>
                      <Table.Td ta="right">{l.taxRatePercent}%</Table.Td>
                      <Table.Td ta="right">{formatKes(l.lineTotalCents)}</Table.Td>
                    </Table.Tr>
                  ))}
                </Table.Tbody>
              </Table>
            </DataCard>

            <DataCard title="Goods received" description={order.receipts.length ? undefined : "Nothing received yet."}>
              {order.receipts.length > 0 && (
                <Table verticalSpacing="sm">
                  <Table.Thead>
                    <Table.Tr>
                      <Table.Th>GRN</Table.Th>
                      <Table.Th>Received</Table.Th>
                      <Table.Th>Items</Table.Th>
                      <Table.Th ta="right">Value incl. VAT</Table.Th>
                      <Table.Th>Invoiced</Table.Th>
                    </Table.Tr>
                  </Table.Thead>
                  <Table.Tbody>
                    {order.receipts.map((g) => (
                      <Table.Tr key={g.id}>
                        <Table.Td>
                          <Text size="sm" ff="monospace">
                            {g.number}
                          </Text>
                          {g.deliveryNoteRef && (
                            <Text size="xs" c="dimmed">
                              DN {g.deliveryNoteRef}
                            </Text>
                          )}
                        </Table.Td>
                        <Table.Td>
                          <Text size="sm">{g.receivedBy?.name}</Text>
                          <Text size="xs" c="dimmed">
                            {g.receivedAt ? dayjs(g.receivedAt).format("DD MMM YYYY HH:mm") : ""}
                          </Text>
                        </Table.Td>
                        <Table.Td>
                          {g.lines.map((l, i) => (
                            <Text key={i} size="sm">
                              {l.quantityReceived} × {l.variant.displayName}
                              {l.quantityDamaged > 0 && (
                                <Text span c="red.7" size="xs">
                                  {" "}
                                  (+{l.quantityDamaged} damaged)
                                </Text>
                              )}
                            </Text>
                          ))}
                        </Table.Td>
                        <Table.Td ta="right">{formatKes(g.valueCents)}</Table.Td>
                        <Table.Td>
                          <DocStatusBadge label={g.invoiced ? "Invoiced" : "Not yet"} color={g.invoiced ? "green" : "gray"} />
                        </Table.Td>
                      </Table.Tr>
                    ))}
                  </Table.Tbody>
                </Table>
              )}
            </DataCard>
          </Stack>
        )}
      </QueryState>

      {receiving && order && (
        <ReceiveGoodsModal
          order={order}
          onClose={() => setReceiving(false)}
          onDone={() => {
            setReceiving(false);
            reload();
          }}
        />
      )}
      {cancelling && order && (
        <NoteModal
          title={`Cancel ${order.number}`}
          label="Why is it cancelled?"
          confirmLabel="Cancel order"
          danger
          successMessage={`${order.number} cancelled.`}
          onSubmit={(note) => purchasingApi.cancelOrder(order.id, note)}
          onClose={() => setCancelling(false)}
          onDone={() => {
            setCancelling(false);
            reload();
          }}
        />
      )}
    </WorkspacePage>
  );
}
