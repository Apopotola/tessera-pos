"use client";

import { Alert, Button, Group, Modal, PasswordInput, Stack } from "@mantine/core";
import { isNotEmpty, matchesField, useForm } from "@mantine/form";
import { IconKey } from "@tabler/icons-react";
import { authApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useAppDispatch } from "@/store/hooks";
import { userUpdated } from "@/store/slices/authSlice";
import type { ChangePasswordPayload } from "@/types/auth";

/**
 * Change your own password. `forced`: set by an admin or expired (Settings → Staff) —
 * the back office stays blocked until it is done, so the dialog cannot be closed.
 */
export default function ChangePasswordModal({ forced = false, onClose }: { forced?: boolean; onClose: () => void }) {
  const dispatch = useAppDispatch();
  const form = useForm<ChangePasswordPayload>({
    mode: "uncontrolled",
    initialValues: { currentPassword: "", password: "", password_confirmation: "" },
    validate: {
      currentPassword: isNotEmpty("Enter your current password"),
      password: isNotEmpty("Choose a new password"),
      password_confirmation: matchesField("password", "The passwords do not match"),
    },
  });

  const { mutate, pending } = useApiMutation((values: ChangePasswordPayload) => authApi.changePassword(values), {
    successMessage: "Password changed.",
    onValidationError: form.setErrors,
    onSuccess: (user) => {
      dispatch(userUpdated(user));
      onClose();
    },
  });

  return (
    <Modal
      opened
      onClose={onClose}
      title="Change your password"
      centered
      withCloseButton={!forced}
      closeOnClickOutside={!forced}
      closeOnEscape={!forced}
    >
      <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
        <Stack>
          {forced && (
            <Alert color="amber" variant="light" icon={<IconKey size={18} />}>
              Choose a new password to continue. Your administrator set this one, or it has not been changed for a while.
            </Alert>
          )}
          <PasswordInput label="Current password" autoComplete="current-password" data-autofocus key={form.key("currentPassword")} {...form.getInputProps("currentPassword")} />
          <PasswordInput label="New password" autoComplete="new-password" key={form.key("password")} {...form.getInputProps("password")} />
          <PasswordInput label="Type it again" autoComplete="new-password" key={form.key("password_confirmation")} {...form.getInputProps("password_confirmation")} />
          <Group justify="flex-end">
            {!forced && (
              <Button variant="default" onClick={onClose} disabled={pending}>
                Cancel
              </Button>
            )}
            <Button type="submit" loading={pending}>
              Change password
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
