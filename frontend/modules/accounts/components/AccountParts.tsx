"use client";

import { Badge, Button, Group, Modal, NumberInput, Select, SimpleGrid, Stack, Table, Text, TextInput, Textarea } from "@mantine/core";
import { isNotEmpty, useForm } from "@mantine/form";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import DataCard from "@/components/shared/DataCard";
import NoteModal from "@/components/shared/NoteModal";
import QueryState from "@/components/shared/QueryState";
import StatTile from "@/components/shared/StatTile";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { AGING_LABELS, type AccountPayment, type AgingBucket, type Statement } from "@/types/accounts";
import { formatKes, optionalKesToCents } from "@/utils/money";

const BUCKETS = Object.keys(AGING_LABELS) as AgingBucket[];

/** The balance split by age (oldest amounts settled first by payments). */
export function AgingTiles({ aging }: { aging: Record<AgingBucket, number> }) {
  return (
    <SimpleGrid cols={{ base: 2, md: 4 }}>
      {BUCKETS.map((b) => (
        <StatTile key={b} label={AGING_LABELS[b]} value={formatKes(aging[b])} highlight={(b === "61_90" || b === "over_90") && aging[b] > 0} />
      ))}
    </SimpleGrid>
  );
}

/** Aged balances per customer or supplier, with totals. */
export function AgingTable<T extends { id: number; name: string; balanceCents: number; aging: Record<AgingBucket, number> }>({
  rows,
  totals,
  pastDue,
  pastDueLabel,
  extra,
  onOpen,
}: {
  rows: T[];
  totals: { balanceCents: number; aging: Record<AgingBucket, number> };
  pastDue: (row: T) => number;
  pastDueLabel: string;
  /** Extra column (e.g. credit limit), rendered after the name. */
  extra?: { label: string; render: (row: T) => React.ReactNode };
  onOpen: (row: T) => void;
}) {
  return (
    <Table verticalSpacing="xs" fz="sm" highlightOnHover>
      <Table.Thead>
        <Table.Tr>
          <Table.Th>Name</Table.Th>
          {extra && <Table.Th ta="right">{extra.label}</Table.Th>}
          {BUCKETS.map((b) => (
            <Table.Th key={b} ta="right">
              {AGING_LABELS[b]}
            </Table.Th>
          ))}
          <Table.Th ta="right">Balance</Table.Th>
          <Table.Th ta="right">{pastDueLabel}</Table.Th>
        </Table.Tr>
      </Table.Thead>
      <Table.Tbody>
        {rows.map((row) => (
          <Table.Tr key={row.id} style={{ cursor: "pointer" }} onClick={() => onOpen(row)}>
            <Table.Td fw={600}>{row.name}</Table.Td>
            {extra && <Table.Td ta="right">{extra.render(row)}</Table.Td>}
            {BUCKETS.map((b) => (
              <Table.Td key={b} ta="right" c={row.aging[b] === 0 ? "dimmed" : b === "over_90" ? "red.7" : undefined}>
                {row.aging[b] === 0 ? "—" : formatKes(row.aging[b])}
              </Table.Td>
            ))}
            <Table.Td ta="right" fw={700}>
              {formatKes(row.balanceCents)}
            </Table.Td>
            <Table.Td ta="right" c={pastDue(row) > 0 ? "red.7" : "dimmed"}>
              {pastDue(row) > 0 ? formatKes(pastDue(row)) : "—"}
            </Table.Td>
          </Table.Tr>
        ))}
      </Table.Tbody>
      <Table.Tfoot>
        <Table.Tr style={{ fontWeight: 700 }}>
          <Table.Td>Total</Table.Td>
          {extra && <Table.Td />}
          {BUCKETS.map((b) => (
            <Table.Td key={b} ta="right">
              {formatKes(totals.aging[b])}
            </Table.Td>
          ))}
          <Table.Td ta="right">{formatKes(totals.balanceCents)}</Table.Td>
          <Table.Td ta="right">{formatKes(rows.reduce((sum, r) => sum + pastDue(r), 0))}</Table.Td>
        </Table.Tr>
      </Table.Tfoot>
    </Table>
  );
}

