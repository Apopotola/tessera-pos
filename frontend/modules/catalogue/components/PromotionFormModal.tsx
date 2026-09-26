"use client";

import { ActionIcon, Alert, Button, Chip, Group, Modal, MultiSelect, NumberInput, SegmentedControl, Select, SimpleGrid, Stack, Text, TextInput } from "@mantine/core";
import { isNotEmpty, useForm } from "@mantine/form";
import { IconX } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { catalogueApi, organisationApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import { promotionOffer } from "@/modules/catalogue/promotions";
import VariantPicker, { type PickedVariant } from "@/modules/inventory/components/VariantPicker";
import type { Promotion, PromotionPayload } from "@/types/catalogue";
import { PERMISSIONS } from "@/types/permissions";
import { optionalKesToCents } from "@/utils/money";

const WEEKDAYS = [
  { value: "1", label: "Mon" },
  { value: "2", label: "Tue" },
  { value: "3", label: "Wed" },
  { value: "4", label: "Thu" },
  { value: "5", label: "Fri" },
  { value: "6", label: "Sat" },
  { value: "7", label: "Sun" },
];

interface FormValues {
  name: string;
  discountType: "percent" | "amount";
  percent: number | string;
  amountKes: number | string;
  minQuantity: number | string;
  unit: "bottle" | "tot" | "any";
  startsOn: string;
  endsOn: string;
  weekdays: string[];
  timeFrom: string;
  timeTo: string;
  branchIds: string[];
  categoryIds: string[];
  brandIds: string[];
}

/** Set up a promotion; a manager's goes to the owner for approval, the owner's applies as scheduled. */
export default function PromotionFormModal({ onClose, onSaved }: { onClose: () => void; onSaved: (promotion: Promotion) => void }) {
  const { can } = usePermissions();
  const [items, setItems] = useState<PickedVariant[]>([]);
  const fetchCategories = useCallback(() => catalogueApi.categories(), []);
  const fetchBrands = useCallback(() => catalogueApi.brands(), []);
  const fetchBranches = useCallback(() => organisationApi.branches(), []);
  const categories = useApiQuery(fetchCategories).data ?? [];
  const brands = useApiQuery(fetchBrands).data ?? [];
  const branches = useApiQuery(fetchBranches).data ?? [];

  const form = useForm<FormValues>({
    initialValues: {
      name: "",
      discountType: "percent",
      percent: 10,
      amountKes: "",
      minQuantity: 1,
      unit: "any",
      startsOn: dayjs().format("YYYY-MM-DD"),
      endsOn: dayjs().add(1, "month").format("YYYY-MM-DD"),
      weekdays: [],
      timeFrom: "",
      timeTo: "",
      branchIds: [],
      categoryIds: [],
      brandIds: [],
    },
    validate: {
      name: isNotEmpty("Give it a name the cashier and receipt will show"),
      percent: (v, values) => (values.discountType === "percent" && !(Number(v) > 0 && Number(v) <= 90) ? "Between 1% and 90%" : null),
      amountKes: (v, values) => (values.discountType === "amount" && !(optionalKesToCents(v) ?? 0) ? "Enter the amount off each item" : null),
      endsOn: (v, values) => (v < values.startsOn ? "Ends before it starts" : null),
      timeTo: (v, values) => (Boolean(v) !== Boolean(values.timeFrom) ? "Set both times, or neither" : null),
    },
  });

  const payload = (v: FormValues): PromotionPayload => ({
    name: v.name.trim(),
    discountType: v.discountType,
    discountValue: v.discountType === "percent" ? Math.round(Number(v.percent) * 100) : (optionalKesToCents(v.amountKes) ?? 0),
    minQuantity: Number(v.minQuantity) || 1,
    unit: v.unit,
    startsOn: v.startsOn,
    endsOn: v.endsOn,
    weekdays: v.weekdays.length ? v.weekdays.map(Number) : null,
    timeFrom: v.timeFrom || null,
    timeTo: v.timeTo || null,
    branchIds: v.branchIds.length ? v.branchIds.map(Number) : null,
    categoryIds: v.categoryIds.map(Number),
    brandIds: v.brandIds.map(Number),
    variantIds: items.map((i) => i.id),
  });

  const { mutate, pending } = useApiMutation((v: FormValues) => catalogueApi.createPromotion(payload(v)), {
    successMessage: (p) => (p.status === "pending" ? "Sent to the owner for approval." : "Promotion approved and scheduled."),
    onValidationError: form.setErrors,
    onSuccess: onSaved,
  });
  const v = form.values;
  const noTargets = !v.categoryIds.length && !v.brandIds.length && !items.length;

  return (
    <Modal opened onClose={onClose} title="New promotion" size="lg" centered>
      <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
        <Stack>
          <TextInput label="Name" placeholder="e.g. Buy 6 wine, 10% off" data-autofocus {...form.getInputProps("name")} />
          <SimpleGrid cols={{ base: 1, sm: 3 }}>
            <Stack gap={4}>
              <Text size="sm" fw={500}>
                Discount
              </Text>
              <SegmentedControl data={[{ value: "percent", label: "% off" }, { value: "amount", label: "KES off each" }]} {...form.getInputProps("discountType")} />
            </Stack>
            {v.discountType === "percent" ? (
              <NumberInput label="Percent off" min={1} max={90} decimalScale={1} suffix="%" {...form.getInputProps("percent")} />
            ) : (
              <NumberInput label="KES off each item" min={0} decimalScale={2} thousandSeparator="," {...form.getInputProps("amountKes")} />
            )}
            <NumberInput label="Minimum quantity" description="Across all matching items" min={1} allowDecimal={false} {...form.getInputProps("minQuantity")} />
          </SimpleGrid>

          <Select
            label="Sold as"
            data={[
              { value: "any", label: "Bottles and tots" },
              { value: "bottle", label: "Bottles only" },
              { value: "tot", label: "Tots only" },
            ]}
            allowDeselect={false}
            {...form.getInputProps("unit")}
          />

          <Stack gap={6}>
            <Text size="sm" fw={500}>
              Applies to <Text span size="xs" c="dimmed">(nothing chosen = every item)</Text>
            </Text>
            <SimpleGrid cols={{ base: 1, sm: 2 }}>
              <MultiSelect placeholder="Categories" data={categories.map((c) => ({ value: String(c.id), label: c.name }))} searchable clearable {...form.getInputProps("categoryIds")} />
              <MultiSelect placeholder="Brands" data={brands.map((b) => ({ value: String(b.id), label: b.name }))} searchable clearable {...form.getInputProps("brandIds")} />
            </SimpleGrid>
            {items.map((item) => (
              <Group key={item.id} justify="space-between" wrap="nowrap">
                <Text size="sm">{item.label}</Text>
                <ActionIcon variant="subtle" color="red" aria-label="Remove item" onClick={() => setItems((list) => list.filter((i) => i.id !== item.id))}>
                  <IconX size={16} />
                </ActionIcon>
              </Group>
            ))}
            <VariantPicker value={null} placeholder="Add a specific item" onChange={(picked) => picked && !items.some((i) => i.id === picked.id) && setItems((list) => [...list, picked])} />
          </Stack>

          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <TextInput label="Starts" type="date" min={dayjs().format("YYYY-MM-DD")} {...form.getInputProps("startsOn")} />
            <TextInput label="Ends" type="date" min={v.startsOn} {...form.getInputProps("endsOn")} />
          </SimpleGrid>
          <Stack gap={4}>
            <Text size="sm" fw={500}>
              Days <Text span size="xs" c="dimmed">(none = every day)</Text>
            </Text>
            <Chip.Group multiple value={v.weekdays} onChange={(days) => form.setFieldValue("weekdays", days)}>
              <Group gap={6}>
                {WEEKDAYS.map((d) => (
                  <Chip key={d.value} value={d.value} size="sm">
                    {d.label}
                  </Chip>
                ))}
              </Group>
            </Chip.Group>
          </Stack>
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <TextInput label="Happy hour from" description="Optional" type="time" {...form.getInputProps("timeFrom")} />
            <TextInput label="Until" description="Can run past midnight" type="time" {...form.getInputProps("timeTo")} />
          </SimpleGrid>
          <MultiSelect label="Branches" placeholder="Every branch" data={branches.map((b) => ({ value: String(b.id), label: b.name }))} clearable {...form.getInputProps("branchIds")} />

          <Alert color={noTargets ? "yellow" : "tessera"} variant="light">
            {promotionOffer({ discountType: v.discountType, discountValue: v.discountType === "percent" ? Number(v.percent) * 100 : (optionalKesToCents(v.amountKes) ?? 0), minQuantity: Number(v.minQuantity) || 1, unit: v.unit })}
            {noTargets ? " on every item in the shop." : "."}
            {!can(PERMISSIONS.PROMOTIONS_APPROVE) && " It goes to the owner for approval before the till applies it."}
          </Alert>

          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              {can(PERMISSIONS.PROMOTIONS_APPROVE) ? "Save promotion" : "Send for approval"}
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
