"use client";

import { Button, Center, FileButton, Group, Image, Stack, Text, ThemeIcon } from "@mantine/core";
import { modals } from "@mantine/modals";
import { IconBottle, IconPhotoUp, IconTrash } from "@tabler/icons-react";
import { catalogueApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import { useApiMutation } from "@/hooks/useApiMutation";
import type { Product } from "@/types/catalogue";

/** Product photo shown on the till grid and stock screens; owners and admins upload or remove it. */
export default function ProductPhotoCard({ product, canManage, onChanged }: { product: Product; canManage: boolean; onChanged: () => void }) {
  const upload = useApiMutation((file: File) => catalogueApi.uploadProductImage(product.id, file), { successMessage: "Photo saved.", onSuccess: onChanged });
  const remove = useApiMutation(() => catalogueApi.removeProductImage(product.id), { successMessage: "Photo removed.", onSuccess: onChanged });
  const busy = upload.pending || remove.pending;

  const confirmRemove = () =>
    modals.openConfirmModal({
      title: "Remove photo",
      children: <Text size="sm">Remove the photo of {product.name}? The till shows a plain tile instead.</Text>,
      labels: { confirm: "Remove", cancel: "Cancel" },
      confirmProps: { color: "red" },
      onConfirm: () => void remove.mutate(),
    });

  return (
    <DataCard title="Photo" description="Shown on the till and stock screens. JPEG, PNG or WebP, up to 4 MB; square photos look best." padding="lg">
      <Group align="flex-start" gap="lg" wrap="wrap">
        {product.imageUrl ? (
          <Image src={product.imageUrl} alt={product.name} w={160} h={160} radius="md" fit="cover" />
        ) : (
          <Center w={160} h={160} bg="var(--mantine-color-default-hover)" style={{ borderRadius: "var(--mantine-radius-md)" }}>
            <Stack align="center" gap={4}>
              <ThemeIcon variant="light" size={48} radius="xl">
                <IconBottle size={26} />
              </ThemeIcon>
              <Text size="xs" c="dimmed">
                No photo yet
              </Text>
            </Stack>
          </Center>
        )}
        {canManage && (
          <Stack gap="xs">
            <FileButton accept="image/jpeg,image/png,image/webp" onChange={(file) => file && void upload.mutate(file)} disabled={busy}>
              {(fileProps) => (
                <Button {...fileProps} leftSection={<IconPhotoUp size={16} />} loading={upload.pending}>
                  {product.imageUrl ? "Replace photo" : "Upload photo"}
                </Button>
              )}
            </FileButton>
            {product.imageUrl && (
              <Button variant="default" color="red" leftSection={<IconTrash size={16} />} onClick={confirmRemove} loading={remove.pending} disabled={busy}>
                Remove
              </Button>
            )}
          </Stack>
        )}
      </Group>
    </DataCard>
  );
}