/** Statement for a period: opening balance, every document with a running balance, closing balance. */
export function StatementCard({ load, subject }: { load: (from: string, to: string) => Promise<Statement>; subject: string }) {
  const [from, setFrom] = useState(dayjs().subtract(3, "month").startOf("month").format("YYYY-MM-DD"));
  const [to, setTo] = useState(dayjs().format("YYYY-MM-DD"));
  const fetchStatement = useCallback(() => load(from, to), [load, from, to]);
  const { data, loading, error, reload } = useApiQuery(fetchStatement);

  return (
    <DataCard
      title="Statement"
      description={`Everything that changed what ${subject}. Positive amounts add to the balance.`}
      actions={
        <Group gap="xs">
          <TextInput type="date" size="xs" aria-label="From" value={from} max={to} onChange={(e) => e.currentTarget.value && setFrom(e.currentTarget.value)} />
          <TextInput type="date" size="xs" aria-label="To" value={to} min={from} onChange={(e) => e.currentTarget.value && setTo(e.currentTarget.value)} />
        </Group>
      }
    >
      <QueryState loading={loading && !data} error={error} isEmpty={!data} onRetry={reload}>
        {data && (
          <Table verticalSpacing="xs" fz="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Date</Table.Th>
                <Table.Th>Reference</Table.Th>
                <Table.Th>Details</Table.Th>
                <Table.Th ta="right">Amount</Table.Th>
                <Table.Th ta="right">Balance</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              <Table.Tr>
                <Table.Td>{dayjs(data.from).format("DD MMM YYYY")}</Table.Td>
                <Table.Td />
                <Table.Td c="dimmed">Opening balance</Table.Td>
                <Table.Td />
                <Table.Td ta="right" fw={600}>
                  {formatKes(data.openingCents)}
                </Table.Td>
              </Table.Tr>
              {data.lines.map((line, i) => (
                <Table.Tr key={`${line.reference}-${i}`}>
                  <Table.Td>{dayjs(line.date).format("DD MMM YYYY")}</Table.Td>
                  <Table.Td ff="monospace">{line.reference}</Table.Td>
                  <Table.Td>
                    {line.description}
                    {line.dueDate && (
                      <Text span size="xs" c="dimmed">
                        {" "}
                        · due {dayjs(line.dueDate).format("DD MMM")}
                      </Text>
                    )}
                  </Table.Td>
                  <Table.Td ta="right" c={line.amountCents < 0 ? "green.8" : undefined}>
                    {formatKes(line.amountCents)}
                  </Table.Td>
                  <Table.Td ta="right">{formatKes(line.balanceCents)}</Table.Td>
                </Table.Tr>
              ))}
              <Table.Tr style={{ fontWeight: 700 }}>
                <Table.Td>{dayjs(data.to).format("DD MMM YYYY")}</Table.Td>
                <Table.Td />
                <Table.Td>Closing balance</Table.Td>
                <Table.Td />
                <Table.Td ta="right">{formatKes(data.closingCents)}</Table.Td>
              </Table.Tr>
            </Table.Tbody>
          </Table>
        )}
      </QueryState>
    </DataCard>
  );
}

/** Payments recorded, newest first; a mistake is reversed (a new row), never edited. */
export function PaymentsCard({
  load,
  reverse,
  canReverse,
  methods,
  refreshKey,
  onChanged,
}: {
  load: () => Promise<AccountPayment[]>;
  reverse: (paymentId: number, reason: string) => Promise<unknown>;
  canReverse: boolean;
  methods: { value: string; label: string }[];
  /** Changes when a payment is added elsewhere, to reload the list. */
  refreshKey: number;
  onChanged: () => void;
}) {
  const fetchPayments = useCallback(() => load(), [load, refreshKey]); // eslint-disable-line react-hooks/exhaustive-deps -- refreshKey reloads the list
  const { data, loading, error, reload } = useApiQuery(fetchPayments);
  const [reversing, setReversing] = useState<AccountPayment | null>(null);
  const label = (m: string) => methods.find((x) => x.value === m)?.label ?? m;

  return (
    <DataCard title="Payments">
      <QueryState loading={loading && !data} error={error} isEmpty={!data?.length} emptyMessage="No payments recorded yet." onRetry={reload}>
        <Table verticalSpacing="xs" fz="sm">
          <Table.Thead>
            <Table.Tr>
              <Table.Th>Number</Table.Th>
              <Table.Th>Date</Table.Th>
              <Table.Th>Method</Table.Th>
              <Table.Th ta="right">Amount</Table.Th>
              <Table.Th>Recorded by</Table.Th>
              <Table.Th />
            </Table.Tr>
          </Table.Thead>
          <Table.Tbody>
            {data?.map((p) => (
              <Table.Tr key={p.id} opacity={p.reversed ? 0.55 : 1}>
                <Table.Td ff="monospace">{p.number}</Table.Td>
                <Table.Td>{dayjs(p.receivedAt ?? p.paidOn).format("DD MMM YYYY")}</Table.Td>
                <Table.Td>
                  {p.isReversal ? (
                    <Badge color="red" variant="light">
                      Reversal of {p.reference}
                    </Badge>
                  ) : (
                    <>
                      {label(p.method)}
                      {p.reference && (
                        <Text span size="xs" c="dimmed" ff="monospace">
                          {" "}
                          {p.reference}
                        </Text>
                      )}
                    </>
                  )}
                </Table.Td>
                <Table.Td ta="right">{formatKes(p.amountCents)}</Table.Td>
                <Table.Td>{p.recordedBy ?? "—"}</Table.Td>
                <Table.Td ta="right">
                  {p.reversed ? (
                    <Badge color="gray" variant="light">
                      Reversed
                    </Badge>
                  ) : (
                    canReverse &&
                    !p.isReversal && (
                      <Button size="xs" variant="subtle" color="red" onClick={() => setReversing(p)}>
                        Reverse
                      </Button>
                    )
                  )}
                </Table.Td>
              </Table.Tr>
            ))}
          </Table.Tbody>
        </Table>
      </QueryState>
      {reversing && (
        <NoteModal
          title={`Reverse payment ${reversing.number}?`}
          description={`${formatKes(reversing.amountCents)} goes back on the balance. The payment stays on record with a reversal next to it.`}
          label="Reason"
          confirmLabel="Reverse payment"
          danger
          successMessage="Payment reversed."
          onSubmit={(reason) => reverse(reversing.id, reason)}
          onClose={() => setReversing(null)}
          onDone={() => {
            setReversing(null);
            reload();
            onChanged();
          }}
        />
      )}
    </DataCard>
  );
}

