"use client";

import { ActionIcon, Alert, Badge, Button, Group, Menu, Stack, Table, Text, Tooltip } from "@mantine/core";
import { modals } from "@mantine/modals";
import { IconBarcode, IconDots, IconEdit, IconHistory, IconPackage, IconPlus, IconTag, IconX } from "@tabler/icons-react";
import { useCallback, useEffect, useState } from "react";
import { catalogueApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import QueryState from "@/components/shared/QueryState";
import StatusBadge from "@/components/shared/StatusBadge";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import { BarcodeModal, PackModal } from "@/modules/catalogue/components/BarcodePackModals";
import PriceChangeModal from "@/modules/catalogue/components/PriceChangeModal";
import PriceHistoryDrawer from "@/modules/catalogue/components/PriceHistoryDrawer";
import ProductFormModal from "@/modules/catalogue/components/ProductFormModal";
import VariantFormModal from "@/modules/catalogue/components/VariantFormModal";
import { useAppDispatch } from "@/store/hooks";
import { updateTab } from "@/store/slices/tabsSlice";
import type { Barcode, Variant } from "@/types/catalogue";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes } from "@/utils/money";

type VariantDialog = "edit" | "barcode" | "pack" | "price" | "history";

export default function ProductDetailView({ tabId, title, section, props }: WorkspaceViewProps) {
  const productId = Number(props?.productId);
  const dispatch = useAppDispatch();
  const { can } = usePermissions();
  const canManage = can(PERMISSIONS.CATALOGUE_MANAGE);
  const canPrice = can(PERMISSIONS.PRICES_MANAGE);

  const fetchProduct = useCallback(() => catalogueApi.product(productId), [productId]);
  const { data: product, loading, error, reload } = useApiQuery(fetchProduct);

  // Deep-linked tabs open as "Product #id"; replace with the real name once loaded.
  const productName = product?.name;
  useEffect(() => {
    if (productName) dispatch(updateTab({ tabId, title: productName }));
  }, [dispatch, tabId, productName]);

  const [editingProduct, setEditingProduct] = useState(false);
  const [addingVariant, setAddingVariant] = useState(false);
  const [dialog, setDialog] = useState<{ kind: VariantDialog; variant: Variant } | null>(null);

  const closeAndReload = () => {
    setDialog(null);
    setAddingVariant(false);
    reload();
  };

  const removeBarcode = useApiMutation((variant: Variant, barcode: Barcode) => catalogueApi.removeBarcode(variant.id, barcode.id), {
    successMessage: "Barcode removed.",
    onSuccess: reload,
  });

  const confirmRemoveBarcode = (variant: Variant, barcode: Barcode) =>
    modals.openConfirmModal({
      title: "Remove barcode",
      children: (
        <Text size="sm">
          Remove <b>{barcode.code}</b> from {variant.displayName}? Scanning it will no longer find this item.
        </Text>
      ),
      labels: { confirm: "Remove", cancel: "Cancel" },
      confirmProps: { color: "red" },
      onConfirm: () => void removeBarcode.mutate(variant, barcode),
    });

  if (!Number.isFinite(productId) || productId <= 0) {
    return (
      <WorkspacePage section={section} title={title}>
        <Alert color="red" title="Product not found">
          This tab has no product attached.
        </Alert>
      </WorkspacePage>
    );
  }

  const meta = product
    ? [product.brand?.name, product.category?.name, product.abv != null ? `${product.abv}% ABV` : null, product.description].filter(Boolean).join(" · ")
    : undefined;

  return (
    <WorkspacePage
      section={section}
      title={product?.name ?? title}
      description={meta}
      actions={
        product && (
          <Group gap="xs">
            <StatusBadge active={product.isActive} />
            {canManage && (
              <>
                <Button variant="default" leftSection={<IconEdit size={16} />} onClick={() => setEditingProduct(true)}>
                  Edit product
                </Button>
                <Button leftSection={<IconPlus size={16} />} onClick={() => setAddingVariant(true)}>
                  Add size
                </Button>
              </>
            )}
          </Group>
        )
      }
    >
      <QueryState loading={loading && !product} error={error} isEmpty={false} onRetry={reload}>
        {product && (
          <>
            <DataCard title="Sizes" description="Each size is sold, stocked and priced separately.">
              <Table verticalSpacing="sm">
                <Table.Thead>
                  <Table.Tr>
                    <Table.Th>Item</Table.Th>
                    <Table.Th>SKU</Table.Th>
                    <Table.Th>Barcodes</Table.Th>
                    <Table.Th>Packs</Table.Th>
                    <Table.Th ta="right">Retail</Table.Th>
                    <Table.Th ta="right">Wholesale</Table.Th>
                    <Table.Th>Tax</Table.Th>
                    <Table.Th />
                  </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                  {product.variants?.map((variant) => (
                    <Table.Tr key={variant.id} opacity={variant.isActive ? 1 : 0.55}>
                      <Table.Td>
                        <Text fw={600} size="sm">
                          {variant.displayName}
                        </Text>
                        {!variant.isActive && (
                          <Badge size="xs" color="gray">
                            Inactive
                          </Badge>
                        )}
                      </Table.Td>
                      <Table.Td>
                        <Text size="sm" ff="monospace">
                          {variant.sku}
                        </Text>
                      </Table.Td>
                      <Table.Td>
                        <Stack gap={2}>
                          {variant.barcodes?.length ? (
                            variant.barcodes.map((barcode) => (
                              <Group key={barcode.id} gap={4} wrap="nowrap">
                                <Text size="xs" ff="monospace">
                                  {barcode.code}
                                </Text>
                                {barcode.packId && (
                                  <Badge size="xs" variant="outline">
                                    {variant.packs?.find((p) => p.id === barcode.packId)?.name ?? "pack"}
                                  </Badge>
                                )}
                                {canManage && (
                                  <Tooltip label="Remove barcode">
                                    <ActionIcon size="xs" variant="subtle" color="red" aria-label="Remove barcode" onClick={() => confirmRemoveBarcode(variant, barcode)}>
                                      <IconX size={12} />
                                    </ActionIcon>
                                  </Tooltip>
                                )}
                              </Group>
                            ))
                          ) : (
                            <Text size="xs" c="dimmed">
                              None
                            </Text>
                          )}
                        </Stack>
                      </Table.Td>
                      <Table.Td>
                        <Text size="sm">{variant.packs?.map((p) => `${p.name} ×${p.units}`).join(", ") || "—"}</Text>
                      </Table.Td>
                      <Table.Td ta="right">{formatKes(variant.currentPrices?.retail?.priceCents)}</Table.Td>
                      <Table.Td ta="right">{formatKes(variant.currentPrices?.wholesale?.priceCents)}</Table.Td>
                      <Table.Td>
                        <Text size="sm">{variant.taxRate?.code ?? "—"}</Text>
                      </Table.Td>
                      <Table.Td>
                        <Menu position="bottom-end" withinPortal>
                          <Menu.Target>
                            <ActionIcon variant="subtle" color="gray" aria-label={`Actions for ${variant.displayName}`}>
                              <IconDots size={16} />
                            </ActionIcon>
                          </Menu.Target>
                          <Menu.Dropdown>
                            {canPrice && (
                              <Menu.Item leftSection={<IconTag size={14} />} onClick={() => setDialog({ kind: "price", variant })}>
                                Change price
                              </Menu.Item>
                            )}
                            <Menu.Item leftSection={<IconHistory size={14} />} onClick={() => setDialog({ kind: "history", variant })}>
                              Price history
                            </Menu.Item>
                            {canManage && (
                              <>
                                <Menu.Divider />
                                <Menu.Item leftSection={<IconEdit size={14} />} onClick={() => setDialog({ kind: "edit", variant })}>
                                  Edit size
                                </Menu.Item>
                                <Menu.Item leftSection={<IconBarcode size={14} />} onClick={() => setDialog({ kind: "barcode", variant })}>
                                  Add barcode
                                </Menu.Item>
                                <Menu.Item leftSection={<IconPackage size={14} />} onClick={() => setDialog({ kind: "pack", variant })}>
                                  Add pack
                                </Menu.Item>
                              </>
                            )}
                          </Menu.Dropdown>
                        </Menu>
                      </Table.Td>
                    </Table.Tr>
                  ))}
                </Table.Tbody>
              </Table>
            </DataCard>

            {editingProduct && (
              <ProductFormModal
                opened
                product={product}
                onClose={() => setEditingProduct(false)}
                onSaved={(saved) => {
                  setEditingProduct(false);
                  dispatch(updateTab({ tabId, title: saved.name }));
                  reload();
                }}
              />
            )}
            {addingVariant && <VariantFormModal opened productId={product.id} onClose={() => setAddingVariant(false)} onSaved={closeAndReload} />}
            {dialog?.kind === "edit" && (
              <VariantFormModal opened productId={product.id} variant={dialog.variant} onClose={() => setDialog(null)} onSaved={closeAndReload} />
            )}
            {dialog?.kind === "barcode" && <BarcodeModal opened variant={dialog.variant} onClose={() => setDialog(null)} onSaved={closeAndReload} />}
            {dialog?.kind === "pack" && <PackModal opened variant={dialog.variant} onClose={() => setDialog(null)} onSaved={closeAndReload} />}
            {dialog?.kind === "price" && <PriceChangeModal opened variant={dialog.variant} onClose={() => setDialog(null)} onSaved={closeAndReload} />}
            {dialog?.kind === "history" && <PriceHistoryDrawer variant={dialog.variant} onClose={() => setDialog(null)} />}
          </>
        )}
      </QueryState>
    </WorkspacePage>
  );
}
