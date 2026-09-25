import { useMemo } from "react";
import { purchasingApi } from "@/api";
import { useApiQuery } from "@/hooks/useApiQuery";

const fetchSuppliers = () => purchasingApi.suppliers();

/** Supplier list plus Select options (active suppliers only). */
export function useSuppliers() {
  const query = useApiQuery(fetchSuppliers);
  const options = useMemo(() => (query.data ?? []).filter((s) => s.isActive).map((s) => ({ value: String(s.id), label: s.name })), [query.data]);

  return { ...query, options };
}
