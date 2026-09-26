"use client";

import { Badge, Button, Group, Loader, Modal, Stack, Text, TextInput, UnstyledButton } from "@mantine/core";
import { useDebouncedValue } from "@mantine/hooks";
import { IconSearch } from "@tabler/icons-react";
import { useEffect, useState } from "react";
import { customersApi } from "@/api";
import type { TillCustomer } from "@/types/customers";
import { formatKes } from "@/utils/money";

interface CustomerPickerProps {
  current: TillCustomer | null;
  /** Offline: search this saved list instead of the server. */
  localCustomers?: TillCustomer[] | null;
  onClose: () => void;
  onPick: (customer: TillCustomer | null) => void;
}

/**
 * Attach a registered customer to the sale (wholesale prices, KRA PIN on the invoice).
 * Most sales stay walk-in. New customers are registered by a manager in the back office.
 */
export default function CustomerPicker({ current, localCustomers = null, onClose, onPick }: CustomerPickerProps) {
  const [search, setSearch] = useState("");
  const [debounced] = useDebouncedValue(search.trim(), 250);
  const [results, setResults] = useState<TillCustomer[]>([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (debounced.length < 2) {
      setResults([]); // eslint-disable-line react-hooks/set-state-in-effect
      return;
    }
    if (localCustomers) {
      const term = debounced.toLowerCase();
      setResults(localCustomers.filter((c) => c.name.toLowerCase().includes(term) || (c.kraPin ?? "").toLowerCase().includes(term)).slice(0, 15));
      return;
    }
    let active = true;
    setLoading(true);
    customersApi
      .tillSearch(debounced)
      .then((list) => active && setResults(list))
      .catch(() => active && setResults([]))
      .finally(() => active && setLoading(false));
    return () => {
      active = false;
    };
  }, [debounced, localCustomers]);

  return (
    <Modal opened onClose={onClose} title="Customer" centered>
      <Stack gap="sm">
        <TextInput
          placeholder="Business name or KRA PIN"
          leftSection={<IconSearch size={16} />}
          rightSection={loading ? <Loader size="xs" /> : null}
          value={search}
          onChange={(e) => setSearch(e.currentTarget.value)}
          data-autofocus
        />
        {results.map((c) => (
          <UnstyledButton
            key={c.id}
            onClick={() => onPick(c)}
            style={{ padding: "10px 12px", borderRadius: 10, border: `1px solid ${current?.id === c.id ? "var(--mantine-color-tessera-6)" : "var(--mantine-color-gray-3)"}` }}
          >
            <Group justify="space-between" wrap="nowrap">
              <div>
                <Text fw={600}>{c.name}</Text>
                <Text size="xs" c="dimmed" ff="monospace">
                  {c.kraPin ?? "No KRA PIN"}
                </Text>
              </div>
              <Group gap={6} wrap="nowrap">
                {c.isWholesale && <Badge color="tessera">Wholesale</Badge>}
                {c.creditAvailableCents != null && (
                  <Badge color={c.creditAvailableCents > 0 ? "green" : "red"} variant="light">
                    Account · {formatKes(c.creditAvailableCents)} left
                  </Badge>
                )}
              </Group>
            </Group>
          </UnstyledButton>
        ))}
        {debounced.length >= 2 && !loading && results.length === 0 && (
          <Text size="sm" c="dimmed">
            No registered customer found. Ask a manager to register them in the back office.
          </Text>
        )}
        <Group justify="space-between">
          <Button variant="subtle" onClick={() => onPick(null)} disabled={!current}>
            Walk-in customer
          </Button>
          <Button variant="default" onClick={onClose}>
            Close
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}
