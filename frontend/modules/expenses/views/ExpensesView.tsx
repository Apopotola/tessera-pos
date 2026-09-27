"use client";

import { Badge, Button, Group, Modal, NumberInput, Pagination, Select, SimpleGrid, Stack, Table, Text, TextInput } from "@mantine/core";
import { isNotEmpty, useForm } from "@mantine/form";
import { IconPlus } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { expensesApi, organisationApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import NoteModal from "@/components/shared/NoteModal";
import QueryState from "@/components/shared/QueryState";
import StatTile from "@/components/shared/StatTile";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import { useAppSelector } from "@/store/hooks";
import { EXPENSE_SOURCES, type Expense, type ExpenseStatus } from "@/types/expenses";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes, optionalKesToCents } from "@/utils/money";

const STATUS = { pending: ["Waiting for approval", "yellow"], approved: ["Approved", "green"], rejected: ["Rejected", "red"] } as const;

/** Expenses and petty cash: recorded here or paid out at the till, approved by a manager. */
export default function ExpensesView({ title, section }: WorkspaceViewProps) {
  const { can } = usePermissions();
  const me = useAppSelector((state) => state.auth.user);
  const [status, setStatus] = useState<ExpenseStatus | null>(null);
  const [from, setFrom] = useState(dayjs().startOf("month").format("YYYY-MM-DD"));
  const [to, setTo] = useState(dayjs().format("YYYY-MM-DD"));
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const [dialog, setDialog] = useState<{ kind: "reject" | "reverse"; expense: Expense } | null>(null);
  const fetchExpenses = useCallback(() => expensesApi.list({ status, from, to, page }), [status, from, to, page]);
  const { data, loading, error, reload } = useApiQuery(fetchExpenses);
  const approve = useApiMutation((e: Expense) => expensesApi.approve(e.id), { successMessage: "Expense approved.", onSuccess: reload });
  const canApprove = can(PERMISSIONS.EXPENSES_APPROVE);

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Money spent running the shop: petty cash, bank or M-PESA recorded here, and cash paid out of a till. A manager approves each one; approved expenses are reversed, never edited."
      actions={
        can(PERMISSIONS.EXPENSES_REQUEST) && (
          <Button leftSection={<IconPlus size={16} />} onClick={() => setCreating(true)}>
            Record expense
          </Button>
        )
      }
    >
      <Group align="flex-end" gap="sm" wrap="wrap">
        <TextInput label="From" type="date" value={from} max={to} onChange={(e) => e.currentTarget.value && setFrom(e.currentTarget.value)} />
        <TextInput label="To" type="date" value={to} min={from} onChange={(e) => e.currentTarget.value && setTo(e.currentTarget.value)} />
        <Select
          label="Status"
          placeholder="All"
          clearable
          data={[
            { value: "pending", label: "Waiting for approval" },
            { value: "approved", label: "Approved" },
            { value: "rejected", label: "Rejected" },
          ]}
          value={status}
          onChange={(v) => {
            setStatus(v as ExpenseStatus | null);
            setPage(1);
          }}
        />
      </Group>
      {data && (
        <SimpleGrid cols={{ base: 1, sm: 2 }}>
          <StatTile label="Approved in this period" value={formatKes(data.totals.approvedCents)} />
          <StatTile label="Waiting for approval" value={formatKes(data.totals.pendingCents)} hint={`${data.totals.pendingCount} expense(s)`} highlight={data.totals.pendingCount > 0} />
        </SimpleGrid>
      )}
      <DataCard>
        <QueryState loading={loading && !data} error={error} isEmpty={!data?.items.length} emptyMessage="No expenses in this period." onRetry={reload}>
          <Table verticalSpacing="sm" fz="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Date</Table.Th>
                <Table.Th>What for</Table.Th>
                <Table.Th>Paid from</Table.Th>
                <Table.Th ta="right">Amount</Table.Th>
                <Table.Th>Status</Table.Th>
                <Table.Th />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((e) => {
                const [label, color] = STATUS[e.status];
                return (
                  <Table.Tr key={e.id} opacity={e.reversed ? 0.55 : 1}>
                    <Table.Td style={{ whiteSpace: "nowrap" }}>
                      {dayjs(e.spentOn).format("DD MMM YYYY")}
                      <Text size="xs" c="dimmed" ff="monospace">
                        {e.number}
                      </Text>
                    </Table.Td>
                    <Table.Td maw={320}>
                      <Text size="sm" fw={600}>
                        {e.category.name}
                        {e.payee ? ` · ${e.payee}` : ""}
                      </Text>
                      <Text size="xs" c="dimmed" lineClamp={2}>
                        {e.description}
                      </Text>
                    </Table.Td>
                    <Table.Td>
                      {EXPENSE_SOURCES[e.paidFrom]}
                      <Text size="xs" c="dimmed">
                        {e.branch.code}
                        {e.reference ? ` · ${e.reference}` : ""}
                      </Text>
                    </Table.Td>
                    <Table.Td ta="right" c={e.amountCents < 0 ? "green.8" : undefined}>
                      {formatKes(e.amountCents)}
                    </Table.Td>
                    <Table.Td>
                      <Badge color={e.reversed ? "gray" : color} variant="light">
                        {e.reversed ? "Reversed" : e.isReversal ? "Reversal" : label}
                      </Badge>
                      <Text size="xs" c="dimmed" mt={2}>
                        By {e.requestedBy ?? "—"}
                        {e.reviewedBy && e.reviewedBy !== e.requestedBy ? ` · ${e.status === "rejected" ? "rejected" : "approved"} by ${e.reviewedBy}` : ""}
                      </Text>
                      {e.status === "rejected" && e.reviewNote && (
                        <Text size="xs" c="red">
                          {e.reviewNote}
                        </Text>
                      )}
                    </Table.Td>
                    <Table.Td ta="right">
                      {e.status === "pending" && canApprove && e.requestedById !== me?.id && (
                        <Group gap="xs" justify="flex-end" wrap="nowrap">
                          <Button size="xs" loading={approve.pending} onClick={() => void approve.mutate(e)}>
                            Approve
                          </Button>
                          <Button size="xs" variant="default" color="red" onClick={() => setDialog({ kind: "reject", expense: e })}>
                            Reject
                          </Button>
                        </Group>
                      )}
                      {e.status === "approved" && canApprove && !e.isReversal && !e.reversed && e.paidFrom !== "till" && (
                        <Button size="xs" variant="subtle" color="red" onClick={() => setDialog({ kind: "reverse", expense: e })}>
                          Reverse
                        </Button>
                      )}
                    </Table.Td>
                  </Table.Tr>
                );
              })}
            </Table.Tbody>
          </Table>
        </QueryState>
        {data && data.meta.lastPage > 1 && (
          <Group justify="flex-end" p="sm">
            <Pagination value={page} onChange={setPage} total={data.meta.lastPage} size="sm" />
          </Group>
        )}
      </DataCard>

      {creating && (
        <ExpenseFormModal
          onClose={() => setCreating(false)}
          onSaved={() => {
            setCreating(false);
            reload();
          }}
        />
      )}
      {dialog && (
        <NoteModal
          title={dialog.kind === "reject" ? `Reject ${dialog.expense.number}?` : `Reverse ${dialog.expense.number}?`}
          description={dialog.kind === "reverse" ? `A reversal of ${formatKes(dialog.expense.amountCents)} is added; the expense stays on record.` : undefined}
          label="Reason"
          confirmLabel={dialog.kind === "reject" ? "Reject" : "Reverse"}
          danger
          successMessage={dialog.kind === "reject" ? "Expense rejected." : "Expense reversed."}
          onSubmit={(note) => (dialog.kind === "reject" ? expensesApi.reject(dialog.expense.id, note) : expensesApi.reverse(dialog.expense.id, note))}
          onClose={() => setDialog(null)}
          onDone={() => {
            setDialog(null);
            reload();
          }}
        />
      )}
    </WorkspacePage>
  );
}

