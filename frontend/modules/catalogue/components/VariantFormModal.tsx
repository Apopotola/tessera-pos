"use client";

import { Button, Group, Modal, NumberInput, Select, SimpleGrid, Stack, Switch, TextInput } from "@mantine/core";
import { useForm } from "@mantine/form";
import { notifications } from "@mantine/notifications";
import { catalogueApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import { CONTAINER_OPTIONS } from "@/modules/catalogue/constants";
import { useCatalogueReference } from "@/modules/catalogue/hooks/useCatalogueReference";
import type { Container, Variant, VariantPayload } from "@/types/catalogue";
import { optionalKesToCents } from "@/utils/money";

interface VariantFormValues {
  volumeMl: number | string;
  container: Container;
  sku: string;
  taxRateId: string | null;
  etimsItemClassCode: string;
  trackBatches: boolean;
  isActive: boolean;
  barcode: string;
  retailPriceKes: number | string;
}

interface VariantFormModalProps {
  opened: boolean;
  onClose: () => void;
  productId: number;
  /** Edit mode when given. */
  variant?: Variant;
  onSaved: () => void;
}

export default function VariantFormModal({ opened, onClose, productId, variant, onSaved }: VariantFormModalProps) {
  const editing = Boolean(variant);
  const { taxRateOptions } = useCatalogueReference();

  const form = useForm<VariantFormValues>({
    initialValues: {
      volumeMl: variant?.volumeMl ?? 750,
      container: variant?.container ?? "bottle",
      sku: variant?.sku ?? "",
      taxRateId: variant?.taxRate ? String(variant.taxRate.id) : null,
      etimsItemClassCode: variant?.etimsItemClassCode ?? "",
      trackBatches: variant?.trackBatches ?? false,
      isActive: variant?.isActive ?? true,
      barcode: "",
      retailPriceKes: "",
    },
    validate: {
      volumeMl: (v) => (Number(v) > 0 ? null : "Enter the size in ml"),
      sku: (v) => (v.trim() ? null : "Enter a SKU"),
      taxRateId: (v) => (v ? null : "Choose a tax rate"),
    },
  });

  const { mutate, pending } = useApiMutation(
    async (values: VariantFormValues) => {
      const payload: VariantPayload = {
        volumeMl: Number(values.volumeMl),
        container: values.container,
        sku: values.sku.trim(),
        taxRateId: Number(values.taxRateId),
        etimsItemClassCode: values.etimsItemClassCode.trim() || null,
        trackBatches: values.trackBatches,
      };

      if (variant) {
        await catalogueApi.updateVariant(variant.id, { ...payload, isActive: values.isActive });
        return [];
      }

      const result = await catalogueApi.createVariant(productId, {
        ...payload,
        barcodes: values.barcode.trim() ? [values.barcode.trim()] : [],
        retailPriceCents: optionalKesToCents(values.retailPriceKes),
      });
      return result.warnings;
    },
    {
      successMessage: editing ? "Size updated." : "Size added.",
      onValidationError: (errors) =>
        form.setErrors(
          Object.fromEntries(
            Object.entries(errors).map(([k, v]) => [k.replace(/^barcodes\.\d+$/, "barcode").replace("retailPriceCents", "retailPriceKes"), v]),
          ),
        ),
      onSuccess: (warnings) => {
        warnings.forEach((message) => notifications.show({ color: "yellow", title: "Check pricing", message }));
        onSaved();
      },
    },
  );

  return (
    <Modal opened={opened} onClose={onClose} title={editing ? `Edit ${variant?.displayName}` : "Add size"} size="lg">
      <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
        <Stack gap="md">
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <NumberInput label="Size (ml)" min={1} required {...form.getInputProps("volumeMl")} />
            <Select label="Container" data={CONTAINER_OPTIONS} allowDeselect={false} {...form.getInputProps("container")} />
            <TextInput label="SKU" required {...form.getInputProps("sku")} />
            <Select label="Tax" data={taxRateOptions} required {...form.getInputProps("taxRateId")} />
            <TextInput label="eTIMS item class code" description="From the KRA code list" {...form.getInputProps("etimsItemClassCode")} />
            {!editing && <TextInput label="Barcode" placeholder="Scan or type" {...form.getInputProps("barcode")} />}
            {!editing && (
              <NumberInput label="Retail price (KES)" min={0} decimalScale={2} thousandSeparator="," {...form.getInputProps("retailPriceKes")} />
            )}
          </SimpleGrid>
          <Switch label="Track batches and expiry (beer, cider, mixers)" {...form.getInputProps("trackBatches", { type: "checkbox" })} />
          {editing && <Switch label="Active" {...form.getInputProps("isActive", { type: "checkbox" })} />}
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              {editing ? "Save changes" : "Add size"}
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
