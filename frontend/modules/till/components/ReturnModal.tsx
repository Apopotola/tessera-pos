"use client";

import { Alert, Button, Checkbox, Group, Modal, NumberInput, Stack, Table, Text, Textarea, TextInput } from "@mantine/core";
import { IconSearch } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useState } from "react";
import { ApiError, salesApi } from "@/api";
import type { Approval, ApprovalAction, Sale } from "@/types/sales";
import { formatKes } from "@/utils/money";

interface ReturnModalProps {
  returnWindowDays: number;
  requestApproval: (action: ApprovalAction, detail?: string | null) => Promise<Approval | null>;
  onClose: () => void;
  /** Receives the updated sale; `refundCents` is the cash to hand back. */
  onReturned: (sale: Sale, refundCents: number) => void;
  onReprint: (sale: Sale) => void;
}

interface Row {
  quantity: number | string;
  restock: boolean;
}

/**
 * Customer return by receipt number: choose items, say whether each is sealed (back on the
 * shelf) or not (quarantine), give a reason, and a manager approves the cash refund.
 */
export default function ReturnModal({ returnWindowDays, requestApproval, onClose, onReturned, onReprint }: ReturnModalProps) {
  const [number, setNumber] = useState("");
  const [sale, setSale] = useState<Sale | null>(null);
  const [rows, setRows] = useState<Record<number, Row>>({});
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  const find = async () => {
    if (!number.trim()) return;
    setPending(true);
    setError(null);
    try {
      const found = await salesApi.findSale(number.trim());
      setSale(found);
      setRows(Object.fromEntries(found.lines.map((l) => [l.id, { quantity: 0, restock: true }])));
    } catch (e) {
      setSale(null);
      setError(e instanceof Error ? e.message : "Sale not found.");
    } finally {
      setPending(false);
    }
  };

  const selected = (sale?.lines ?? [])
    .map((line) => ({ line, qty: Math.floor(Number(rows[line.id]?.quantity) || 0), restock: rows[line.id]?.restock ?? true }))
    .filter((r) => r.qty > 0);
  // Mirrors SaleReturnService: refund the proportion of what was actually paid for the line.
  const refundCents = selected.reduce((sum, r) => sum + Math.round((r.line.lineTotalCents * r.qty) / r.line.quantity), 0);
  const expired = sale ? dayjs().diff(dayjs(sale.completedAt), "day", true) > returnWindowDays : false;

  const submit = async () => {
    if (!sale || selected.length === 0 || !reason.trim()) return;
    const approval = await requestApproval("refund", `Refund ${formatKes(refundCents)} in cash.`);
    if (!approval) return;
    setPending(true);
    setError(null);
    try {
      const updated = await salesApi.returnItems({
        saleId: sale.id,
        reason: reason.trim(),
        approvalToken: approval.token,
        lines: selected.map((r) => ({ saleLineId: r.line.id, quantity: r.qty, restock: r.restock })),
      });
      onReturned(updated, updated.returns.at(-1)?.totalCents ?? refundCents);
    } catch (e) {
      setError(e instanceof ApiError ? (Object.values(e.formErrors)[0] ?? e.message) : "Return failed.");
    } finally {
      setPending(false);
    }
  };

  return (
    <Modal opened onClose={onClose} title="Return or reprint" centered size="xl">
      <Stack>
        <Group align="flex-end">
          <TextInput
            label="Receipt number"
            placeholder="MAIN-S-000123"
            value={number}
            onChange={(e) => setNumber(e.currentTarget.value.toUpperCase())}
            onKeyDown={(e) => e.key === "Enter" && void find()}
            style={{ flex: 1 }}
            ff="monospace"
            data-autofocus
          />
          <Button leftSection={<IconSearch size={16} />} onClick={() => void find()} loading={pending && !sale}>
            Find
          </Button>
        </Group>

        {sale && (
          <>
            <Group justify="space-between">
              <Text size="sm">
                {sale.number} · {dayjs(sale.completedAt).format("D MMM YYYY, h:mm a")} · {formatKes(sale.totalCents)}
              </Text>
              <Button variant="light" size="xs" onClick={() => onReprint(sale)}>
                Reprint receipt
              </Button>
            </Group>
            {expired && <Alert color="yellow">This sale is older than {returnWindowDays} days; returns are closed.</Alert>}
            <Table verticalSpacing="xs">
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Item</Table.Th>
                  <Table.Th ta="right">Paid</Table.Th>
                  <Table.Th ta="center">Returnable</Table.Th>
                  <Table.Th w={110}>Return qty</Table.Th>
                  <Table.Th>Sealed</Table.Th>
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {sale.lines.map((line) => {
                  // Poured tots cannot come back.
                  const left = line.unit === "tot" ? 0 : line.quantity - line.returnedQuantity;
                  return (
                    <Table.Tr key={line.id}>
                      <Table.Td>
                        {line.variant.displayName}
                        {line.unit === "tot" && ` — tot ${line.totMl}ml`}
                      </Table.Td>
                      <Table.Td ta="right">{formatKes(line.lineTotalCents)}</Table.Td>
                      <Table.Td ta="center">{left}</Table.Td>
                      <Table.Td>
                        <NumberInput
                          size="xs"
                          min={0}
                          max={left}
                          allowDecimal={false}
                          disabled={left === 0 || expired}
                          value={rows[line.id]?.quantity ?? 0}
                          onChange={(v) => setRows((r) => ({ ...r, [line.id]: { ...r[line.id], quantity: v } }))}
                        />
                      </Table.Td>
                      <Table.Td>
                        <Checkbox
                          aria-label="Sealed, back on the shelf"
                          disabled={left === 0 || expired}
                          checked={rows[line.id]?.restock ?? true}
                          onChange={(e) => {
                            const restock = e.currentTarget.checked;
                            setRows((r) => ({ ...r, [line.id]: { ...r[line.id], restock } }));
                          }}
                        />
                      </Table.Td>
                    </Table.Tr>
                  );
                })}
              </Table.Tbody>
            </Table>
            <Text size="xs" c="dimmed">
              Sealed bottles go back on the shop floor. Unticked items (opened, broken, faulty) go to quarantine for a manager to review.
            </Text>
            <Textarea label="Reason" required autosize minRows={2} value={reason} onChange={(e) => setReason(e.currentTarget.value)} />
          </>
        )}

        {error && <Alert color="red">{error}</Alert>}

        <Group justify="space-between">
          <Text fw={700}>{selected.length > 0 && `Refund ${formatKes(refundCents)} in cash`}</Text>
          <Group>
            <Button variant="default" onClick={onClose} disabled={pending}>
              Close
            </Button>
            <Button color="red" onClick={() => void submit()} loading={pending && Boolean(sale)} disabled={!sale || selected.length === 0 || !reason.trim() || expired}>
              Refund
            </Button>
          </Group>
        </Group>
      </Stack>
    </Modal>
  );
}