interface FormValues {
  branchId: string | null;
  categoryId: string | null;
  amountKes: number | string;
  paidFrom: "petty_cash" | "bank" | "mpesa";
  payee: string;
  description: string;
  reference: string;
  spentOn: string;
}

function ExpenseFormModal({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const fetchCategories = useCallback(() => expensesApi.categories(), []);
  const fetchBranches = useCallback(() => organisationApi.branches(), []);
  const categories = useApiQuery(fetchCategories).data ?? [];
  const branches = useApiQuery(fetchBranches).data ?? [];
  const form = useForm<FormValues>({
    initialValues: { branchId: null, categoryId: null, amountKes: "", paidFrom: "petty_cash", payee: "", description: "", reference: "", spentOn: dayjs().format("YYYY-MM-DD") },
    validate: {
      branchId: isNotEmpty("Choose the branch"),
      categoryId: isNotEmpty("Choose what it was for"),
      amountKes: (v) => ((optionalKesToCents(v) ?? 0) >= 100 ? null : "Enter the amount"),
      description: isNotEmpty("Say briefly what it was for"),
    },
  });
  const { mutate, pending } = useApiMutation(
    (v: FormValues) =>
      expensesApi.create({
        branchId: Number(v.branchId),
        categoryId: Number(v.categoryId),
        amountCents: optionalKesToCents(v.amountKes) ?? 0,
        paidFrom: v.paidFrom,
        payee: v.payee.trim() || null,
        description: v.description.trim(),
        reference: v.reference.trim() || null,
        spentOn: v.spentOn,
      }),
    { successMessage: "Expense sent for approval.", onValidationError: form.setErrors, onSuccess: onSaved },
  );
  const branchOptions = branches.map((b) => ({ value: String(b.id), label: b.name }));

  return (
    <Modal opened onClose={onClose} title="Record an expense" centered>
      <form onSubmit={form.onSubmit((v) => void mutate(v))} noValidate>
        <Stack>
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <Select label="Branch" data={branchOptions} {...form.getInputProps("branchId")} />
            <Select label="What for" data={categories.map((c) => ({ value: String(c.id), label: c.name }))} searchable {...form.getInputProps("categoryId")} />
          </SimpleGrid>
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <NumberInput label="Amount (KES)" min={0} decimalScale={2} thousandSeparator="," data-autofocus {...form.getInputProps("amountKes")} />
            <TextInput label="Date" type="date" max={dayjs().format("YYYY-MM-DD")} {...form.getInputProps("spentOn")} />
          </SimpleGrid>
          <Select
            label="Paid from"
            description="Cash from a till drawer is paid out at the till instead (Till menu → Pay out cash)."
            data={[
              { value: "petty_cash", label: "Petty cash" },
              { value: "bank", label: "Bank" },
              { value: "mpesa", label: "M-PESA" },
            ]}
            allowDeselect={false}
            {...form.getInputProps("paidFrom")}
          />
          <TextInput label="Paid to (optional)" placeholder="e.g. Boda rider, KPLC" {...form.getInputProps("payee")} />
          <TextInput label="Description" placeholder="e.g. Delivery of 3 crates to Kilimani" {...form.getInputProps("description")} />
          <TextInput label="Receipt or transaction number (optional)" {...form.getInputProps("reference")} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              Send for approval
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
