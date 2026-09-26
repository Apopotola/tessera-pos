"use client";

import { Badge, Group, Modal, Stack, Text, TextInput } from "@mantine/core";
import { IconBarcode, IconSearch } from "@tabler/icons-react";
import { useState } from "react";
import { ApiError, salesApi } from "@/api";
import { looksLikeBarcode } from "@/modules/till/cart";
import type { TillItem } from "@/types/sales";
import { formatKes } from "@/utils/money";

/** Quick button (Settings → Sales screen): scan or type to see a price and stock without touching the sale. */
export default function PriceCheckModal({ onClose }: { onClose: () => void }) {
  const [query, setQuery] = useState("");
  const [items, setItems] = useState<TillItem[] | null>(null);
  const [error, setError] = useState<string | null>(null);

  const check = async () => {
    const term = query.trim();
    if (term.length < 2) return;
    setError(null);
    try {
      setItems(looksLikeBarcode(term) ? [(await salesApi.scan(term)).item] : await salesApi.searchItems(term));
    } catch (e) {
      setItems([]);
      setError(e instanceof ApiError ? e.message : "Could not check the price.");
    }
    setQuery("");
  };

  return (
    <Modal opened onClose={onClose} title="Price check" centered>
      <Stack>
        <TextInput
          size="lg"
          placeholder="Scan a barcode or type a name"
          leftSection={looksLikeBarcode(query) ? <IconBarcode size={20} /> : <IconSearch size={20} />}
          value={query}
          onChange={(e) => setQuery(e.currentTarget.value)}
          onKeyDown={(e) => e.key === "Enter" && void check()}
          data-autofocus
        />
        {error && (
          <Text c="red" size="sm">
            {error}
          </Text>
        )}
        {items?.length === 0 && !error && (
          <Text c="dimmed" size="sm">
            No matching item.
          </Text>
        )}
        {items?.map((item) => (
          <Group key={item.variantId} justify="space-between" wrap="nowrap">
            <div>
              <Text fw={600}>{item.displayName}</Text>
              <Text size="xs" c="dimmed" ff="monospace">
                {item.sku}
              </Text>
            </div>
            <Stack gap={2} align="flex-end">
              <Text fw={700} fz="lg">
                {item.priceCents == null ? "No price" : formatKes(item.priceCents)}
              </Text>
              <Badge variant="light" color={item.onFloor > 0 ? "gray" : "red"}>
                {item.onFloor} on floor
              </Badge>
            </Stack>
          </Group>
        ))}
      </Stack>
    </Modal>
  );
}
