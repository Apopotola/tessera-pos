"use client";

import { Button, Group, Modal, NumberInput, SimpleGrid, Stack, Text, Textarea } from "@mantine/core";
import { useForm } from "@mantine/form";
import { salesApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import type { Shift } from "@/types/till";
import { formatKes, kesToCents } from "@/utils/money";

/** Blind count: the cashier enters counted cash before the expected amount is shown. */
export function EndShiftModal({ shift, onClose, onClosed }: { shift: Shift; onClose: () => void; onClosed: (shift: Shift) => void }) {
  const form = useForm<{ countedKes: number | string; note: string }>({
    initialValues: { countedKes: "", note: "" },
    validate: { countedKes: (v) => (v !== "" && Number(v) >= 0 ? null : "Count the cash in the drawer and enter the total") },
  });

  const { mutate, pending } = useApiMutation(
    (values: { countedKes: number | string; note: string }) => salesApi.closeShift(shift.id, kesToCents(Number(values.countedKes)), values.note.trim() || null),
    { onValidationError: (errors) => form.setErrors({ countedKes: errors.countedCashCents, note: errors.note }), onSuccess: onClosed },
  );

  return (
    <Modal opened onClose={onClose} title="End shift — count the cash drawer" centered>
      <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
        <Stack>
          <Text size="sm" c="dimmed">
            Count every note and coin in the drawer, including the float. The expected amount is shown after you submit.
          </Text>
          <NumberInput label="Cash counted (KES)" size="lg" min={0} decimalScale={2} thousandSeparator="," required data-autofocus {...form.getInputProps("countedKes")} />
          <Textarea label="Note (optional)" autosize minRows={2} {...form.getInputProps("note")} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              Submit count
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}

export function ShiftSummaryModal({ shift, onDone }: { shift: Shift; onDone: () => void }) {
  const variance = shift.varianceCents ?? 0;

  return (
    <Modal opened onClose={onDone} title="Shift ended" centered withCloseButton={false}>
      <Stack>
        <SimpleGrid cols={3}>
          <Summary label="Expected" value={formatKes(shift.expectedCashCents)} />
          <Summary label="Counted" value={formatKes(shift.countedCashCents)} />
          <Summary label={variance < 0 ? "Short" : variance > 0 ? "Over" : "Variance"} value={formatKes(Math.abs(variance))} color={variance === 0 ? "green" : "red"} />
        </SimpleGrid>
        <Text size="sm" c="dimmed">
          A manager reviews any difference. You are now signed out.
        </Text>
        <Button onClick={onDone}>Done</Button>
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
