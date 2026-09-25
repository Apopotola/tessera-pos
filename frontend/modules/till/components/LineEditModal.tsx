"use client";

import { Button, Group, Modal, NumberInput, Stack, Text } from "@mantine/core";
import { IconTrash } from "@tabler/icons-react";
import { useState } from "react";
import { type CartLine, lineGross, lineName } from "@/modules/till/cart";
import { formatKes, kesToCents } from "@/utils/money";

interface LineEditModalProps {
  line: CartLine;
  canDiscount: boolean;
  discountLimitPercent: number;
  onClose: () => void;
  onSave: (line: CartLine) => void;
  onRemove: () => void;
}

/** Change quantity, price or discount on one cart line. Overrides and big discounts ask a manager on save. */
export default function LineEditModal({ line, canDiscount, discountLimitPercent, onClose, onSave, onRemove }: LineEditModalProps) {
  const [quantity, setQuantity] = useState<number | string>(line.quantity);
  const [priceKes, setPriceKes] = useState<number | string>(line.unitPriceCents / 100);
  const [discountKes, setDiscountKes] = useState<number | string>(line.discountCents / 100);

  const qty = Math.max(1, Math.floor(Number(quantity) || 1));
  const unitPrice = kesToCents(Number(priceKes) || 0);
  const discount = kesToCents(Number(discountKes) || 0);
  const draft: CartLine = { ...line, quantity: qty, unitPriceCents: unitPrice, discountCents: discount };
  const gross = lineGross(draft);
  const invalid = unitPrice <= 0 || discount < 0 || discount > gross;

  const save = () => {
    if (invalid) return;
    // Any change to price or discount needs a fresh approval.
    const changed = unitPrice !== line.unitPriceCents || discount !== line.discountCents;
    onSave(changed ? { ...draft, approvalToken: null, approvedBy: null } : draft);
  };

  return (
    <Modal opened onClose={onClose} title={lineName(line)} centered>
      <Stack>
        <Text size="sm" c="dimmed">
          List price {formatKes(line.listPriceCents)}
          {line.unit === "bottle" && ` · ${line.onFloor} on the shop floor`}
        </Text>
        <NumberInput label="Quantity" size="lg" min={1} max={10000} allowDecimal={false} value={quantity} onChange={setQuantity} data-autofocus />
        <NumberInput
          label="Price each (KES)"
          description="Changing the price needs a manager"
          min={0}
          decimalScale={2}
          thousandSeparator=","
          value={priceKes}
          onChange={setPriceKes}
        />
        {canDiscount && (
          <NumberInput
            label="Discount on this line (KES)"
            description={`Up to ${discountLimitPercent}% without a manager (${formatKes(Math.floor((gross * discountLimitPercent) / 100))})`}
            min={0}
            decimalScale={2}
            thousandSeparator=","
            value={discountKes}
            onChange={setDiscountKes}
            error={discount > gross ? "Discount is larger than the line" : null}
          />
        )}
        <Group justify="space-between">
          <Text fw={700}>Line total {formatKes(gross - discount)}</Text>
        </Group>
        <Group justify="space-between">
          <Button variant="subtle" color="red" leftSection={<IconTrash size={16} />} onClick={onRemove}>
            Remove item
          </Button>
          <Group>
            <Button variant="default" onClick={onClose}>
              Cancel
            </Button>
            <Button onClick={save} disabled={invalid}>
              Save
            </Button>
          </Group>
        </Group>
      </Stack>
    </Modal>
  );
}
