"use client";

import { Button, Group, Modal, PasswordInput, Stack, Text } from "@mantine/core";
import { useForm } from "@mantine/form";
import { usersApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import type { ManagedUser } from "@/types/users";

interface PasswordValues {
  password: string;
  passwordConfirmation: string;
}

/** Sets a temporary back-office password for someone who forgot theirs; they choose their own at next sign-in. */
export default function PasswordResetModal({ user, onClose, onSaved }: { user: ManagedUser; onClose: () => void; onSaved: () => void }) {
  const form = useForm<PasswordValues>({
    initialValues: { password: "", passwordConfirmation: "" },
    validate: {
      password: (v) => (v.length >= 8 ? null : "At least 8 characters"),
      passwordConfirmation: (v, values) => (v === values.password ? null : "The passwords do not match"),
    },
  });

  const { mutate, pending } = useApiMutation((values: PasswordValues) => usersApi.resetPassword(user.id, values.password, values.passwordConfirmation), {
    successMessage: `Password reset for ${user.name}.`,
    onValidationError: (errors) => form.setErrors({ password: errors.password }),
    onSuccess: onSaved,
  });

  return (
    <Modal opened onClose={onClose} title={`Reset password — ${user.name}`} centered>
      <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
        <Stack>
          <Text size="sm" c="dimmed">
            Type a temporary password and give it to {user.name} privately. They must choose their own the next time they sign in, and
            they are signed out of the back office now.
          </Text>
          <PasswordInput label="Temporary password" autoComplete="new-password" data-autofocus {...form.getInputProps("password")} />
          <PasswordInput label="Repeat password" autoComplete="new-password" {...form.getInputProps("passwordConfirmation")} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              Reset password
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
