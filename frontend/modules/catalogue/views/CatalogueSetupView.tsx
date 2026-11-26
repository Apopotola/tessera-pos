"use client";

import { ActionIcon, Button, Group, Modal, NumberInput, Select, Stack, Switch, Table, Tabs, Text, TextInput, Tooltip } from "@mantine/core";
import { useForm } from "@mantine/form";
import { IconEdit, IconPlus } from "@tabler/icons-react";
import { Fragment, useState } from "react";
import { catalogueApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import StatusBadge from "@/components/shared/StatusBadge";
import WorkspacePage from "@/components/shared/WorkspacePage";
import QueryState from "@/components/shared/QueryState";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useCatalogueReference } from "@/modules/catalogue/hooks/useCatalogueReference";
import type { Brand, Category } from "@/types/catalogue";

export default function CatalogueSetupView({ title, section }: WorkspaceViewProps) {
  const { brands, categories } = useCatalogueReference();
  const [brandDialog, setBrandDialog] = useState<{ brand?: Brand } | null>(null);
  const [categoryDialog, setCategoryDialog] = useState<{ category?: Category } | null>(null);

  return (
    <WorkspacePage section={section} title={title} description="Reference lists used when creating products.">

      <Tabs defaultValue="brands" keepMounted={false}>
        <Tabs.List>
          <Tabs.Tab value="brands">Brands</Tabs.Tab>
          <Tabs.Tab value="categories">Categories</Tabs.Tab>
        </Tabs.List>

        <Tabs.Panel value="brands" pt="md">
          <Stack>
            <Button w="fit-content" leftSection={<IconPlus size={16} />} onClick={() => setBrandDialog({})}>
              Add brand
            </Button>
            <DataCard>
              <QueryState loading={brands.loading} error={brands.error} isEmpty={!brands.data?.length} emptyMessage="No brands yet." onRetry={brands.reload}>
                <Table verticalSpacing="xs">
                  <Table.Thead>
                    <Table.Tr>
                      <Table.Th>Brand</Table.Th>
                      <Table.Th>Country</Table.Th>
                      <Table.Th>Status</Table.Th>
                      <Table.Th />
                    </Table.Tr>
                  </Table.Thead>
                  <Table.Tbody>
                    {brands.data?.map((brand) => (
                      <Table.Tr key={brand.id}>
                        <Table.Td fw={600}>{brand.name}</Table.Td>
                        <Table.Td>{brand.country ?? "—"}</Table.Td>
                        <Table.Td>
                          <StatusBadge active={brand.isActive} />
                        </Table.Td>
                        <Table.Td ta="right">
                          <EditButton label={`Edit ${brand.name}`} onClick={() => setBrandDialog({ brand })} />
                        </Table.Td>
                      </Table.Tr>
                    ))}
                  </Table.Tbody>
                </Table>
              </QueryState>
            </DataCard>
          </Stack>
        </Tabs.Panel>

        <Tabs.Panel value="categories" pt="md">
          <Stack>
            <Button w="fit-content" leftSection={<IconPlus size={16} />} onClick={() => setCategoryDialog({})}>
              Add category
            </Button>
            <DataCard>
              <QueryState
                loading={categories.loading}
                error={categories.error}
                isEmpty={!categories.data?.length}
                emptyMessage="No categories yet."
                onRetry={categories.reload}
              >
                <Table verticalSpacing="xs">
                  <Table.Thead>
                    <Table.Tr>
                      <Table.Th>Category</Table.Th>
                      <Table.Th>Order</Table.Th>
                      <Table.Th>Status</Table.Th>
                      <Table.Th />
                    </Table.Tr>
                  </Table.Thead>
                  <Table.Tbody>
                    {categories.data?.map((parent) => (
                      <Fragment key={parent.id}>
                        <Table.Tr>
                          <Table.Td fw={600}>{parent.name}</Table.Td>
                          <Table.Td>{parent.sortOrder}</Table.Td>
                          <Table.Td>
                            <StatusBadge active={parent.isActive} />
                          </Table.Td>
                          <Table.Td ta="right">
                            <EditButton label={`Edit ${parent.name}`} onClick={() => setCategoryDialog({ category: parent })} />
                          </Table.Td>
                        </Table.Tr>
                        {parent.children?.map((child) => (
                          <Table.Tr key={child.id}>
                            <Table.Td pl={36}>
                              <Text size="sm">↳ {child.name}</Text>
                            </Table.Td>
                            <Table.Td>{child.sortOrder}</Table.Td>
                            <Table.Td>
                              <StatusBadge active={child.isActive} />
                            </Table.Td>
                            <Table.Td ta="right">
                              <EditButton label={`Edit ${child.name}`} onClick={() => setCategoryDialog({ category: child })} />
                            </Table.Td>
                          </Table.Tr>
                        ))}
                      </Fragment>
                    ))}
                  </Table.Tbody>
                </Table>
              </QueryState>
            </DataCard>
          </Stack>
        </Tabs.Panel>
      </Tabs>

      {brandDialog && (
        <BrandModal
          brand={brandDialog.brand}
          onClose={() => setBrandDialog(null)}
          onSaved={() => {
            setBrandDialog(null);
            brands.reload();
          }}
        />
      )}
      {categoryDialog && (
        <CategoryModal
          category={categoryDialog.category}
          parents={categories.data ?? []}
          onClose={() => setCategoryDialog(null)}
          onSaved={() => {
            setCategoryDialog(null);
            categories.reload();
          }}
        />
      )}
    </WorkspacePage>
  );
}


