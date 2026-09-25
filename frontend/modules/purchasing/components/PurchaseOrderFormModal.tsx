"use client";

import { Button, Group, Modal, Select, SimpleGrid, Stack, Text, Textarea } from "@mantine/core";
import { DateInput } from "@mantine/dates";
import { useForm } from "@mantine/form";
import { useEffect, useMemo, useState } from "react";
import { purchasingApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import LineItemsEditor, { newLine, type LineRow } from "@/modules/inventory/components/LineItemsEditor";
import { useLocations } from "@/modules/inventory/hooks/useLocations";
import { useSuppliers } from "@/modules/purchasing/hooks/useSuppliers";
import type { PurchaseOrder, SupplierItem } from "@/types/purchasing";
import { centsToKes, formatKes, optionalKesToCents } from "@/utils/money";

interface Values {
  supplierId: string | null;
  locationId: string | null;
  expectedDate: string | null;
  note: string;
  lines: LineRow[];
}

/** Raise a draft purchase order. Last prices paid to the chosen supplier fill in automatically. */
export default function PurchaseOrderFormModal({ onClose, onSaved }: { onClose: () => void; onSaved: (order: PurchaseOrder) => void }) {
  const suppliers = useSuppliers();
  const locations = useLocations();
  const [supplierItems, setSupplierItems] = useState<Map<number, SupplierItem>>(new Map());

  const form = useForm<Values>({
    initialValues: { supplierId: null, locationId: null, expectedDate: null, note: "", lines: [newLine()] },
    validate: {
      supplierId: (v) => (v ? null : "Choose a supplier"),
      locationId: (v) => (v ? null : "Where should it be delivered?"),
    },
  });

  const supplierId = form.values.supplierId;
  useEffect(() => {
    if (!supplierId) return;
    let active = true;
    purchasingApi
      .supplierItems(Number(supplierId))
      .then((items) => active && setSupplierItems(new Map(items.map((i) => [i.variantId, i]))))
      .catch(() => undefined);
    return () => {
      active = false;
    };
  }, [supplierId]);

  // Fill a newly picked item's cost with the last price paid to this supplier.
  const onLinesChange = (lines: LineRow[]) =>
    form.setFieldValue(
      "lines",
      lines.map((l) => {
        const last = l.variant ? supplierItems.get(l.variant.id)?.lastCostCents : null;
        return l.unitCostKes === "" && last ? { ...l, unitCostKes: centsToKes(last) } : l;
      }),
    );

  const netTotal = useMemo(
    () => form.values.lines.reduce((sum, l) => sum + (Number(l.quantity) || 0) * (optionalKesToCents(l.unitCostKes) ?? 0), 0),
    [form.values.lines],
  );

  const { mutate, pending } = useApiMutation(
    (values: Values) =>
      purchasingApi.createOrder({
        supplierId: Number(values.supplierId),
        locationId: Number(values.locationId),
        expectedDate: values.expectedDate,
        note: values.note.trim() || null,
        lines: values.lines.flatMap((l) => (l.variant ? [{ variantId: l.variant.id, quantity: Number(l.quantity), unitCostCents: optionalKesToCents(l.unitCostKes) ?? 0 }] : [])),
      }),
    {
      successMessage: (o) => `${o.number} saved as a draft. Someone else must approve it.`,
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
    <Modal opened onClose={onClose} title="New purchase order" size="xl">
      <form onSubmit={form.onSubmit(submit)} noValidate>
        <Stack>
          <SimpleGrid cols={{ base: 1, sm: 3 }}>
            <Select label="Supplier" data={suppliers.options} searchable required {...form.getInputProps("supplierId")} />
            <Select label="Deliver to" data={locations.options} required {...form.getInputProps("locationId")} />
            <DateInput label="Expected delivery" placeholder="Optional" clearable minDate={new Date()} valueFormat="DD MMM YYYY" {...form.getInputProps("expectedDate")} />
          </SimpleGrid>
          <LineItemsEditor
            lines={form.values.lines}
            onChange={onLinesChange}
            withCost
            costRequired
            costLabel="Unit cost excl. VAT (KES)"
            errors={form.errors as Record<string, string>}
          />
          <Text size="sm" ta="right">
            Subtotal excl. VAT: <b>{formatKes(netTotal)}</b>{" "}
            <Text span c="dimmed" size="xs">
              (VAT is added per item&apos;s tax rate)
            </Text>
          </Text>
          <Textarea label="Note to supplier" autosize minRows={2} {...form.getInputProps("note")} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              Save draft
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
