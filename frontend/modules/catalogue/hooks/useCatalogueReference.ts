import type { ComboboxItem, ComboboxItemGroup } from "@mantine/core";
import { useMemo } from "react";
import { catalogueApi } from "@/api";
import { useApiQuery } from "@/hooks/useApiQuery";
import type { Category } from "@/types/catalogue";

// Module-level fetchers are stable, so useApiQuery loads each once per mount.
const fetchBrands = () => catalogueApi.brands();
const fetchCategories = () => catalogueApi.categories();
const fetchTaxRates = () => catalogueApi.taxRates();

/** Brand / category / tax-rate lists and ready-made Select options. */
export function useCatalogueReference() {
  const brands = useApiQuery(fetchBrands);
  const categories = useApiQuery(fetchCategories);
  const taxRates = useApiQuery(fetchTaxRates);

  const brandOptions = useMemo<ComboboxItem[]>(
    () => (brands.data ?? []).filter((b) => b.isActive).map((b) => ({ value: String(b.id), label: b.name })),
    [brands.data],
  );

  const categoryOptions = useMemo(() => categoryGroups(categories.data ?? []), [categories.data]);

  const taxRateOptions = useMemo<ComboboxItem[]>(
    () => (taxRates.data ?? []).map((t) => ({ value: String(t.id), label: `${t.code} — ${t.name}` })),
    [taxRates.data],
  );

  return {
    brands,
    categories,
    taxRates,
    brandOptions,
    categoryOptions,
    taxRateOptions,
    loading: brands.loading || categories.loading || taxRates.loading,
  };
}

/** Parent categories become groups; the parent itself is selectable as "<name> (general)". */
export function categoryGroups(tree: Category[]): ComboboxItemGroup<ComboboxItem>[] {
  return tree
    .filter((parent) => parent.isActive)
    .map((parent) => ({
      group: parent.name,
      items: [
        { value: String(parent.id), label: `${parent.name} (general)` },
        ...(parent.children ?? []).filter((c) => c.isActive).map((c) => ({ value: String(c.id), label: c.name })),
      ],
    }));
}