function EditButton({ label, onClick }: { label: string; onClick: () => void }) {
  return (
    <Tooltip label={label}>
      <ActionIcon variant="subtle" color="gray" aria-label={label} onClick={onClick}>
        <IconEdit size={16} />
      </ActionIcon>
    </Tooltip>
  );
}

function BrandModal({ brand, onClose, onSaved }: { brand?: Brand; onClose: () => void; onSaved: () => void }) {
  const form = useForm({
    initialValues: { name: brand?.name ?? "", country: brand?.country ?? "", isActive: brand?.isActive ?? true },
    validate: { name: (v) => (v.trim() ? null : "Enter the brand name") },
  });

  const { mutate, pending } = useApiMutation(
    (values: typeof form.values) => {
      const payload = { name: values.name.trim(), country: values.country.trim() || null, isActive: values.isActive };
      return brand ? catalogueApi.updateBrand(brand.id, payload) : catalogueApi.createBrand(payload);
    },
    { successMessage: brand ? "Brand updated." : "Brand added.", onValidationError: (e) => form.setErrors(e), onSuccess: onSaved },
  );

  return (
    <Modal opened onClose={onClose} title={brand ? "Edit brand" : "Add brand"}>
      <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
        <Stack>
          <TextInput label="Name" required data-autofocus {...form.getInputProps("name")} />
          <TextInput label="Country of origin" {...form.getInputProps("country")} />
          {brand && <Switch label="Active" {...form.getInputProps("isActive", { type: "checkbox" })} />}
          <FormActions pending={pending} onCancel={onClose} />
        </Stack>
      </form>
    </Modal>
  );
}

function CategoryModal({ category, parents, onClose, onSaved }: { category?: Category; parents: Category[]; onClose: () => void; onSaved: () => void }) {
  const form = useForm({
    initialValues: {
      name: category?.name ?? "",
      parentId: category?.parentId ? String(category.parentId) : null,
      sortOrder: category?.sortOrder ?? 0,
      isActive: category?.isActive ?? true,
    },
    validate: { name: (v) => (v.trim() ? null : "Enter the category name") },
  });

  const parentOptions = parents.filter((p) => p.id !== category?.id).map((p) => ({ value: String(p.id), label: p.name }));

  const { mutate, pending } = useApiMutation(
    (values: typeof form.values) => {
      const payload = {
        name: values.name.trim(),
        parentId: values.parentId ? Number(values.parentId) : null,
        sortOrder: Number(values.sortOrder) || 0,
        isActive: values.isActive,
      };
      return category ? catalogueApi.updateCategory(category.id, payload) : catalogueApi.createCategory(payload);
    },
    { successMessage: category ? "Category updated." : "Category added.", onValidationError: (e) => form.setErrors(e), onSuccess: onSaved },
  );

  return (
    <Modal opened onClose={onClose} title={category ? "Edit category" : "Add category"}>
      <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
        <Stack>
          <TextInput label="Name" required data-autofocus {...form.getInputProps("name")} />
          <Select label="Parent category" placeholder="None (top level)" data={parentOptions} clearable {...form.getInputProps("parentId")} />
          <NumberInput label="Display order" min={0} {...form.getInputProps("sortOrder")} />
          {category && <Switch label="Active" {...form.getInputProps("isActive", { type: "checkbox" })} />}
          <FormActions pending={pending} onCancel={onClose} />
        </Stack>
      </form>
    </Modal>
  );
}

function FormActions({ pending, onCancel }: { pending: boolean; onCancel: () => void }) {
  return (
    <Group justify="flex-end">
      <Button variant="default" onClick={onCancel} disabled={pending}>
        Cancel
      </Button>
      <Button type="submit" loading={pending}>
        Save
      </Button>
    </Group>
  );
}
