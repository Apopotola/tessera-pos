"use client";

import { Alert, Button, Divider, Grid, Group, NumberInput, Paper, Select, SimpleGrid, Stack, Table, Text, TextInput, Textarea } from "@mantine/core";
import { DateInput } from "@mantine/dates";
import { IconCheck, IconInfoCircle, IconPrinter } from "@tabler/icons-react";
import dayjs from "dayjs";
import { type ReactNode, useCallback, useMemo, useState } from "react";
import { purchasingApi } from "@/api";
import { fonts } from "@/app/theme";
import DataCard from "@/components/shared/DataCard";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import type { PurchaseOrder, PurchaseOrderStatus } from "@/types/purchasing";
import { formatKes } from "@/utils/money";

const RECEIVABLE: PurchaseOrderStatus[] = ["approved", "sent", "partially_received"];

interface Row {
  received: number | string;
  damaged: number | string;
  batchNumber: string;
  expiryDate: string | null;
}

const fetchOpenOrders = () => purchasingApi.orders("open");
const fetchSuppliers = () => purchasingApi.suppliers();

const blankRows = (order: PurchaseOrder): Record<number, Row> =>
  Object.fromEntries(order.lines.filter((l) => l.outstanding > 0).map((l) => [l.id, { received: l.outstanding, damaged: 0, batchNumber: "", expiryDate: null }]));

/**
 * Goods received note on one page: pick an approved purchase order, count what arrived
 * (good and damaged), check the GRN summary and post it to stock.
 */
