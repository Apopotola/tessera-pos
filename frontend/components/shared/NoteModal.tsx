"use client";

import { Button, Group, Modal, Stack, Text, Textarea } from "@mantine/core";
import { useForm } from "@mantine/form";
import { useApiMutation } from "@/hooks/useApiMutation";

interface NoteModalProps {
  title: string;
  description?: string;
  label: string;
  confirmLabel: string;
  /** Red confirm button for destructive actions (reject, cancel). */
  danger?: boolean;
  required?: boolean;
  onSubmit: (note: string) => Promise<unknown>;
  onClose: () => void;
  onDone: () => void;
  successMessage: string;
}

/** Confirm an action that needs a reason (reject, cancel…). The reason is kept in the audit log. */
export default function NoteModal({ title, description, label, confirmLabel, danger, required = true, onSubmit, onClose, onDone, successMessage }: NoteModalProps) {
  const form = useForm({
    initialValues: { note: "" },
    validate: { note: (v) => (!required || v.trim() ? null : "A reason is required") },
  });

  const { mutate, pending } = useApiMutation((note: string) => onSubmit(note), {
    successMessage,
    onValidationError: (errors) => form.setErrors(errors),
    onSuccess: onDone,
  });

  return (
    <Modal opened onClose={onClose} title={title} centered>
      <form onSubmit={form.onSubmit((values) => void mutate(values.note.trim()))} noValidate>
        <Stack>
          {description && (
            <Text size="sm" c="dimmed">
              {description}
            </Text>
          )}
          <Textarea label={label} required={required} autosize minRows={2} data-autofocus {...form.getInputProps("note")} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" color={danger ? "red" : undefined} loading={pending}>
              {confirmLabel}
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
