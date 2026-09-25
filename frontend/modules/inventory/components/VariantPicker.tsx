"use client";

import { Loader, Select } from "@mantine/core";
import { useDebouncedValue } from "@mantine/hooks";
import { useEffect, useState } from "react";
import { catalogueApi } from "@/api";

export interface PickedVariant {
  id: number;
  label: string;
}

interface VariantPickerProps {
  value: PickedVariant | null;
  onChange: (variant: PickedVariant | null) => void;
  label?: string;
  error?: string;
  placeholder?: string;
}

/**
 * Search the catalogue by name, brand, SKU or scanned barcode and pick one size.
 * Options come from the server as you type, so large catalogues stay fast.
 */
export default function VariantPicker({ value, onChange, label, error, placeholder = "Search or scan an item" }: VariantPickerProps) {
  const [search, setSearch] = useState("");
  const [debounced] = useDebouncedValue(search.trim(), 300);
  const [options, setOptions] = useState<PickedVariant[]>([]);
  const [loading, setLoading] = useState(false);

  useEffect(() => {
    if (debounced.length < 2 || debounced === value?.label) return;
    let active = true;
    setLoading(true); // eslint-disable-line react-hooks/set-state-in-effect -- request status for the search box
    catalogueApi
      .products({ search: debounced, perPage: 15 })
      .then((page) => {
        if (!active) return;
        setOptions(
          page.items.flatMap((p) =>
            (p.variants ?? []).filter((v) => v.isActive).map((v) => ({ id: v.id, label: `${p.name} ${v.volumeLabel} · ${v.sku}` })),
          ),
        );
      })
      .catch(() => active && setOptions([]))
      .finally(() => active && setLoading(false));
    return () => {
      active = false;
    };
  }, [debounced, value?.label]);

  const data = [...(value && !options.some((o) => o.id === value.id) ? [value] : []), ...options].map((o) => ({ value: String(o.id), label: o.label }));

  return (
    <Select
      label={label}
      placeholder={placeholder}
      searchable
      clearable
      data={data}
      value={value ? String(value.id) : null}
      searchValue={search}
      onSearchChange={setSearch}
      onChange={(id) => {
        const picked = data.find((d) => d.value === id);
        onChange(picked ? { id: Number(picked.value), label: picked.label } : null);
      }}
      filter={({ options: all }) => all}
      nothingFoundMessage={debounced.length < 2 ? "Type at least 2 characters" : loading ? "Searching…" : "No matching item"}
      rightSection={loading ? <Loader size="xs" /> : undefined}
      error={error}
    />
  );
}
