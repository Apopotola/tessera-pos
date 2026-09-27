"use client";

import { Alert, Button, Group, Modal, NumberInput, Select, Stack, Text, TextInput } from "@mantine/core";
import { notifications } from "@mantine/notifications";
import { useCallback, useState } from "react";
import { ApiError, expensesApi } from "@/api";
import { useApiQuery } from "@/hooks/useApiQuery";
import type { Approval, ApprovalAction } from "@/types/sales";
import { formatKes, kesToCents } from "@/utils/money";

/**
 * Pay an expense out of the drawer (a delivery rider, casual labour…), in front of a manager
 * who confirms with their PIN. It lowers the cash expected at cash-up.
 */
export default function PayoutModal({
  requestApproval,
  onClose,
  onPaid,
}: {
  requestApproval: (action: ApprovalAction, detail?: string | null) => Promise<Approval | null>;
  onClose: () => void;
  onPaid: () => void;
}) {
  const fetchCategories = useCallback(() => expensesApi.categories(), []);
  const categories = useApiQuery(fetchCategories).data ?? [];
  const [categoryId, setCategoryId] = useState<string | null>(null);
  const [amount, setAmount] = useState<number | string>("");
  const [payee, setPayee] = useState("");
  const [description, setDescription] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [pending, setPending] = useState(false);
  const cents = kesToCents(Number(amount) || 0);
  const ready = cents >= 100 && categoryId !== null && description.trim() !== "";

  const submit = async () => {
    if (!ready) return;
    const approval = await requestApproval("payout", `${formatKes(cents)} for ${description.trim()}.`);
    if (!approval) return;
    setPending(true);
    setError(null);
    try {
      await expensesApi.tillPayout({ categoryId: Number(categoryId), amountCents: cents, payee: payee.trim() || null, description: description.trim(), approvalToken: approval.token });
      notifications.show({ color: "green", message: `${formatKes(cents)} paid out, witnessed by ${approval.approver.name}.` });
      onPaid();
    } catch (e) {
      setError(e instanceof ApiError ? (Object.values(e.formErrors)[0] ?? e.message) : "Could not record the payout.");
    } finally {
      setPending(false);
    }
  };

  return (
    <Modal opened onClose={onClose} title="Pay out cash for an expense" centered>
      <Stack>
        <Text size="sm" c="dimmed">
          Cash leaves the drawer in front of a manager, who confirms with their PIN. It comes off what the drawer should hold at cash-up.
        </Text>
        <NumberInput label="Amount (KES)" size="lg" min={0} decimalScale={2} thousandSeparator="," value={amount} onChange={setAmount} data-autofocus />
        <Select label="What for" data={categories.map((c) => ({ value: String(c.id), label: c.name }))} value={categoryId} onChange={setCategoryId} searchable />
        <TextInput label="Paid to (optional)" placeholder="e.g. Boda rider" value={payee} onChange={(e) => setPayee(e.currentTarget.value)} />
        <TextInput label="Description" placeholder="e.g. Delivery to Kilimani" value={description} onChange={(e) => setDescription(e.currentTarget.value)} />
        {error && <Alert color="red">{error}</Alert>}
        <Group justify="flex-end">
          <Button variant="default" onClick={onClose} disabled={pending}>
            Cancel
          </Button>
          <Button onClick={() => void submit()} loading={pending} disabled={!ready}>
            Pay out
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}
