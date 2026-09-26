"use client";

import { Alert, Button, Group, Modal, NumberInput, SimpleGrid, Stack, Table, Text, Textarea } from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { useState } from "react";
import { ApiError, salesApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import type { Approval, ApprovalAction } from "@/types/sales";
import type { Shift } from "@/types/till";
import { formatKes, kesToCents } from "@/utils/money";

/** KES notes and coins, in cents (keep in sync with Modules\Sales\Support\Denominations). */
const DENOMINATIONS = [
  { cents: 100000, label: "1,000", kind: "note" },
  { cents: 50000, label: "500", kind: "note" },
  { cents: 20000, label: "200", kind: "note" },
  { cents: 10000, label: "100", kind: "note" },
  { cents: 5000, label: "50", kind: "note" },
  { cents: 2000, label: "20", kind: "coin" },
  { cents: 1000, label: "10", kind: "coin" },
  { cents: 500, label: "5", kind: "coin" },
  { cents: 100, label: "1", kind: "coin" },
] as const;

/**
 * Blind count by denomination: the cashier counts each note and coin; the total is added
 * up here and again on the server. The expected amount is only shown after submitting.
 */
export function EndShiftModal({ shift, onClose, onClosed }: { shift: Shift; onClose: () => void; onClosed: (shift: Shift) => void }) {
  const [pieces, setPieces] = useState<Record<number, number | string>>({});
  const [note, setNote] = useState("");

  const total = DENOMINATIONS.reduce((sum, d) => sum + d.cents * (Number(pieces[d.cents]) || 0), 0);
  const counted = DENOMINATIONS.some((d) => Number(pieces[d.cents]) > 0);

  const { mutate, pending } = useApiMutation(
    () => {
      const denominations = Object.fromEntries(DENOMINATIONS.filter((d) => Number(pieces[d.cents]) > 0).map((d) => [String(d.cents), Number(pieces[d.cents])]));
      return salesApi.closeShift(shift.id, total, note.trim() || null, denominations);
    },
    { onSuccess: onClosed },
  );

  return (
    <Modal opened onClose={onClose} title="End shift — count the cash drawer" centered size="lg">
      <Stack>
        <Text size="sm" c="dimmed">
          Count every note and coin in the drawer, including the float. Cash already dropped to the safe is not in the drawer. The expected amount is shown after you submit.
        </Text>
        <Table verticalSpacing={4}>
          <Table.Thead>
            <Table.Tr>
              <Table.Th>Note / coin</Table.Th>
              <Table.Th w={130}>How many</Table.Th>
              <Table.Th ta="right">Value</Table.Th>
            </Table.Tr>
          </Table.Thead>
          <Table.Tbody>
            {DENOMINATIONS.map((d, i) => (
              <Table.Tr key={d.cents}>
                <Table.Td>
                  <Text fw={600}>KES {d.label}</Text>
                  <Text size="xs" c="dimmed">
                    {d.kind}
                  </Text>
                </Table.Td>
                <Table.Td>
                  <NumberInput
                    min={0}
                    max={100000}
                    allowDecimal={false}
                    allowNegative={false}
                    hideControls
                    value={pieces[d.cents] ?? ""}
                    onChange={(v) => setPieces((p) => ({ ...p, [d.cents]: v }))}
                    data-autofocus={i === 0 || undefined}
                    aria-label={`Number of KES ${d.label} ${d.kind}s`}
                  />
                </Table.Td>
                <Table.Td ta="right">{formatKes(d.cents * (Number(pieces[d.cents]) || 0))}</Table.Td>
              </Table.Tr>
            ))}
          </Table.Tbody>
        </Table>
        <Group justify="space-between">
          <Text fw={600}>Total counted</Text>
          <Text className="tessera-display" fz={28}>
            {formatKes(total)}
          </Text>
        </Group>
        <Textarea label="Note (optional)" autosize minRows={2} value={note} onChange={(e) => setNote(e.currentTarget.value)} />
        <Group justify="flex-end">
          <Button variant="default" onClick={onClose} disabled={pending}>
            Cancel
          </Button>
          <Button onClick={() => void mutate()} loading={pending} disabled={!counted}>
            Submit count
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}

/** Shown once the count is in: expected vs counted, and a reason when they differ. */
export function ShiftSummaryModal({ shift, onDone }: { shift: Shift; onDone: () => void }) {
  const variance = shift.varianceCents ?? 0;
  const [reason, setReason] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);

  const finish = async () => {
    if (variance === 0) {
      onDone();
      return;
    }
    if (reason.trim().length < 3) {
      setError("Say briefly why the cash is over or short. A manager reviews it.");
      return;
    }
    setPending(true);
    try {
      await salesApi.varianceReason(shift.id, reason.trim());
      onDone();
    } catch (e) {
      setError(e instanceof ApiError ? e.message : "Could not save the reason.");
      setPending(false);
    }
  };

  return (
    <Modal opened onClose={() => undefined} title="Shift ended" centered withCloseButton={false}>
      <Stack>
        <SimpleGrid cols={3}>
          <Summary label="Expected" value={formatKes(shift.expectedCashCents)} />
          <Summary label="Counted" value={formatKes(shift.countedCashCents)} />
          <Summary label={variance < 0 ? "Short" : variance > 0 ? "Over" : "Variance"} value={formatKes(Math.abs(variance))} color={variance === 0 ? "green" : "red"} />
        </SimpleGrid>
        {shift.dropsCents > 0 && (
          <Text size="sm" c="dimmed">
            {formatKes(shift.dropsCents)} was dropped to the safe during the shift.
          </Text>
        )}
        {variance !== 0 && (
          <Textarea
            label="Why is the cash over or short?"
            placeholder="e.g. Gave KES 100 too much change to a customer"
            required
            autosize
            minRows={2}
            value={reason}
            onChange={(e) => setReason(e.currentTarget.value)}
            error={error}
            data-autofocus
          />
        )}
        <Text size="sm" c="dimmed">
          A manager signs off every cash-up. You are then signed out.
        </Text>
        <Button onClick={() => void finish()} loading={pending}>
          Done
        </Button>
      </Stack>
    </Modal>
  );
}

/** Excess cash to the safe, witnessed by a manager's PIN. */
export function CashDropModal({
  shift,
  requestApproval,
  onClose,
  onDropped,
}: {
  shift: Shift;
  requestApproval: (action: ApprovalAction, detail?: string | null) => Promise<Approval | null>;
  onClose: () => void;
  onDropped: (dropsCents: number) => void;
}) {
  const [amount, setAmount] = useState<number | string>("");
  const [note, setNote] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const cents = kesToCents(Number(amount) || 0);

  const submit = async () => {
    if (cents < 100) return;
    const approval = await requestApproval("cash_drop", `${formatKes(cents)} to the safe.`);
    if (!approval) return;
    setPending(true);
    setError(null);
    try {
      const result = await salesApi.cashDrop(shift.id, cents, note.trim() || null, approval.token);
      notifications.show({ color: "green", message: `${formatKes(cents)} dropped to the safe, witnessed by ${approval.approver.name}.` });
      onDropped(result.dropsCents);
    } catch (e) {
      setError(e instanceof ApiError ? (Object.values(e.formErrors)[0] ?? e.message) : "Could not record the drop.");
    } finally {
      setPending(false);
    }
  };

  return (
    <Modal opened onClose={onClose} title="Cash drop to the safe" centered>
      <Stack>
        <Text size="sm" c="dimmed">
          Take excess notes out of the drawer in front of a manager. They confirm with their PIN.
        </Text>
        <NumberInput label="Amount (KES)" size="lg" min={0} decimalScale={2} thousandSeparator="," value={amount} onChange={setAmount} data-autofocus />
        <Textarea label="Note (optional)" placeholder="e.g. Envelope 3" autosize value={note} onChange={(e) => setNote(e.currentTarget.value)} />
        {error && <Alert color="red">{error}</Alert>}
        <Group justify="flex-end">
          <Button variant="default" onClick={onClose} disabled={pending}>
            Cancel
          </Button>
          <Button onClick={() => void submit()} loading={pending} disabled={cents < 100}>
            Record drop
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}

function Summary({ label, value, color }: { label: string; value: string; color?: string }) {
  return (
    <div>
      <Text size="xs" c="dimmed">
        {label}
      </Text>
      <Text fw={700} c={color}>
        {value}
      </Text>
    </div>
  );
}