interface PaymentForm {
  amountKes: number | string;
  method: string;
  reference: string;
  branchId: string | null;
  date: string;
  note: string;
}

/** Record a payment: received from a customer (at a branch) or paid to a supplier (on a date). */
export function RecordPaymentModal({
  title,
  balanceCents,
  methods,
  branches,
  onSubmit,
  onClose,
  onDone,
}: {
  title: string;
  balanceCents: number;
  methods: { value: string; label: string }[];
  /** Customer payments: the branch that received it. Supplier payments: none (a payment date instead). */
  branches?: { id: number; name: string }[];
  onSubmit: (values: { amountCents: number; method: string; reference: string | null; branchId: number | null; date: string | null; note: string | null }) => Promise<unknown>;
  onClose: () => void;
  onDone: () => void;
}) {
  const form = useForm<PaymentForm>({
    initialValues: {
      amountKes: balanceCents > 0 ? balanceCents / 100 : "",
      method: methods[0].value,
      reference: "",
      branchId: branches?.length === 1 ? String(branches[0].id) : null,
      date: dayjs().format("YYYY-MM-DD"),
      note: "",
    },
    validate: {
      amountKes: (v) => ((optionalKesToCents(v) ?? 0) >= 100 ? null : "Enter the amount (at least KES 1)"),
      reference: (v, values) => (values.method === "cash" || v.trim() ? null : "Enter the transaction or cheque number"),
      branchId: (v) => (branches && !v ? "Choose the branch" : null),
      method: isNotEmpty("Choose how it was paid"),
    },
  });

  const { mutate, pending } = useApiMutation(
    (values: PaymentForm) =>
      onSubmit({
        amountCents: optionalKesToCents(values.amountKes) ?? 0,
        method: values.method,
        reference: values.reference.trim() || null,
        branchId: values.branchId ? Number(values.branchId) : null,
        date: branches ? null : values.date,
        note: values.note.trim() || null,
      }),
    { successMessage: "Payment recorded.", onValidationError: form.setErrors, onSuccess: onDone },
  );

  return (
    <Modal opened onClose={onClose} title={title} centered>
      <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
        <Stack>
          <Text size="sm" c="dimmed">
            Balance now {formatKes(balanceCents)}. Part payments are fine; they settle the oldest amounts first.
          </Text>
          <NumberInput label="Amount (KES)" min={0} decimalScale={2} thousandSeparator="," data-autofocus {...form.getInputProps("amountKes")} />
          <Select label="Method" data={methods} allowDeselect={false} {...form.getInputProps("method")} />
          <TextInput label="Reference" placeholder="M-PESA code, bank or cheque number" {...form.getInputProps("reference")} />
          {branches ? (
            <Select label="Received at" data={branches.map((b) => ({ value: String(b.id), label: b.name }))} {...form.getInputProps("branchId")} />
          ) : (
            <TextInput label="Paid on" type="date" max={dayjs().format("YYYY-MM-DD")} {...form.getInputProps("date")} />
          )}
          <Textarea label="Note (optional)" autosize minRows={2} {...form.getInputProps("note")} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              Record payment
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
