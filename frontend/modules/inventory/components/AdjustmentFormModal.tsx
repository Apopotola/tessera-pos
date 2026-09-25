"use client";

import { Alert, Button, Group, Modal, Select, SimpleGrid, Stack, Textarea } from "@mantine/core";
import { useForm } from "@mantine/form";
import { IconInfoCircle } from "@tabler/icons-react";
import { useMemo } from "react";
import { inventoryApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import { usePermissions } from "@/hooks/usePermissions";
import LineItemsEditor, { newLine, type LineRow } from "@/modules/inventory/components/LineItemsEditor";
import { ADJUSTMENT_TYPES, STAGES } from "@/modules/inventory/constants";
import { useLocations } from "@/modules/inventory/hooks/useLocations";
import type { AdjustmentStage, AdjustmentType } from "@/types/inventory";
import { PERMISSIONS } from "@/types/permissions";
import { optionalKesToCents } from "@/utils/money";

interface Values {
  type: AdjustmentType;
  locationId: string | null;
  stage: AdjustmentStage | null;
  reason: string;
  lines: LineRow[];
}

const LOSS_TYPES: AdjustmentType[] = ["breakage", "damaged", "expired"];

/** Report a breakage or loss, or record found / opening stock. Nothing moves until approved. */
export default function AdjustmentFormModal({ onClose, onSaved, initialType = "breakage" }: { onClose: () => void; onSaved: () => void; initialType?: AdjustmentType }) {
  const { can } = usePermissions();
  const locations = useLocations();
  const canAdjust = can(PERMISSIONS.INVENTORY_ADJUST);

  // Loss reports are open to more roles than stock corrections.
  const typeOptions = useMemo(
    () => ADJUSTMENT_TYPES.filter((t) => canAdjust || LOSS_TYPES.includes(t.value)).map((t) => ({ value: t.value, label: `${t.label} — ${t.hint}` })),
    [canAdjust],
  );

  const form = useForm<Values>({
    initialValues: { type: initialType, locationId: null, stage: null, reason: "", lines: [newLine()] },
    validate: {
      locationId: (v) => (v ? null : "Choose where the stock is"),
      reason: (v) => (v.trim() ? null : "Say what happened (kept in the audit log)"),
    },
  });

  const { type } = form.values;
  const isLoss = !["opening", "found"].includes(type);
  const withCost = type === "opening" || type === "found";

  const { mutate, pending } = useApiMutation(
    (values: Values) =>
      inventoryApi.createAdjustment({
        locationId: Number(values.locationId),
        type: values.type,
        stage: isLoss ? values.stage : null,
        reason: values.reason.trim(),
        lines: values.lines
          .filter((l) => l.variant)
          .map((l) => ({ variantId: l.variant!.id, quantity: Number(l.quantity), unitCostCents: withCost ? optionalKesToCents(l.unitCostKes) : null })),
      }),
    {
      successMessage: (adj) => `${adj.number} sent for approval.`,
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
    <Modal opened onClose={onClose} title="New stock adjustment" size="xl">
      <form onSubmit={form.onSubmit(submit)} noValidate>
        <Stack>
          <SimpleGrid cols={{ base: 1, sm: isLoss ? 3 : 2 }}>
            <Select label="What happened" data={typeOptions} allowDeselect={false} {...form.getInputProps("type")} />
            <Select label="Location" data={locations.options} required {...form.getInputProps("locationId")} />
            {isLoss && <Select label="Where it happened" placeholder="Optional" data={STAGES} clearable {...form.getInputProps("stage")} />}
          </SimpleGrid>
          <LineItemsEditor
            lines={form.values.lines}
            onChange={(lines) => form.setFieldValue("lines", lines)}
            withCost={withCost}
            costRequired={type === "opening"}
            errors={form.errors as Record<string, string>}
          />
          <Textarea label="Reason" required autosize minRows={2} placeholder="e.g. Dropped while shelving" {...form.getInputProps("reason")} />
          <Alert variant="light" icon={<IconInfoCircle size={18} />}>
            Stock does not change until a manager approves this. You cannot approve your own adjustment.
          </Alert>
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              Send for approval
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
