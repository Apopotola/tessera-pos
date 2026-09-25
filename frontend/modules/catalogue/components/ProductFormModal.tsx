"use client";

import {
  ActionIcon,
  Button,
  Divider,
  Group,
  Modal,
  NumberInput,
  Paper,
  Select,
  SimpleGrid,
  Stack,
  Switch,
  Text,
  TextInput,
  Textarea,
  Tooltip,
} from "@mantine/core";
import { useForm } from "@mantine/form";
import { randomId } from "@mantine/hooks";
import { notifications } from "@mantine/notifications";
import { IconPlus, IconTrash } from "@tabler/icons-react";
import { catalogueApi } from "@/api";
import { useApiMutation } from "@/hooks/useApiMutation";
import { CONTAINER_OPTIONS } from "@/modules/catalogue/constants";
import { useCatalogueReference } from "@/modules/catalogue/hooks/useCatalogueReference";
import type { Container, CreateProductPayload, Product, ProductPayload } from "@/types/catalogue";
import { optionalKesToCents } from "@/utils/money";

interface VariantRow {
  key: string;
  volumeMl: number | string;
  container: Container;
  sku: string;
  taxRateId: string | null;
  barcode: string;
  retailPriceKes: number | string;
}

interface ProductFormValues {
  brandId: string | null;
  categoryId: string | null;
  name: string;
  abv: number | string;
  description: string;
  isActive: boolean;
  variants: VariantRow[];
}

interface ProductFormModalProps {
  opened: boolean;
  onClose: () => void;
  /** Edit mode when given; variants are then managed on the product screen. */
  product?: Product;
  onSaved: (product: Product) => void;
}

function emptyVariant(taxRateId: string | null = null): VariantRow {
  return { key: randomId(), volumeMl: 750, container: "bottle", sku: "", taxRateId, barcode: "", retailPriceKes: "" };
}

/** Laravel error paths → form paths (barcodes.0 → barcode, retailPriceCents → retailPriceKes). */
function toFormErrors(errors: Record<string, string>): Record<string, string> {
  return Object.fromEntries(
    Object.entries(errors).map(([path, message]) => [
      path.replace(/\.barcodes\.\d+$/, ".barcode").replace(/\.retailPriceCents$/, ".retailPriceKes"),
      message,
    ]),
  );
}

