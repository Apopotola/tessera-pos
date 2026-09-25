"use client";

import { Button, Group, Modal, NumberInput, Select, Stack, TextInput } from "@mantine/core";
import { useForm } from "@mantine/form";
import { catalogueApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import type { Variant } from "@/types/catalogue";

interface VariantModalProps {
  opened: boolean;
  onClose: () => void;
  variant: Variant;
  onSaved: () => void;
}

/** Add a bottle barcode, or a barcode printed on one of the variant's packs. */
export function BarcodeModal({ opened, onClose, variant, onSaved }: VariantModalProps) {
  const form = useForm<{ code: string; packId: string | null }>({
    initialValues: { code: "", packId: null },
    validate: { code: (v) => (v.trim().length >= 4 ? null : "Scan or type the barcode") },
  });

  const packOptions = (variant.packs ?? []).map((p) => ({ value: String(p.id), label: `${p.name} of ${p.units}` }));

  const { mutate, pending } = useApiMutation(
    (values: { code: string; packId: string | null }) =>
      catalogueApi.addBarcode(variant.id, values.code.trim(), values.packId ? Number(values.packId) : null),
    {
      successMessage: "Barcode added.",
      onValidationError: (errors) => form.setErrors(errors),
      onSuccess: () => {
        form.reset();
        onSaved();
      },
    },
  );

  return (
    <Modal opened={opened} onClose={onClose} title={`Add barcode — ${variant.displayName}`}>
      <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
        <Stack>
          <TextInput label="Barcode" placeholder="Scan now" data-autofocus required {...form.getInputProps("code")} />
          <Select
            label="Printed on"
            placeholder="Single unit"
            data={packOptions}
            clearable
            description="Leave empty for the bottle/can itself"
            {...form.getInputProps("packId")}
          />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              Add barcode
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}

interface PackValues {
  name: string;
  units: number | string;
  barcode: string;
}

/** Add a case/crate: a purchasing and wholesale unit of N variant units. */
export function PackModal({ opened, onClose, variant, onSaved }: VariantModalProps) {
  const form = useForm<PackValues>({
    initialValues: { name: "Case", units: 12, barcode: "" },
    validate: {
      name: (v) => (v.trim() ? null : "Enter a name, e.g. Case"),
      units: (v) => (Number(v) >= 2 ? null : "A pack holds at least 2 units"),
    },
  });

  const { mutate, pending } = useApiMutation(
    (values: PackValues) =>
      catalogueApi.createPack(variant.id, { name: values.name.trim(), units: Number(values.units), barcode: values.barcode.trim() || null }),
    {
      successMessage: "Pack added.",
      onValidationError: (errors) => form.setErrors(errors),
      onSuccess: () => {
        form.reset();
        onSaved();
      },
    },
  );

  return (
    <Modal opened={opened} onClose={onClose} title={`Add pack — ${variant.displayName}`}>
      <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
        <Stack>
          <TextInput label="Pack name" placeholder="Case, Crate, Carton" required {...form.getInputProps("name")} />
          <NumberInput label={`Units of ${variant.volumeLabel} per pack`} min={2} max={1000} required {...form.getInputProps("units")} />
          <TextInput label="Pack barcode" placeholder="Optional" {...form.getInputProps("barcode")} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              Add pack
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
