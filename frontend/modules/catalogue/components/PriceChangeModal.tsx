"use client";

import { Alert, Button, Group, Modal, NumberInput, SegmentedControl, Select, SimpleGrid, Stack, Text, Textarea } from "@mantine/core";
import { DateInput } from "@mantine/dates";
import { useForm } from "@mantine/form";
import { notifications } from "@mantine/notifications";
import { IconInfoCircle } from "@tabler/icons-react";
import { useCallback } from "react";
import { catalogueApi, organisationApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import type { PriceTier, Variant } from "@/types/catalogue";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes, kesToCents, optionalKesToCents } from "@/utils/money";

interface PriceValues {
  tier: PriceTier;
  branchId: string | null;
  priceKes: number | string;
  minPriceKes: number | string;
  effectiveFrom: string | null;
  reason: string;
}

interface PriceChangeModalProps {
  opened: boolean;
  onClose: () => void;
  variant: Variant;
  onSaved: () => void;
}

const ALL_BRANCHES = "all";

export default function PriceChangeModal({ opened, onClose, variant, onSaved }: PriceChangeModalProps) {
  const { can } = usePermissions();
  const appliesImmediately = can(PERMISSIONS.PRICES_APPROVE);
  const fetchBranches = useCallback(() => organisationApi.branches(), []);
  const branches = useApiQuery(fetchBranches);

  const form = useForm<PriceValues>({
    initialValues: { tier: "retail", branchId: ALL_BRANCHES, priceKes: "", minPriceKes: "", effectiveFrom: null, reason: "" },
    validate: {
      priceKes: (v) => (Number(v) > 0 ? null : "Enter the new price"),
      minPriceKes: (v, values) => (v !== "" && Number(v) > Number(values.priceKes) ? "Minimum cannot exceed the price" : null),
      reason: (v) => (v.trim() ? null : "Give a reason (kept in the audit log)"),
    },
  });

  const current = variant.currentPrices?.[form.values.tier] ?? null;

  const { mutate, pending } = useApiMutation(
    (values: PriceValues) =>
      catalogueApi.requestPrice(variant.id, {
        tier: values.tier,
        branchId: values.branchId && values.branchId !== ALL_BRANCHES ? Number(values.branchId) : null,
        priceCents: kesToCents(Number(values.priceKes)),
        minPriceCents: optionalKesToCents(values.minPriceKes),
        effectiveFrom: values.effectiveFrom,
        reason: values.reason.trim(),
      }),
    {
      successMessage: (result) => (result.price.status === "approved" ? "Price applied." : "Price change sent for approval."),
      onValidationError: (errors) =>
        form.setErrors(
          // priceCents → priceKes, minPriceCents → minPriceKes
          Object.fromEntries(Object.entries(errors).map(([k, v]) => [k.replace("PriceCents", "PriceKes").replace(/^priceCents$/, "priceKes"), v])),
        ),
      onSuccess: ({ warnings }) => {
        warnings.forEach((message) => notifications.show({ color: "yellow", title: "Check pricing", message }));
        form.reset();
        onSaved();
      },
    },
  );

  const branchOptions = [
    { value: ALL_BRANCHES, label: "All branches" },
    ...(branches.data ?? []).map((b) => ({ value: String(b.id), label: `${b.code} · ${b.name}` })),
  ];

  return (
    <Modal opened={opened} onClose={onClose} title={`Change price — ${variant.displayName}`} size="lg">
      <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
        <Stack>
          <SegmentedControl
            data={[
              { value: "retail", label: "Retail" },
              { value: "wholesale", label: "Wholesale" },
              ...(variant.totMl ? [{ value: "tot", label: `Tot (${variant.totMl}ml)` }] : []),
            ]}
            {...form.getInputProps("tier")}
          />
          <Text size="sm" c="dimmed">
            Current {form.values.tier} price (all branches): <b>{formatKes(current?.priceCents)}</b>
          </Text>
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <NumberInput label="New price (KES)" min={0} decimalScale={2} thousandSeparator="," required {...form.getInputProps("priceKes")} />
            <NumberInput
              label="Minimum price (KES)"
              description="Selling below this needs manager approval"
              min={0}
              decimalScale={2}
              thousandSeparator=","
              {...form.getInputProps("minPriceKes")}
            />
            <Select label="Applies to" data={branchOptions} allowDeselect={false} {...form.getInputProps("branchId")} />
            <DateInput label="Effective from" placeholder="Immediately" minDate={new Date()} clearable valueFormat="DD MMM YYYY" {...form.getInputProps("effectiveFrom")} />
          </SimpleGrid>
          <Textarea label="Reason" placeholder="e.g. Supplier price increase" required autosize minRows={2} {...form.getInputProps("reason")} />
          <Alert variant="light" color={appliesImmediately ? "blue" : "yellow"} icon={<IconInfoCircle size={18} />}>
            {appliesImmediately
              ? "You can approve prices, so this change applies as soon as it takes effect."
              : "This change needs approval from the owner before it applies."}
          </Alert>
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              {appliesImmediately ? "Apply price" : "Send for approval"}
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
