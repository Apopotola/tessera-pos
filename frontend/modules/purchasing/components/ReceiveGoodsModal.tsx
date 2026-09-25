"use client";

import { Alert, Button, Group, Modal, NumberInput, Stack, Table, Text, TextInput, Textarea } from "@mantine/core";
import { DateInput } from "@mantine/dates";
import { IconInfoCircle } from "@tabler/icons-react";
import { useState } from "react";
import { purchasingApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import type { PurchaseOrder } from "@/types/purchasing";

interface Row {
  received: number | string;
  damaged: number | string;
  batchNumber: string;
  expiryDate: string | null;
}

/** Goods received note: count good units and damaged-on-arrival separately. */
export default function ReceiveGoodsModal({ order, onClose, onDone }: { order: PurchaseOrder; onClose: () => void; onDone: () => void }) {
  const open = order.lines.filter((l) => l.outstanding > 0);
  const [rows, setRows] = useState<Record<number, Row>>(() =>
    Object.fromEntries(open.map((l) => [l.id, { received: l.outstanding, damaged: 0, batchNumber: "", expiryDate: null }])),
  );
  const [deliveryNoteRef, setDeliveryNoteRef] = useState("");
  const [note, setNote] = useState("");
  const set = (id: number, patch: Partial<Row>) => setRows((r) => ({ ...r, [id]: { ...r[id], ...patch } }));

  const { mutate, pending } = useApiMutation(
    () =>
      purchasingApi.receive(
        order.id,
        open.map((l) => ({
          lineId: l.id,
          received: Number(rows[l.id].received) || 0,
          damaged: Number(rows[l.id].damaged) || 0,
          batchNumber: rows[l.id].batchNumber.trim() || null,
          expiryDate: rows[l.id].expiryDate,
        })),
        deliveryNoteRef.trim() || null,
        note.trim() || null,
      ),
    { successMessage: "Goods received — stock updated.", onSuccess: onDone },
  );

  const anyDamaged = open.some((l) => Number(rows[l.id]?.damaged) > 0);

  return (
    <Modal opened onClose={onClose} title={`Receive goods — ${order.number}`} size="xl">
      <Stack>
        <Text size="sm" c="dimmed">
          Count what arrived from {order.supplier.name}. Good units go into {order.location.name} immediately at the order price.
        </Text>
        <TextInput label="Supplier delivery note number" placeholder="Optional" value={deliveryNoteRef} onChange={(e) => setDeliveryNoteRef(e.currentTarget.value)} maw={320} />
        <Table verticalSpacing="xs">
          <Table.Thead>
            <Table.Tr>
              <Table.Th>Item</Table.Th>
              <Table.Th ta="right">Expected</Table.Th>
              <Table.Th w={110}>Good</Table.Th>
              <Table.Th w={110}>Damaged</Table.Th>
              <Table.Th w={140}>Batch</Table.Th>
              <Table.Th w={160}>Expiry</Table.Th>
            </Table.Tr>
          </Table.Thead>
          <Table.Tbody>
            {open.map((l) => (
              <Table.Tr key={l.id}>
                <Table.Td>{l.variant.displayName}</Table.Td>
                <Table.Td ta="right">{l.outstanding}</Table.Td>
                <Table.Td>
                  <NumberInput min={0} max={l.outstanding} value={rows[l.id].received} onChange={(v) => set(l.id, { received: v })} aria-label={`Good units ${l.variant.displayName}`} />
                </Table.Td>
                <Table.Td>
                  <NumberInput min={0} max={l.outstanding} value={rows[l.id].damaged} onChange={(v) => set(l.id, { damaged: v })} aria-label={`Damaged units ${l.variant.displayName}`} />
                </Table.Td>
                <Table.Td>
                  <TextInput placeholder="Optional" value={rows[l.id].batchNumber} onChange={(e) => set(l.id, { batchNumber: e.currentTarget.value })} />
                </Table.Td>
                <Table.Td>
                  <DateInput placeholder="Optional" clearable valueFormat="DD MMM YYYY" value={rows[l.id].expiryDate} onChange={(v) => set(l.id, { expiryDate: v })} />
                </Table.Td>
              </Table.Tr>
            ))}
          </Table.Tbody>
        </Table>
        {anyDamaged && (
          <Alert color="yellow" variant="light" icon={<IconInfoCircle size={18} />}>
            Damaged units are recorded against the order but do not go into stock. Claim a credit from the supplier or raise a return.
          </Alert>
        )}
        <Textarea label="Note" placeholder="Optional" autosize value={note} onChange={(e) => setNote(e.currentTarget.value)} />
        <Group justify="flex-end">
          <Button variant="default" onClick={onClose} disabled={pending}>
            Cancel
          </Button>
          <Button loading={pending} onClick={() => void mutate()}>
            Confirm goods received
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}
