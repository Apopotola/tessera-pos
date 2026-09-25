"use client";

import { Button, Group, Modal, Select, SimpleGrid, Stack, Textarea } from "@mantine/core";
import { useForm } from "@mantine/form";
import { inventoryApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import LineItemsEditor, { newLine, type LineRow } from "@/modules/inventory/components/LineItemsEditor";
import { useLocations } from "@/modules/inventory/hooks/useLocations";

interface Values {
  fromLocationId: string | null;
  toLocationId: string | null;
  note: string;
  lines: LineRow[];
}

/** Request stock from one location (or branch) to another. */
export default function TransferFormModal({ onClose, onSaved }: { onClose: () => void; onSaved: () => void }) {
  const locations = useLocations();

  const form = useForm<Values>({
    initialValues: { fromLocationId: null, toLocationId: null, note: "", lines: [newLine()] },
    validate: {
      fromLocationId: (v) => (v ? null : "Where is the stock now?"),
      toLocationId: (v, values) => (!v ? "Where should it go?" : v === values.fromLocationId ? "Choose a different destination" : null),
    },
  });

  const { mutate, pending } = useApiMutation(
    (values: Values) =>
      inventoryApi.createTransfer({
        fromLocationId: Number(values.fromLocationId),
        toLocationId: Number(values.toLocationId),
        note: values.note.trim() || null,
        lines: values.lines.filter((l) => l.variant).map((l) => ({ variantId: l.variant!.id, quantity: Number(l.quantity) })),
      }),
    {
      successMessage: (t) => `${t.number} requested.`,
      onValidationError: (errors) => form.setErrors(errors),
      onSuccess: onSaved,
    },
  );

  const submit = (values: Values) => {
    if (!values.lines.some((l) => l.variant)) {
      form.setErrors({ lines: "Add at least one item" });
      return;
    }
    void mutate(values);
  };

  return (
    <Modal opened onClose={onClose} title="Request a transfer" size="xl">
      <form onSubmit={form.onSubmit(submit)} noValidate>
        <Stack>
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <Select label="From" data={locations.options} required {...form.getInputProps("fromLocationId")} />
            <Select label="To" data={locations.options} required {...form.getInputProps("toLocationId")} />
          </SimpleGrid>
          <LineItemsEditor lines={form.values.lines} onChange={(lines) => form.setFieldValue("lines", lines)} errors={form.errors as Record<string, string>} />
          <Textarea label="Note" autosize minRows={2} placeholder="Optional" {...form.getInputProps("note")} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              Request transfer
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