export default function ReceiveStockView({ title, section, props }: WorkspaceViewProps) {
  const orders = useApiQuery(fetchOpenOrders);
  const suppliers = useApiQuery(fetchSuppliers);
  const [orderId, setOrderId] = useState<string | null>(typeof props?.orderId === "number" ? String(props.orderId) : null);
  const fetchOrder = useCallback(() => (orderId ? purchasingApi.order(Number(orderId)) : Promise.resolve(null)), [orderId]);
  const order = useApiQuery(fetchOrder);
  const [rows, setRows] = useState<Record<number, Row>>({});
  const [loadedFor, setLoadedFor] = useState<number | null>(null);
  const [deliveryNoteRef, setDeliveryNoteRef] = useState("");
  const [note, setNote] = useState("");
  const [posted, setPosted] = useState<PurchaseOrder | null>(null);

  // Fresh counts each time a different order loads (set during render, not in an effect).
  const po = order.data;
  if (po && loadedFor !== po.id) {
    setLoadedFor(po.id);
    setRows(blankRows(po));
  }

  const receivable = useMemo(() => (orders.data?.items ?? []).filter((o) => RECEIVABLE.includes(o.status)), [orders.data]);
  const orderOptions = receivable.map((o) => ({ value: String(o.id), label: `${o.number} · ${o.supplier.name} · ${formatKes(o.totals.grossCents)}` }));
  const open = po?.lines.filter((l) => l.outstanding > 0) ?? [];
  const supplier = suppliers.data?.find((s) => s.id === po?.supplier.id);
  const set = (id: number, patch: Partial<Row>) => setRows((r) => ({ ...r, [id]: { ...r[id], ...patch } }));
  const num = (v: number | string | undefined) => Number(v) || 0;

  const lineCost = (l: (typeof open)[number]) => num(rows[l.id]?.received) * l.unitCostCents;
  const totalUnits = open.reduce((s, l) => s + num(rows[l.id]?.received), 0);
  const totalDamaged = open.reduce((s, l) => s + num(rows[l.id]?.damaged), 0);
  const totalCost = open.reduce((s, l) => s + lineCost(l), 0);
  const overCount = open.some((l) => num(rows[l.id]?.received) + num(rows[l.id]?.damaged) > l.outstanding);
  const nothing = totalUnits + totalDamaged === 0;

  const { mutate, pending } = useApiMutation(
    () =>
      purchasingApi.receive(
        Number(orderId),
        open.map((l) => ({
          lineId: l.id,
          received: num(rows[l.id]?.received),
          damaged: num(rows[l.id]?.damaged),
          batchNumber: rows[l.id]?.batchNumber.trim() || null,
          expiryDate: rows[l.id]?.expiryDate ?? null,
        })),
        deliveryNoteRef.trim() || null,
        note.trim() || null,
      ),
    {
      successMessage: "Goods received — stock updated.",
      onSuccess: (result) => {
        setPosted(result);
        orders.reload();
      },
    },
  );

  const startNext = () => {
    setPosted(null);
    setOrderId(null);
    setLoadedFor(null);
    setDeliveryNoteRef("");
    setNote("");
  };

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Count a delivery against its approved purchase order. Good units go into stock at the order price; damaged units are recorded for a supplier claim."
      actions={
        posted ? (
          <>
            <Button variant="default" leftSection={<IconPrinter size={16} />} onClick={() => window.print()}>
              Print GRN
            </Button>
            <Button onClick={startNext}>Receive another delivery</Button>
          </>
        ) : undefined
      }
    >
      {posted ? (
        <Alert color="green" variant="light" icon={<IconCheck size={18} />} title={`Posted to stock — ${posted.number}`}>
          {posted.supplier.name} · {totalUnits} units into {posted.location.name} · {formatKes(totalCost)}. The order is now {posted.status.replace("_", " ")}.
        </Alert>
      ) : null}

      <Grid gutter="lg" align="flex-start">
        <Grid.Col span={{ base: 12, lg: 8 }}>
        <Stack gap="lg" style={{ minWidth: 0 }}>
          <DataCard title="Delivery details" padding="lg">
            <SimpleGrid cols={{ base: 1, sm: 2 }}>
              <Select
                label="Purchase order"
                placeholder={orders.loading ? "Loading orders…" : receivable.length ? "Choose the order this delivery is for" : "No approved orders waiting"}
                data={orderOptions}
                value={orderId}
                onChange={(v) => {
                  setPosted(null);
                  setOrderId(v);
                }}
                searchable
                disabled={pending}
              />
              <TextInput label="Supplier" value={po?.supplier.name ?? ""} readOnly placeholder="From the order" />
              <TextInput label="Supplier delivery note no." placeholder="e.g. DN-55821" value={deliveryNoteRef} onChange={(e) => setDeliveryNoteRef(e.currentTarget.value)} disabled={!po || pending} />
              <TextInput label="Receive into" value={po?.location.name ?? ""} readOnly placeholder="From the order" />
              <TextInput label="Date received" value={dayjs().format("DD MMM YYYY")} readOnly description="Recorded when you post" />
              <TextInput label="Expected" value={po?.expectedDate ? dayjs(po.expectedDate).format("DD MMM YYYY") : "—"} readOnly />
            </SimpleGrid>
          </DataCard>

          <DataCard title="Items received" description={po ? `${open.length} lines outstanding on ${po.number}` : "Choose a purchase order first"}>
            <QueryState loading={order.loading && !!orderId} error={order.error} isEmpty={!po || open.length === 0} emptyMessage={po ? "Everything on this order has been received." : "Choose a purchase order to list its items."} onRetry={order.reload}>
              <Table verticalSpacing="xs" highlightOnHover>
                <Table.Thead>
                  <Table.Tr>
                    <Table.Th>Product</Table.Th>
                    <Table.Th ta="right">Outstanding</Table.Th>
                    <Table.Th w={100}>Good qty</Table.Th>
                    <Table.Th w={100}>Damaged</Table.Th>
                    <Table.Th ta="right">Unit cost</Table.Th>
                    <Table.Th ta="right">Line total</Table.Th>
                    <Table.Th w={130}>Batch</Table.Th>
                    <Table.Th w={150}>Expiry</Table.Th>
                  </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                  {open.map((l) => (
                    <Table.Tr key={l.id}>
                      <Table.Td fw={600}>{l.variant.displayName}</Table.Td>
                      <Table.Td ta="right">{l.outstanding}</Table.Td>
                      <Table.Td>
                        <NumberInput min={0} max={l.outstanding} value={rows[l.id]?.received ?? 0} onChange={(v) => set(l.id, { received: v })} aria-label={`Good units ${l.variant.displayName}`} disabled={pending} />
                      </Table.Td>
                      <Table.Td>
                        <NumberInput min={0} max={l.outstanding} value={rows[l.id]?.damaged ?? 0} onChange={(v) => set(l.id, { damaged: v })} aria-label={`Damaged units ${l.variant.displayName}`} disabled={pending} />
                      </Table.Td>
                      <Table.Td ta="right">{formatKes(l.unitCostCents)}</Table.Td>
                      <Table.Td ta="right" fw={600}>
                        {formatKes(lineCost(l))}
                      </Table.Td>
                      <Table.Td>
                        <TextInput placeholder="Optional" value={rows[l.id]?.batchNumber ?? ""} onChange={(e) => set(l.id, { batchNumber: e.currentTarget.value })} disabled={pending} />
                      </Table.Td>
                      <Table.Td>
                        <DateInput placeholder="Optional" clearable valueFormat="DD MMM YYYY" value={rows[l.id]?.expiryDate ?? null} onChange={(v) => set(l.id, { expiryDate: v })} disabled={pending} />
                      </Table.Td>
                    </Table.Tr>
                  ))}
                </Table.Tbody>
              </Table>
            </QueryState>
          </DataCard>

          {po && open.length > 0 && <Textarea label="Note" placeholder="Optional, e.g. delivered by…" autosize value={note} onChange={(e) => setNote(e.currentTarget.value)} disabled={pending} />}
        </Stack>
        </Grid.Col>

        <Grid.Col span={{ base: 12, lg: 4 }}>
        <Paper withBorder radius="lg" p="lg" bg="white" style={{ position: "sticky", top: 16 }}>
          <Stack gap="sm">
            <Text fw={800} fz="lg" ff={fonts.display}>
              GRN summary
            </Text>
            <Summary label="Products" value={open.filter((l) => num(rows[l.id]?.received) > 0).length} />
            <Summary label="Good units" value={totalUnits} />
            {totalDamaged > 0 && <Summary label="Damaged (not stocked)" value={totalDamaged} />}
            <Summary label="Payment terms" value={supplier ? (supplier.paymentTermsDays ? `Credit · ${supplier.paymentTermsDays} days` : "Cash on delivery") : "—"} />
            <Summary label="Due date" value={supplier?.paymentTermsDays ? dayjs().add(supplier.paymentTermsDays, "day").format("DD MMM YYYY") : "—"} />
            <Divider />
            <Text size="xs" c="dimmed">
              Total cost
            </Text>
            <Text className="tessera-display" fz={30} lh={1.1}>
              {formatKes(totalCost)}
            </Text>
            {overCount && (
              <Alert color="red" variant="light" icon={<IconInfoCircle size={16} />} p="xs">
                Good + damaged is more than is outstanding on a line.
              </Alert>
            )}
            {totalDamaged > 0 && (
              <Alert color="yellow" variant="light" icon={<IconInfoCircle size={16} />} p="xs">
                Damaged units are recorded against the order but not stocked. Claim a credit or raise a return.
              </Alert>
            )}
            <Button size="md" fullWidth loading={pending} disabled={!po || !open.length || nothing || overCount || !!posted} onClick={() => void mutate()}>
              Post to stock
            </Button>
            <Text size="xs" c="dimmed">
              Posting adds the good units to stock and the stock ledger at once. A mistake is corrected with a return or an adjustment, never by editing the GRN.
            </Text>
          </Stack>
        </Paper>
        </Grid.Col>
      </Grid>
    </WorkspacePage>
  );
}

function Summary({ label, value }: { label: string; value: ReactNode }) {
  return (
    <Group justify="space-between" gap="xs">
      <Text size="sm" c="dimmed">
        {label}
      </Text>
      <Text size="sm" fw={600}>
        {value}
      </Text>
    </Group>
  );
}