export default function ProductFormModal({ opened, onClose, product, onSaved }: ProductFormModalProps) {
  const editing = Boolean(product);
  const { brandOptions, categoryOptions, taxRateOptions } = useCatalogueReference();
  const defaultTax = taxRateOptions.find((o) => o.label.startsWith("B "))?.value ?? null;

  const form = useForm<ProductFormValues>({
    initialValues: {
      brandId: product?.brand ? String(product.brand.id) : null,
      categoryId: product?.category ? String(product.category.id) : null,
      name: product?.name ?? "",
      abv: product?.abv ?? "",
      description: product?.description ?? "",
      isActive: product?.isActive ?? true,
      variants: editing ? [] : [emptyVariant(defaultTax)],
    },
    validate: {
      name: (v) => (v.trim() ? null : "Enter the product name"),
      categoryId: (v) => (v ? null : "Choose a category"),
      variants: {
        volumeMl: (v) => (Number(v) > 0 ? null : "Enter the size in ml"),
        sku: (v) => (v.trim() ? null : "Enter a SKU"),
        taxRateId: (v) => (v ? null : "Choose a tax rate"),
      },
    },
  });

  const { mutate, pending } = useApiMutation(
    async (values: ProductFormValues) => {
      const base: ProductPayload = {
        brandId: values.brandId ? Number(values.brandId) : null,
        categoryId: Number(values.categoryId),
        name: values.name.trim(),
        abv: values.abv === "" ? null : Number(values.abv),
        description: values.description.trim() || null,
      };

      if (product) {
        return { product: await catalogueApi.updateProduct(product.id, { ...base, isActive: values.isActive }), warnings: [] };
      }

      const payload: CreateProductPayload = {
        ...base,
        variants: values.variants.map((v) => ({
          volumeMl: Number(v.volumeMl),
          container: v.container,
          sku: v.sku.trim(),
          taxRateId: Number(v.taxRateId),
          barcodes: v.barcode.trim() ? [v.barcode.trim()] : [],
          retailPriceCents: optionalKesToCents(v.retailPriceKes),
        })),
      };
      return catalogueApi.createProduct(payload);
    },
    {
      successMessage: editing ? "Product updated." : "Product created.",
      onValidationError: (errors) => form.setErrors(toFormErrors(errors)),
      onSuccess: ({ product: saved, warnings }) => {
        warnings.forEach((message) => notifications.show({ color: "yellow", title: "Check pricing", message }));
        form.reset();
        onSaved(saved);
      },
    },
  );

  return (
    <Modal opened={opened} onClose={onClose} title={editing ? "Edit product" : "New product"} size="xl">
      <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
        <Stack gap="md">
          <SimpleGrid cols={{ base: 1, sm: 2 }}>
            <TextInput label="Product name" placeholder="e.g. Johnnie Walker Black Label" required {...form.getInputProps("name")} />
            <Select label="Brand" placeholder="Select brand" data={brandOptions} searchable clearable {...form.getInputProps("brandId")} />
            <Select label="Category" placeholder="Select category" data={categoryOptions} searchable required {...form.getInputProps("categoryId")} />
            <NumberInput label="ABV %" min={0} max={100} decimalScale={1} {...form.getInputProps("abv")} />
          </SimpleGrid>
          <Textarea label="Description" autosize minRows={2} {...form.getInputProps("description")} />
          {editing && <Switch label="Active (inactive products cannot be sold)" {...form.getInputProps("isActive", { type: "checkbox" })} />}

          {!editing && (
            <>
              <Divider label="Sizes (each size is a separate sellable item)" labelPosition="left" />
              {form.values.variants.map((row, index) => (
                <Paper key={row.key} withBorder p="sm">
                  <SimpleGrid cols={{ base: 2, sm: 4 }}>
                    <NumberInput label="Size (ml)" min={1} required {...form.getInputProps(`variants.${index}.volumeMl`)} />
                    <Select label="Container" data={CONTAINER_OPTIONS} allowDeselect={false} {...form.getInputProps(`variants.${index}.container`)} />
                    <TextInput label="SKU" placeholder="JWB-750" required {...form.getInputProps(`variants.${index}.sku`)} />
                    <Select label="Tax" data={taxRateOptions} required {...form.getInputProps(`variants.${index}.taxRateId`)} />
                    <TextInput label="Barcode" placeholder="Scan or type" {...form.getInputProps(`variants.${index}.barcode`)} />
                    <NumberInput
                      label="Retail price (KES)"
                      min={0}
                      decimalScale={2}
                      thousandSeparator=","
                      {...form.getInputProps(`variants.${index}.retailPriceKes`)}
                    />
                  </SimpleGrid>
                  {form.values.variants.length > 1 && (
                    <Group justify="flex-end" mt="xs">
                      <Tooltip label="Remove size">
                        <ActionIcon color="red" variant="subtle" onClick={() => form.removeListItem("variants", index)} aria-label="Remove size">
                          <IconTrash size={16} />
                        </ActionIcon>
                      </Tooltip>
                    </Group>
                  )}
                </Paper>
              ))}
              {typeof form.errors.variants === "string" && (
                <Text c="red" size="sm">
                  {form.errors.variants}
                </Text>
              )}
              <Button
                variant="light"
                leftSection={<IconPlus size={16} />}
                onClick={() => form.insertListItem("variants", emptyVariant(defaultTax))}
                w="fit-content"
              >
                Add size
              </Button>
            </>
          )}

          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              {editing ? "Save changes" : "Create product"}
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
