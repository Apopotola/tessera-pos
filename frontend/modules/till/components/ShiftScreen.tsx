"use client";

import { Alert, Button, Group, Modal, NumberInput, Paper, SimpleGrid, Stack, Text, Textarea } from "@mantine/core";
import { useForm } from "@mantine/form";
import { IconInfoCircle, IconLock, IconLogout } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useState } from "react";
import { salesApi } from "@/api";
import { brand } from "@/app/theme";
import { useApiMutation } from "@/hooks/useApiMutation";
import TillHeader from "@/modules/till/components/TillHeader";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { logout } from "@/store/slices/authSlice";
import type { Shift, TillContext } from "@/types/till";
import { formatKes, kesToCents } from "@/utils/money";

interface ShiftScreenProps {
  context: TillContext;
  shift: Shift;
  onEnded: () => void;
}

/** Holding screen while the shift is open. The selling screen arrives with the Sales module. */
export default function ShiftScreen({ context, shift, onEnded }: ShiftScreenProps) {
  const dispatch = useAppDispatch();
  const user = useAppSelector((state) => state.auth.user);
  const [ending, setEnding] = useState(false);
  const [closed, setClosed] = useState<Shift | null>(null);

  const lock = async () => {
    await dispatch(logout());
    onEnded();
  };

  return (
    <div style={{ minHeight: "100vh", background: brand.navy, padding: "40px 48px", display: "flex", flexDirection: "column", gap: 32 }}>
      <TillHeader context={context} />

      <Paper p="xl" radius="lg" style={{ background: brand.navyRaised, maxWidth: 720 }}>
        <Stack gap="md">
          <Text c="gray.5" size="sm" tt="uppercase" fw={600} style={{ letterSpacing: "0.1em" }}>
            Shift open
          </Text>
          <Text c="white" className="tessera-display" fz={40}>
            {user?.name}
          </Text>
          <SimpleGrid cols={2}>
            <div>
              <Text c="gray.5" size="sm">
                Started
              </Text>
              <Text c="white" fw={600}>
                {dayjs(shift.openedAt).format("h:mm a, D MMM")}
              </Text>
            </div>
            <div>
              <Text c="gray.5" size="sm">
                Opening float
              </Text>
              <Text c="white" fw={600}>
                {formatKes(shift.openingFloatCents)}
              </Text>
            </div>
          </SimpleGrid>
          <Alert variant="light" color="tessera" icon={<IconInfoCircle size={18} />}>
            The selling screen arrives with the Sales module. You can lock the till or end your shift here.
          </Alert>
          <Group>
            <Button size="lg" variant="default" leftSection={<IconLock size={18} />} onClick={() => void lock()}>
              Lock till
            </Button>
            <Button size="lg" color="amber.5" c={brand.navy} leftSection={<IconLogout size={18} />} onClick={() => setEnding(true)}>
              End shift
            </Button>
          </Group>
        </Stack>
      </Paper>

      {ending && !closed && <EndShiftModal shift={shift} onClose={() => setEnding(false)} onClosed={setClosed} />}
      {closed && <ShiftSummaryModal shift={closed} onDone={() => void lock()} />}
    </div>
  );
}

/** Blind count: the cashier enters counted cash before the expected amount is shown. */
function EndShiftModal({ shift, onClose, onClosed }: { shift: Shift; onClose: () => void; onClosed: (shift: Shift) => void }) {
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

function ShiftSummaryModal({ shift, onDone }: { shift: Shift; onDone: () => void }) {
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
