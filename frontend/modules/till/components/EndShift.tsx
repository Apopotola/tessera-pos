"use client";

import { Alert, Button, Divider, Group, Modal, NumberInput, SimpleGrid, Stack, Table, Text, Textarea } from "@mantine/core";
import { notifications } from "@mantine/notifications";
import dayjs from "dayjs";
import { useCallback, useEffect, useState } from "react";
import { createPortal } from "react-dom";
import { ApiError, salesApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import QueryState from "@/components/shared/QueryState";
import { printTillSummary } from "@/modules/sales/printer";
import receiptClasses from "@/modules/sales/components/Receipt.module.css";
import { type Approval, type ApprovalAction, type CashCheck, TENDER_LABELS, type TillSummary } from "@/types/sales";
import type { Shift, TillContext } from "@/types/till";
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
 * Count by denomination: the cashier counts each note and coin; the total is added up here
 * and again on the server. With blind cash-up (Settings → Staff, the default) the expected
 * amount is only shown after submitting; otherwise it is shown while counting.
 */
export function EndShiftModal({ shift, blind, onClose, onClosed }: { shift: Shift; blind: boolean; onClose: () => void; onClosed: (shift: Shift) => void }) {
  const [pieces, setPieces] = useState<Record<number, number | string>>({});
  const fetchCurrent = useCallback(() => (blind ? Promise.resolve(null) : salesApi.currentShift()), [blind]);
  const expected = useApiQuery(fetchCurrent).data?.expectedCashCents ?? null;
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
          Count every note and coin in the drawer, including the float. Cash already dropped to the safe or paid out for expenses is not in the drawer.
          {blind && " The expected amount is shown after you submit."}
        </Text>
        {expected !== null && (
          <Alert color="tessera" variant="light">
            The drawer should hold <b>{formatKes(expected)}</b>
            {counted && total !== expected && ` · counted ${total > expected ? "over" : "short"} by ${formatKes(Math.abs(total - expected))}`}.
          </Alert>
        )}
        <CountTable pieces={pieces} onChange={setPieces} />
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

type Pieces = Record<number, number | string>;

/** Notes and coins as {"100000": 3, …} (cents => pieces), only those counted. */
function denominationsOf(pieces: Pieces): Record<string, number> {
  return Object.fromEntries(DENOMINATIONS.filter((d) => Number(pieces[d.cents]) > 0).map((d) => [String(d.cents), Number(pieces[d.cents])]));
}

function totalOf(pieces: Pieces): number {
  return DENOMINATIONS.reduce((sum, d) => sum + d.cents * (Number(pieces[d.cents]) || 0), 0);
}

/** Count the drawer note by note and coin by coin (closing the till and cash checks). */
function CountTable({ pieces, onChange }: { pieces: Pieces; onChange: (update: (p: Pieces) => Pieces) => void }) {
  return (
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
                onChange={(v) => onChange((p) => ({ ...p, [d.cents]: v }))}
                data-autofocus={i === 0 || undefined}
                aria-label={`Number of KES ${d.label} ${d.kind}s`}
              />
            </Table.Td>
            <Table.Td ta="right">{formatKes(d.cents * (Number(pieces[d.cents]) || 0))}</Table.Td>
          </Table.Tr>
        ))}
      </Table.Tbody>
    </Table>
  );
}

/**
 * Cash check (shared till that stays open): a manager counts the drawer while waiters keep selling.
 * Expected, counted and the difference are recorded, and the next count starts from the cash found.
 * With blind cash-up on, the expected amount shows only after submitting.
 */
