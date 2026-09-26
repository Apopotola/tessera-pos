"use client";

import { Button, NumberInput, Paper, Select, Stack, Text, TextInput, Title } from "@mantine/core";
import { useForm } from "@mantine/form";
import Link from "next/link";
import { useCallback, useEffect } from "react";
import { organisationApi } from "@/api";
import { brand } from "@/app/theme";
import Logo from "@/components/brand/Logo";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { logout } from "@/store/slices/authSlice";
import { PERMISSIONS } from "@/types/permissions";
import { kesToCents } from "@/utils/money";
import { setTillToken } from "@/utils/tillDevice";

interface SetupValues {
  branchId: string | null;
  name: string;
  description: string;
  floatKes: number | string;
}

/**
 * One-time device setup. A manager signs in, names the till, and the device receives
 * its token. The manager is then signed out so the till starts at the PIN screen.
 */
export default function TillSetup({ onPaired }: { onPaired: () => void }) {
  const dispatch = useAppDispatch();
  const status = useAppSelector((state) => state.auth.status);
  const { can } = usePermissions();
  const canSetUp = status === "authenticated" && can(PERMISSIONS.ORGANISATION_MANAGE);

  return (
    <div style={{ minHeight: "100vh", background: brand.navy, display: "flex", alignItems: "center", justifyContent: "center", padding: 24 }}>
      <Paper p="xl" radius="lg" w="100%" maw={460}>
        <Stack>
          <Logo tone="dark" />
          <Title order={2}>Set up this device as a till</Title>
          {canSetUp ? (
            <PairForm
              onPaired={async (token) => {
                setTillToken(token);
                await dispatch(logout());
                onPaired();
              }}
            />
          ) : (
            <>
              <Text size="sm" c="dimmed">
                This device is not a till yet. A manager or owner must sign in once to set it up. After that, cashiers sign in here with their PIN.
              </Text>
              <Button component={Link} href="/login?next=/till" size="md">
                Manager sign in
              </Button>
            </>
          )}
        </Stack>
      </Paper>
    </div>
  );
}

function PairForm({ onPaired }: { onPaired: (token: string) => Promise<void> }) {
  const fetchBranches = useCallback(() => organisationApi.branches(), []);
  const branches = useApiQuery(fetchBranches);

  const form = useForm<SetupValues>({
    initialValues: { branchId: null, name: "Till 1", description: "Main counter", floatKes: 5000 },
    validate: {
      branchId: (v) => (v ? null : "Choose the branch this till is in"),
      name: (v) => (v.trim() ? null : "Name the till, e.g. Till 1"),
    },
  });

  // A single-branch shop has nothing to choose.
  const onlyBranch = branches.data?.length === 1 ? String(branches.data[0].id) : null;
  const branchChosen = form.values.branchId !== null;
  useEffect(() => {
    // Only fills an empty field, so it runs once (form helpers are not stable references).
    if (onlyBranch && !branchChosen) form.setFieldValue("branchId", onlyBranch);
  }, [onlyBranch, branchChosen]); // eslint-disable-line react-hooks/exhaustive-deps

  const { mutate, pending } = useApiMutation(
    (values: SetupValues) =>
      organisationApi.pairTill({
        branchId: Number(values.branchId),
        name: values.name.trim(),
        description: values.description.trim() || null,
        defaultFloatCents: kesToCents(Number(values.floatKes) || 0),
      }),
    {
      successMessage: "This device is now a till.",
      onValidationError: (errors) => form.setErrors({ ...errors, floatKes: errors.defaultFloatCents }),
      onSuccess: ({ deviceToken }) => void onPaired(deviceToken),
    },
  );

  return (
    <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
      <Stack>
        <Select
          label="Branch"
          data={(branches.data ?? []).map((b) => ({ value: String(b.id), label: `${b.code} · ${b.name}` }))}
          required
          {...form.getInputProps("branchId")}
        />
        <TextInput label="Till name" required {...form.getInputProps("name")} />
        <TextInput label="Location" placeholder="Main counter" {...form.getInputProps("description")} />
        <NumberInput label="Default opening float (KES)" min={0} thousandSeparator="," {...form.getInputProps("floatKes")} />
        <Text size="xs" c="dimmed">
          Setting up a till that already exists disconnects its old device.
        </Text>
        <Button type="submit" loading={pending}>
          Set up till
        </Button>
      </Stack>
    </form>
  );
}
