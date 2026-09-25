"use client";

import { Button, Group, Modal, MultiSelect, PasswordInput, Select, SimpleGrid, Stack, Switch, TextInput } from "@mantine/core";
import { isEmail, isNotEmpty, useForm } from "@mantine/form";
import { usersApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import type { Branch } from "@/types/organisation";
import type { ManagedUser, UserPayload } from "@/types/users";

interface UserFormValues {
  name: string;
  email: string;
  phone: string;
  role: string | null;
  branchIds: string[];
  password: string;
  isActive: boolean;
}

interface UserFormModalProps {
  user?: ManagedUser;
  roles: string[];
  branches: Branch[];
  onClose: () => void;
  onSaved: () => void;
}

export default function UserFormModal({ user, roles, branches, onClose, onSaved }: UserFormModalProps) {
  const editing = Boolean(user);

  const form = useForm<UserFormValues>({
    initialValues: {
      name: user?.name ?? "",
      email: user?.email ?? "",
      phone: user?.phone ?? "",
      role: user?.role ?? "Cashier",
      branchIds: (user?.branchIds ?? (branches.length === 1 ? [branches[0].id] : [])).map(String),
      password: "",
      isActive: user?.isActive ?? true,
    },
    validate: {
      name: isNotEmpty("Enter the person's name"),
      email: isEmail("Enter a valid email"),
      role: isNotEmpty("Choose a role"),
      password: (v) => (v && v.length < 8 ? "At least 8 characters, or leave empty for till-only staff" : null),
    },
  });

  const { mutate, pending } = useApiMutation(
    (values: UserFormValues) => {
      const payload: UserPayload = {
        name: values.name.trim(),
        email: values.email.trim(),
        phone: values.phone.trim() || null,
        role: values.role ?? "",
        branchIds: values.branchIds.map(Number),
        isActive: values.isActive,
      };
      return user ? usersApi.update(user.id, payload) : usersApi.create({ ...payload, password: values.password || null });
    },
    {
      successMessage: editing ? "User updated." : "User added.",
      // branchIds.0 → branchIds (field-level message on the multiselect)
      onValidationError: (errors) =>
        form.setErrors(Object.fromEntries(Object.entries(errors).map(([k, v]) => [k.replace(/^branchIds\.\d+$/, "branchIds"), v]))),
      onSuccess: onSaved,
    },
  );

  return (
    <Modal opened onClose={onClose} title={editing ? `Edit ${user?.name}` : "Add user"} size="lg">
      <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
        <Stack>
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <TextInput label="Full name" required data-autofocus {...form.getInputProps("name")} />
            <Select label="Role" data={roles} required allowDeselect={false} {...form.getInputProps("role")} />
            <TextInput label="Email" required {...form.getInputProps("email")} />
            <TextInput label="Phone" placeholder="0712 345 678" description="Can be used to sign in" {...form.getInputProps("phone")} />
          </SimpleGrid>
          <MultiSelect
            label="Branches"
            description="Where this person can work. Owners and accountants see all branches."
            data={branches.map((b) => ({ value: String(b.id), label: `${b.code} · ${b.name}` }))}
            {...form.getInputProps("branchIds")}
          />
          {!editing && (
            <PasswordInput
              label="Back-office password"
              description="Leave empty for cashiers who only use the till PIN. They must change it on first sign-in."
              {...form.getInputProps("password")}
            />
          )}
          {editing && <Switch label="Account active" {...form.getInputProps("isActive", { type: "checkbox" })} />}
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              {editing ? "Save changes" : "Add user"}
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
