"use client";

import { Button, Group, Modal, SimpleGrid, Stack, Switch, Text, TextInput, Textarea } from "@mantine/core";
import { useForm } from "@mantine/form";
import { customersApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import type { Customer, CustomerPayload } from "@/types/customers";

interface CustomerFormModalProps {
  customer?: Customer;
  onClose: () => void;
  onSaved: (customer: Customer) => void;
}

interface Values {
  name: string;
  kraPin: string;
  isWholesale: boolean;
  contactName: string;
  phone: string;
  email: string;
  notes: string;
  isActive: boolean;
}

/** Register or edit a wholesale / B2B customer. Collect only what the business needs. */
export default function CustomerFormModal({ customer, onClose, onSaved }: CustomerFormModalProps) {
  const form = useForm<Values>({
    initialValues: {
      name: customer?.name ?? "",
      kraPin: customer?.kraPin ?? "",
      isWholesale: customer?.isWholesale ?? true,
      contactName: customer?.contactName ?? "",
      phone: customer?.phone ?? "",
      email: customer?.email ?? "",
      notes: customer?.notes ?? "",
      isActive: customer?.isActive ?? true,
    },
    validate: {
      name: (v) => (v.trim() ? null : "Enter the business name"),
      kraPin: (v) => (!v.trim() || /^[A-Za-z]\d{9}[A-Za-z]$/.test(v.trim()) ? null : "A KRA PIN looks like P051234567X"),
    },
  });

  const { mutate, pending } = useApiMutation(
    (values: Values) => {
      const payload: CustomerPayload = {
        name: values.name.trim(),
        kraPin: values.kraPin.trim().toUpperCase() || null,
        isWholesale: values.isWholesale,
        contactName: values.contactName.trim() || null,
        phone: values.phone.trim() || null,
        email: values.email.trim() || null,
        notes: values.notes.trim() || null,
        ...(customer ? { isActive: values.isActive } : {}),
      };
      return customer ? customersApi.update(customer.id, payload) : customersApi.create(payload);
    },
    { successMessage: customer ? "Customer updated." : "Customer registered.", onValidationError: form.setErrors, onSuccess: onSaved },
  );

  return (
    <Modal opened onClose={onClose} title={customer ? `Edit ${customer.name}` : "Register customer"} size="lg">
      <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
        <Stack>
          <Text size="sm" c="dimmed">
            Walk-in customers need no record. Register bars, restaurants and businesses that buy at wholesale prices or need their KRA PIN on the tax invoice.
          </Text>
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <TextInput label="Business name" required data-autofocus {...form.getInputProps("name")} />
            <TextInput label="KRA PIN" placeholder="P051234567X" description="Printed on their eTIMS invoices" {...form.getInputProps("kraPin")} />
          </SimpleGrid>
          <Switch label="Wholesale customer — pays wholesale prices at the till" {...form.getInputProps("isWholesale", { type: "checkbox" })} />
          <SimpleGrid cols={{ base: 1, sm: 3 }}>
            <TextInput label="Contact person" {...form.getInputProps("contactName")} />
            <TextInput label="Phone" placeholder="0712 345 678" inputMode="tel" {...form.getInputProps("phone")} />
            <TextInput label="Email" type="email" {...form.getInputProps("email")} />
          </SimpleGrid>
          <Textarea label="Notes" autosize minRows={2} {...form.getInputProps("notes")} />
          {customer && <Switch label="Active" {...form.getInputProps("isActive", { type: "checkbox" })} />}
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              {customer ? "Save" : "Register"}
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
