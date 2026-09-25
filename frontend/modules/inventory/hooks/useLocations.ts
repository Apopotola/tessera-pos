import type { ComboboxItemGroup } from "@mantine/core";
import { useMemo } from "react";
import { inventoryApi } from "@/api";
import { useApiQuery } from "@/hooks/useApiQuery";
import type { StockLocation } from "@/types/inventory";

const fetchLocations = () => inventoryApi.locations();

/** Stock locations the user can work with, grouped by branch for Select inputs. */
export function useLocations() {
  const query = useApiQuery(fetchLocations);

  const options = useMemo<ComboboxItemGroup[]>(() => {
    const groups = new Map<string, { value: string; label: string }[]>();
    for (const location of query.data ?? []) {
      const key = location.branchCode ?? String(location.branchId);
      groups.set(key, [...(groups.get(key) ?? []), { value: String(location.id), label: `${location.name} (${key})` }]);
    }
    return [...groups].map(([group, items]) => ({ group, items }));
  }, [query.data]);

  const byId = useMemo(() => new Map<number, StockLocation>((query.data ?? []).map((l) => [l.id, l])), [query.data]);

  return { ...query, options, byId };
}
