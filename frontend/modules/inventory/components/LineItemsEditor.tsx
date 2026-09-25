"use client";

import { ActionIcon, Button, Group, NumberInput, Stack, Text } from "@mantine/core";
import { randomId } from "@mantine/hooks";
import { IconPlus, IconTrash } from "@tabler/icons-react";
import VariantPicker, { type PickedVariant } from "@/modules/inventory/components/VariantPicker";

export interface LineRow {
  key: string;
  variant: PickedVariant | null;
  quantity: number | string;
  unitCostKes: number | string;
}

export const newLine = (): LineRow => ({ key: randomId(), variant: null, quantity: 1, unitCostKes: "" });

interface LineItemsEditorProps {
  lines: LineRow[];
  onChange: (lines: LineRow[]) => void;
  /** Show a unit cost column (opening / found stock). */
  withCost?: boolean;
  costRequired?: boolean;
  /** Overrides the cost column label, e.g. "Unit cost excl. VAT (KES)". */
  costLabel?: string;
  errors?: Record<string, string>;
}

/** Item + quantity rows shared by adjustment and transfer forms. */
export default function LineItemsEditor({ lines, onChange, withCost, costRequired, costLabel, errors = {} }: LineItemsEditorProps) {
  const update = (index: number, patch: Partial<LineRow>) => onChange(lines.map((line, i) => (i === index ? { ...line, ...patch } : line)));

  return (
    <Stack gap="xs">
      {lines.map((line, index) => (
        <Group key={line.key} align="flex-start" wrap="nowrap" gap="xs">
          <div style={{ flex: 1 }}>
            <VariantPicker
              label={index === 0 ? "Item" : undefined}
              value={line.variant}
              onChange={(variant) => update(index, { variant })}
              error={errors[`lines.${index}.variantId`]}
            />
          </div>
          <NumberInput
            label={index === 0 ? "Qty" : undefined}
            w={90}
            min={1}
            value={line.quantity}
            onChange={(quantity) => update(index, { quantity })}
            error={errors[`lines.${index}.quantity`]}
          />
          {withCost && (
            <NumberInput
              label={index === 0 ? (costLabel ?? `Unit cost (KES)${costRequired ? "" : " — optional"}`) : undefined}
              w={170}
              min={0}
              decimalScale={2}
              thousandSeparator=","
              value={line.unitCostKes}
              onChange={(unitCostKes) => update(index, { unitCostKes })}
              error={errors[`lines.${index}.unitCostCents`]}
            />
          )}
          <ActionIcon
            variant="subtle"
            color="red"
            mt={index === 0 ? 26 : 4}
            disabled={lines.length === 1}
            onClick={() => onChange(lines.filter((_, i) => i !== index))}
            aria-label="Remove line"
          >
            <IconTrash size={16} />
          </ActionIcon>
        </Group>
      ))}
      {errors.lines && (
        <Text c="red" size="sm">
          {errors.lines}
        </Text>
      )}
      <Button variant="light" size="xs" w="fit-content" leftSection={<IconPlus size={14} />} onClick={() => onChange([...lines, newLine()])}>
        Add item
      </Button>
    </Stack>
  );
}
