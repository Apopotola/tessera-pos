"use client";

import { Badge, Button, Group, Pagination, SegmentedControl, Select, Table, Text, TextInput } from "@mantine/core";
import { useDebouncedValue, useDisclosure } from "@mantine/hooks";
import { IconPlus, IconSearch } from "@tabler/icons-react";
import { useCallback, useState } from "react";
import { catalogueApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import StatusBadge from "@/components/shared/StatusBadge";
import WorkspacePage from "@/components/shared/WorkspacePage";
import QueryState from "@/components/shared/QueryState";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import ProductFormModal from "@/modules/catalogue/components/ProductFormModal";
import { useCatalogueReference } from "@/modules/catalogue/hooks/useCatalogueReference";
import { productDetailTab } from "@/modules/catalogue/routes";
import { useAppDispatch } from "@/store/hooks";
import { openTab } from "@/store/slices/tabsSlice";
import type { Product, ProductFilters } from "@/types/catalogue";
import { PERMISSIONS } from "@/types/permissions";

type StatusFilter = NonNullable<ProductFilters["status"]>;

export default function ProductsView({ title, tabId, section }: WorkspaceViewProps) {
  const dispatch = useAppDispatch();
  const { can } = usePermissions();
  const { brandOptions, categoryOptions } = useCatalogueReference();
  const [createOpened, createModal] = useDisclosure(false);

  const [search, setSearch] = useState("");
  const [debouncedSearch] = useDebouncedValue(search.trim(), 350);
  const [categoryId, setCategoryId] = useState<string | null>(null);
  const [brandId, setBrandId] = useState<string | null>(null);
  const [status, setStatus] = useState<StatusFilter>("active");
  const [page, setPage] = useState(1);

  const fetchProducts = useCallback(
    () =>
      catalogueApi.products({
        search: debouncedSearch || undefined,
        categoryId: categoryId ? Number(categoryId) : undefined,
        brandId: brandId ? Number(brandId) : undefined,
        status,
        page,
      }),
    [debouncedSearch, categoryId, brandId, status, page],
  );
  const { data, loading, error, reload } = useApiQuery(fetchProducts);

  const openProduct = (product: Pick<Product, "id" | "name">) => dispatch(openTab(productDetailTab(product, tabId)));

  /** Any filter change returns to page 1. */
  const filterSetter = <T,>(setter: (value: T) => void) => (value: T) => {
    setter(value);
    setPage(1);
  };

  return (
    <WorkspacePage section={section}
      title={title}
      description="Each size is a separate sellable item with its own barcode, stock and price."
      actions={
        can(PERMISSIONS.CATALOGUE_MANAGE) && (
          <Button leftSection={<IconPlus size={16} />} onClick={createModal.open}>
            New product
          </Button>
        )
      }
    >

      <Group gap="sm" wrap="wrap">
        <TextInput
          placeholder="Search name, brand, SKU or barcode"
          leftSection={<IconSearch size={16} />}
          value={search}
          onChange={(e) => filterSetter(setSearch)(e.currentTarget.value)}
          w={300}
        />
        <Select placeholder="All categories" data={categoryOptions} value={categoryId} onChange={filterSetter(setCategoryId)} searchable clearable w={220} />
        <Select placeholder="All brands" data={brandOptions} value={brandId} onChange={filterSetter(setBrandId)} searchable clearable w={200} />
        <SegmentedControl
          value={status}
          onChange={(v) => filterSetter(setStatus)(v as StatusFilter)}
          data={[
            { value: "active", label: "Active" },
            { value: "inactive", label: "Inactive" },
            { value: "all", label: "All" },
          ]}
        />
      </Group>

      <DataCard>
        <QueryState loading={loading} error={error} isEmpty={!data?.items.length} emptyMessage="No products match these filters." onRetry={reload}>
          <Table highlightOnHover verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Product</Table.Th>
                <Table.Th>Category</Table.Th>
                <Table.Th>Sizes</Table.Th>
                <Table.Th>Status</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((product) => (
                <Table.Tr key={product.id} style={{ cursor: "pointer" }} onClick={() => openProduct(product)}>
                  <Table.Td>
                    <Text fw={600} size="sm">
                      {product.name}
                    </Text>
                    <Text size="xs" c="dimmed">
                      {product.brand?.name ?? "No brand"}
                      {product.abv != null && ` · ${product.abv}% ABV`}
                    </Text>
                  </Table.Td>
                  <Table.Td>{product.category?.name}</Table.Td>
                  <Table.Td>
                    <Group gap={4}>
                      {product.variants?.map((v) => (
                        <Badge key={v.id} variant={v.isActive ? "light" : "outline"} color={v.isActive ? "tessera" : "gray"}>
                          {v.volumeLabel}
                        </Badge>
                      ))}
                    </Group>
                  </Table.Td>
                  <Table.Td>
                    <StatusBadge active={product.isActive} />
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </QueryState>
      </DataCard>

      {data && data.meta.lastPage > 1 && (
        <Group justify="space-between">
          <Text size="sm" c="dimmed">
            {data.meta.total} products
          </Text>
          <Pagination value={page} onChange={setPage} total={data.meta.lastPage} size="sm" />
        </Group>
      )}

      {createOpened && (
        <ProductFormModal
          opened
          onClose={createModal.close}
          onSaved={(product) => {
            createModal.close();
            reload();
            openProduct(product);
          }}
        />
      )}
    </WorkspacePage>
  );
}
