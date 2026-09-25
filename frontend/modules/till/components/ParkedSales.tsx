"use client";

import { Alert, Button, Group, Modal, Stack, Text, TextInput, UnstyledButton } from "@mantine/core";
import dayjs from "dayjs";
import { useState } from "react";
import { ApiError } from "@/api";
import type { ParkedSale } from "@/types/sales";
import { formatKes } from "@/utils/money";

/** Name the cart so it is easy to find again ("Man in blue shirt", "Table 4"). */
export function ParkModal({ totalCents, onClose, onPark }: { totalCents: number; onClose: () => void; onPark: (label: string) => Promise<void> }) {
  const [label, setLabel] = useState("");
  const [pending, setPending] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const submit = async () => {
    if (pending) return;
    setPending(true);
    setError(null);
    try {
      await onPark(label);
    } catch (e) {
      setError(e instanceof ApiError ? (Object.values(e.formErrors)[0] ?? e.message) : "Could not park the sale.");
      setPending(false);
    }
  };

  return (
    <Modal opened onClose={onClose} title={`Park sale · ${formatKes(totalCents)}`} centered>
      <Stack>
        <Text size="sm" c="dimmed">
          Nothing is charged or taken from stock until the sale is recalled and paid.
        </Text>
        <TextInput
          label="Who is it for? (optional)"
          placeholder="e.g. Man in blue shirt"
          maxLength={60}
          value={label}
          onChange={(e) => setLabel(e.currentTarget.value)}
          onKeyDown={(e) => e.key === "Enter" && void submit()}
          data-autofocus
        />
        {error && <Alert color="red">{error}</Alert>}
        <Group justify="flex-end">
          <Button variant="default" onClick={onClose} disabled={pending}>
            Cancel
          </Button>
          <Button onClick={() => void submit()} loading={pending}>
            Park sale
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}

export function RecallModal({
  parked,
  cartHasItems,
  onClose,
  onRecall,
}: {
  parked: ParkedSale[];
  cartHasItems: boolean;
  onClose: () => void;
  onRecall: (sale: ParkedSale) => Promise<void>;
}) {
  const [pendingId, setPendingId] = useState<number | null>(null);
  const [error, setError] = useState<string | null>(null);

  const recall = async (sale: ParkedSale) => {
    if (pendingId !== null) return;
    setPendingId(sale.id);
    setError(null);
    try {
      await onRecall(sale);
    } catch (e) {
      setError(e instanceof Error ? e.message : "Could not recall the sale.");
      setPendingId(null);
    }
  };

  return (
    <Modal opened onClose={onClose} title="Parked sales" centered>
      <Stack gap="sm">
        {cartHasItems && <Alert color="yellow">Finish or park the current sale before recalling another.</Alert>}
        {parked.length === 0 && (
          <Text c="dimmed" size="sm">
            No parked sales on this till.
          </Text>
        )}
        {parked.map((sale) => (
          <UnstyledButton
            key={sale.id}
            disabled={cartHasItems || pendingId !== null}
            onClick={() => void recall(sale)}
            style={{ padding: "12px 14px", borderRadius: 12, border: "1px solid var(--mantine-color-gray-3)", opacity: cartHasItems ? 0.5 : 1 }}
          >
            <Group justify="space-between" wrap="nowrap">
              <div>
                <Text fw={600}>{sale.label}</Text>
                <Text size="xs" c="dimmed">
                  {sale.lines.reduce((n, l) => n + l.quantity, 0)} items · {sale.parkedBy ?? "—"} · {sale.createdAt ? dayjs(sale.createdAt).format("h:mm a") : ""}
                </Text>
              </div>
              <Text fw={700}>{pendingId === sale.id ? "Recalling…" : formatKes(sale.totalCents)}</Text>
            </Group>
          </UnstyledButton>
        ))}
        {error && <Alert color="red">{error}</Alert>}
      </Stack>
    </Modal>
  );
}
