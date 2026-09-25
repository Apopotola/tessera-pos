"use client";

import { Button, Group, Input, Modal, PinInput, Stack, Text } from "@mantine/core";
import { useForm } from "@mantine/form";
import { usersApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import type { ManagedUser } from "@/types/users";

interface PinValues {
  pin: string;
  pinConfirmation: string;
}

/** Sets a 4-digit till PIN. The server rejects guessable PINs (1111, 1234…). */
export default function PinModal({ user, onClose, onSaved }: { user: ManagedUser; onClose: () => void; onSaved: () => void }) {
  const form = useForm<PinValues>({
    initialValues: { pin: "", pinConfirmation: "" },
    validate: {
      pin: (v) => (/^\d{4}$/.test(v) ? null : "Enter 4 digits"),
      pinConfirmation: (v, values) => (v === values.pin ? null : "The PINs do not match"),
    },
  });

  const { mutate, pending } = useApiMutation((values: PinValues) => usersApi.setPin(user.id, values.pin, values.pinConfirmation), {
    successMessage: `Till PIN set for ${user.name}.`,
    onValidationError: (errors) => form.setErrors({ pin: errors.pin }),
    onSuccess: onSaved,
  });

  return (
    <Modal opened onClose={onClose} title={`Till PIN — ${user.name}`} centered>
      <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
        <Stack>
          <Text size="sm" c="dimmed">
            Give the PIN to {user.name} privately. They use it with their name on the till screen.
          </Text>
          <Input.Wrapper label="New PIN" error={form.errors.pin}>
            <PinInput length={4} type="number" mask oneTimeCode={false} aria-label="New PIN" {...form.getInputProps("pin")} />
          </Input.Wrapper>
          <Input.Wrapper label="Repeat PIN" error={form.errors.pinConfirmation}>
            <PinInput length={4} type="number" mask oneTimeCode={false} aria-label="Repeat PIN" {...form.getInputProps("pinConfirmation")} />
          </Input.Wrapper>
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              Save PIN
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
