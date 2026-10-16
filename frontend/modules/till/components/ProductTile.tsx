"use client";

import { Group, Image, Text, UnstyledButton } from "@mantine/core";
import { IconBottle, IconGlassFull } from "@tabler/icons-react";
import { useState } from "react";
import type { SaleUnit, TillItem } from "@/types/sales";
import { formatKes } from "@/utils/money";
import classes from "../Till.module.css";

interface ProductTileProps {
  item: TillItem;
  onAdd: (item: TillItem, quantity?: number, unit?: SaleUnit) => void;
}

/** One product on the till grid: photo, name, price and shop-floor stock; poured items get a tot button. */
export default function ProductTile({ item, onAdd }: ProductTileProps) {
  return (
    <div className={classes.item} data-disabled={item.priceCents == null || undefined}>
      <UnstyledButton className={classes.itemMain} onClick={() => onAdd(item)}>
        <ProductPhoto url={item.imageUrl} />
        <div className={classes.itemText}>
          <Text fw={600} size="sm" lh={1.25} lineClamp={2}>
            {item.displayName}
          </Text>
          <Text c="dimmed" size="xs" ff="monospace">
            {item.sku}
          </Text>
        </div>
        <Group className={classes.itemFoot} justify="space-between" gap={6} wrap="nowrap">
          <Text fw={700} c="tessera.7">
            {item.priceCents == null ? "No price" : formatKes(item.priceCents)}
          </Text>
          <Text size="xs" fw={600} c={item.onFloor > 0 ? "dimmed" : "red.7"}>
            {item.onFloor > 0 ? `${item.onFloor} left` : "Out of stock"}
          </Text>
        </Group>
      </UnstyledButton>
      {item.totMl && item.totPriceCents != null && (
        <UnstyledButton className={classes.totButton} onClick={() => onAdd(item, 1, "tot")}>
          <Group justify="space-between" wrap="nowrap" gap={6}>
            <Group gap={6} wrap="nowrap">
              <IconGlassFull size={16} />
              <Text size="sm" fw={600}>
                Tot {item.totMl}ml
              </Text>
            </Group>
            <Text size="sm" fw={700}>
              {formatKes(item.totPriceCents)}
            </Text>
          </Group>
          <Text size="xs" c="dimmed">
            {item.openBottleMl == null ? "Opens a new bottle" : `${item.openBottleMl}ml left in open bottle`}
          </Text>
        </UnstyledButton>
      )}
    </div>
  );
}

/**
 * The product photo, or a plain bottle when there is none. Photos are not in the offline copy:
 * one the browser has not kept falls back to the bottle when the till is offline.
 */
function ProductPhoto({ url }: { url: string | null }) {
  const [failed, setFailed] = useState<string | null>(null);
  const show = url !== null && failed !== url;

  return (
    <div className={classes.itemPhoto}>
      {show ? (
        <Image src={url} alt="" loading="lazy" decoding="async" fit="contain" h="100%" onError={() => setFailed(url)} />
      ) : (
        <IconBottle size={34} stroke={1.3} />
      )}
    </div>
  );
}