export function CashCheckModal({ shift, blind, onClose, onChecked }: { shift: Shift; blind: boolean; onClose: () => void; onChecked: (check: CashCheck) => void }) {
  const [pieces, setPieces] = useState<Pieces>({});
  const [note, setNote] = useState("");
  const fetchSummary = useCallback(() => (blind ? Promise.resolve(null) : salesApi.tillSummary(shift.id)), [blind, shift.id]);
  const expected = useApiQuery(fetchSummary).data?.expectedCashCents ?? null;
  const total = totalOf(pieces);
  const counted = total > 0;

  const { mutate, pending } = useApiMutation(() => salesApi.cashCheck(shift.id, total, note.trim() || null, denominationsOf(pieces)), { onSuccess: onChecked });

  return (
    <Modal opened onClose={onClose} title="Cash check — count the drawer (the till stays open)" centered size="lg">
      <Stack>
        <Text size="sm" c="dimmed">
          Count every note and coin in the drawer, including the float. Nothing closes: waiters keep their bills and carry on selling.
          {blind && " The expected amount is shown after you submit."}
        </Text>
        {expected !== null && (
          <Alert color="tessera" variant="light">
            The drawer should hold <b>{formatKes(expected)}</b>
            {counted && total !== expected && ` · counted ${total > expected ? "over" : "short"} by ${formatKes(Math.abs(total - expected))}`}.
          </Alert>
        )}
        <CountTable pieces={pieces} onChange={setPieces} />
        <Group justify="space-between">
          <Text fw={600}>Total counted</Text>
          <Text className="tessera-display" fz={28}>
            {formatKes(total)}
          </Text>
        </Group>
        <Textarea label="Note (optional)" placeholder="e.g. Morning count before banking" autosize minRows={2} value={note} onChange={(e) => setNote(e.currentTarget.value)} />
        <Group justify="flex-end">
          <Button variant="default" onClick={onClose} disabled={pending}>
            Cancel
          </Button>
          <Button onClick={() => void mutate()} loading={pending} disabled={!counted}>
            Record cash check
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}

/** After a cash check: expected vs counted. The till stays open; another manager signs it off. */
export function CashCheckResultModal({ check, onDone }: { check: CashCheck; onDone: () => void }) {
  const variance = check.varianceCents;
  return (
    <Modal opened onClose={onDone} title="Cash check recorded" centered>
      <Stack>
        <SimpleGrid cols={3}>
          <Summary label="Expected" value={formatKes(check.expectedCashCents)} />
          <Summary label="Counted" value={formatKes(check.countedCashCents)} />
          <Summary label={variance < 0 ? "Short" : variance > 0 ? "Over" : "Variance"} value={formatKes(Math.abs(variance))} color={variance === 0 ? "green" : "red"} />
        </SimpleGrid>
        <Text size="sm" c="dimmed">
          The till stays open. The next count starts from {formatKes(check.countedCashCents)}. Another manager signs it off in Shifts &amp; cash-ups.
        </Text>
        <Button onClick={onDone} data-autofocus>
          Back to selling
        </Button>
      </Stack>
    </Modal>
  );
}

interface SummaryHeading {
  business: string;
  branch: string;
  till: string;
  printedBy: string;
}

/** Till summary for a manager, without closing: since the last cash check (or the opening). */
export function TillSummaryModal({ shift, context, printedBy, onClose, onCashCheck }: { shift: Shift; context: TillContext; printedBy: string; onClose: () => void; onCashCheck?: () => void }) {
  const fetchSummary = useCallback(() => salesApi.tillSummary(shift.id), [shift.id]);
  const { data: summary, loading, error, reload } = useApiQuery(fetchSummary);
  const [paper, setPaper] = useState(false);
  const [printPending, setPrintPending] = useState(false);
  const heading: SummaryHeading = { business: context.business.name, branch: context.branch.name, till: context.till.name, printedBy };

  // Print dialog: once the paper copy is mounted, print it and take it away.
  useEffect(() => {
    if (!paper) return;
    const timer = window.setTimeout(() => {
      window.print();
      setPaper(false);
    }, 50);
    return () => window.clearTimeout(timer);
  }, [paper]);

  const print = async () => {
    if (!summary || printPending) return;
    setPrintPending(true);
    try {
      const outcome = await printTillSummary(context.policy.printer, summary, heading, context.policy.receipt.paperSize);
      if (outcome.via === "browser" && outcome.error) notifications.show({ color: "yellow", message: `The printer did not answer (${outcome.error}). Opening the print dialog instead.` });
      if (outcome.via === "printer") notifications.show({ color: "green", message: "Till summary printed." });
      else setPaper(true);
    } finally {
      setPrintPending(false);
    }
  };

  return (
    <Modal opened onClose={onClose} title="Till summary" centered size="lg">
      <QueryState loading={loading} error={error} isEmpty={false} onRetry={reload}>
        {summary && (
          <Stack>
            <Text size="sm" c="dimmed">
              {summary.lastCheck ? "Since the last cash check" : "Since the till opened"}, {dayjs(summary.since).format("D MMM, h:mm a")}. The till stays open.
            </Text>
            <SimpleGrid cols={{ base: 2, sm: 4 }}>
              {summary.methods.map((m) => (
                <Summary key={m.method} label={TENDER_LABELS[m.method]} value={formatKes(m.amountCents)} />
              ))}
            </SimpleGrid>
            <Divider />
            <Table verticalSpacing={4}>
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Waiter</Table.Th>
                  <Table.Th ta="right">Sales</Table.Th>
                  <Table.Th ta="right">Total</Table.Th>
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {summary.waiters.length === 0 && (
                  <Table.Tr>
                    <Table.Td colSpan={3}>
                      <Text size="sm" c="dimmed">
                        No sales yet.
                      </Text>
                    </Table.Td>
                  </Table.Tr>
                )}
                {summary.waiters.map((w) => (
                  <Table.Tr key={w.userId}>
                    <Table.Td>{w.name}</Table.Td>
                    <Table.Td ta="right">{w.salesCount}</Table.Td>
                    <Table.Td ta="right">{formatKes(w.totalCents)}</Table.Td>
                  </Table.Tr>
                ))}
                <Table.Tr>
                  <Table.Td fw={700}>All waiters</Table.Td>
                  <Table.Td ta="right" fw={700}>
                    {summary.salesCount}
                  </Table.Td>
                  <Table.Td ta="right" fw={700}>
                    {formatKes(summary.salesTotalCents)}
                  </Table.Td>
                </Table.Tr>
              </Table.Tbody>
            </Table>
            <Divider />
            <SimpleGrid cols={{ base: 2, sm: 4 }}>
              <Summary label={summary.lastCheck ? "Cash at last count" : "Opening float"} value={formatKes(summary.startingCashCents)} />
              <Summary label="Dropped to safe" value={formatKes(summary.dropsCents)} />
              <Summary label="Paid out" value={formatKes(summary.payoutsCents)} />
              <Summary label="Expected in drawer" value={summary.expectedCashCents === null ? "After counting" : formatKes(summary.expectedCashCents)} />
            </SimpleGrid>
            {summary.blind && (
              <Text size="xs" c="dimmed">
                Blind cash-up is on: the expected cash shows after a cash check.
              </Text>
            )}
            <Group justify="flex-end">
              <Button variant="default" onClick={() => void print()} loading={printPending}>
                Print
              </Button>
              {onCashCheck && <Button onClick={onCashCheck}>Cash check…</Button>}
            </Group>
          </Stack>
        )}
      </QueryState>
      {paper && summary && <TillSummaryPaper summary={summary} heading={heading} />}
    </Modal>
  );
}

/** The summary on the roll through the print dialog (same content as the ESC/POS copy). */
function TillSummaryPaper({ summary, heading }: { summary: TillSummary; heading: SummaryHeading }) {
  const row = (left: string, right: string, bold = false) => (
    <div style={{ display: "flex", justifyContent: "space-between", fontWeight: bold ? 700 : undefined }}>
      <span>{left}</span>
      <span>{right}</span>
    </div>
  );
  return createPortal(
    <div className="tessera-print">
      <div className={receiptClasses.receipt}>
        <div style={{ textAlign: "center" }}>
          <div style={{ fontWeight: 700, fontSize: 14 }}>{heading.business}</div>
          <div>
            {heading.branch} · {heading.till}
          </div>
          <div style={{ fontWeight: 700 }}>TILL SUMMARY</div>
          <div>
            {dayjs(summary.since).format("DD/MM HH:mm")} – {dayjs().format("DD/MM HH:mm")}
          </div>
          <div>{summary.lastCheck ? "Since the last cash check" : "Since the till opened"}</div>
        </div>
        <hr />
        <div style={{ fontWeight: 700 }}>BY WAITER</div>
        {summary.waiters.length === 0 && <div>No sales yet</div>}
        {summary.waiters.map((w) => (
          <div key={w.userId}>{row(`${w.name} (${w.salesCount})`, formatKes(w.totalCents))}</div>
        ))}
        {row(`TOTAL (${summary.salesCount} sales)`, formatKes(summary.salesTotalCents), true)}
        <hr />
        <div style={{ fontWeight: 700 }}>BY PAYMENT</div>
        {summary.methods.map((m) => (
          <div key={m.method}>{row(TENDER_LABELS[m.method], formatKes(m.amountCents))}</div>
        ))}
        <hr />
        {row(summary.lastCheck ? "Cash at last count" : "Opening float", formatKes(summary.startingCashCents))}
        {row("Drops to the safe", formatKes(-summary.dropsCents))}
        {row("Paid out (expenses)", formatKes(-summary.payoutsCents))}
        {row("EXPECTED IN DRAWER", summary.expectedCashCents === null ? "(count first)" : formatKes(summary.expectedCashCents), true)}
        <hr />
        <div style={{ textAlign: "center" }}>
          Printed by {heading.printedBy} · {dayjs().format("DD/MM/YYYY HH:mm")}
        </div>
      </div>
    </div>,
    document.body,
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
