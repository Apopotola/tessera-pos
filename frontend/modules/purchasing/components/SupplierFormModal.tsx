"use client";

import { Button, Group, Modal, NumberInput, SimpleGrid, Stack, Switch, TextInput, Textarea } from "@mantine/core";
import { isNotEmpty, useForm } from "@mantine/form";
import { purchasingApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import type { Supplier, SupplierPayload } from "@/types/purchasing";

type Values = Omit<SupplierPayload, "paymentTermsDays"> & { paymentTermsDays: number | string; isActive: boolean };

export default function SupplierFormModal({ supplier, onClose, onSaved }: { supplier?: Supplier; onClose: () => void; onSaved: () => void }) {
  const form = useForm<Values>({
    initialValues: {
      name: supplier?.name ?? "",
      kraPin: supplier?.kraPin ?? "",
      contactPerson: supplier?.contactPerson ?? "",
      phone: supplier?.phone ?? "",
      email: supplier?.email ?? "",
      address: supplier?.address ?? "",
      paymentTermsDays: supplier?.paymentTermsDays ?? 30,
      paymentDetails: supplier?.paymentDetails ?? "",
      notes: supplier?.notes ?? "",
      isActive: supplier?.isActive ?? true,
    },
    validate: {
      name: isNotEmpty("Enter the supplier's name"),
      kraPin: (v) => (!v || /^[A-Za-z]\d{9}[A-Za-z]$/.test(v.trim()) ? null : "KRA PIN looks like P051234567X"),
    },
  });

  const { mutate, pending } = useApiMutation(
    (values: Values) => {
      const blankToNull = (v: string | null) => (v && v.trim() ? v.trim() : null);
      const payload: SupplierPayload = {
        name: values.name.trim(),
        kraPin: blankToNull(values.kraPin),
        contactPerson: blankToNull(values.contactPerson),
        phone: blankToNull(values.phone),
        email: blankToNull(values.email),
        address: blankToNull(values.address),
        paymentTermsDays: Number(values.paymentTermsDays) || 0,
        paymentDetails: blankToNull(values.paymentDetails),
        notes: blankToNull(values.notes),
        isActive: values.isActive,
      };
      return supplier ? purchasingApi.updateSupplier(supplier.id, payload) : purchasingApi.createSupplier(payload);
    },
    { successMessage: supplier ? "Supplier updated." : "Supplier added.", onValidationError: (e) => form.setErrors(e), onSuccess: onSaved },
  );

  return (
    <Modal opened onClose={onClose} title={supplier ? `Edit ${supplier.name}` : "Add supplier"} size="lg">
      <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
        <Stack>
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <TextInput label="Supplier name" required data-autofocus {...form.getInputProps("name")} />
            <TextInput label="KRA PIN" placeholder="P051234567X" {...form.getInputProps("kraPin")} />
            <TextInput label="Contact person" {...form.getInputProps("contactPerson")} />
            <TextInput label="Phone" {...form.getInputProps("phone")} />
            <TextInput label="Email" {...form.getInputProps("email")} />
            <NumberInput label="Payment terms (days)" min={0} max={365} {...form.getInputProps("paymentTermsDays")} />
          </SimpleGrid>
          <TextInput label="Address" {...form.getInputProps("address")} />
          <Textarea label="Payment details" description="Bank account or M-PESA paybill for paying this supplier" autosize minRows={2} {...form.getInputProps("paymentDetails")} />
          <Textarea label="Notes" autosize minRows={2} {...form.getInputProps("notes")} />
          {supplier && <Switch label="Active" {...form.getInputProps("isActive", { type: "checkbox" })} />}
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              Save
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
