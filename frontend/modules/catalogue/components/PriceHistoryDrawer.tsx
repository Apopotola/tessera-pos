"use client";

import { Badge, Drawer, Group, Paper, Stack, Text } from "@mantine/core";
import dayjs from "dayjs";
import { useCallback } from "react";
import { catalogueApi } from "@/api";
import QueryState from "@/components/shared/QueryState";
import { useApiQuery } from "@/hooks/useApiQuery";
import { PRICE_STATUS_COLORS, TIER_LABELS } from "@/modules/catalogue/constants";
import type { Variant } from "@/types/catalogue";
import { formatKes } from "@/utils/money";

/** Every price row ever created for a variant — nothing is overwritten, so this is the full story. */
export default function PriceHistoryDrawer({ variant, onClose }: { variant: Variant; onClose: () => void }) {
  const fetchHistory = useCallback(() => catalogueApi.priceHistory(variant.id), [variant.id]);
  const { data, loading, error, reload } = useApiQuery(fetchHistory);

  return (
    <Drawer opened onClose={onClose} position="right" size="md" title={`Price history — ${variant.displayName}`}>
      <QueryState loading={loading} error={error} isEmpty={!data?.length} emptyMessage="No prices set yet." onRetry={reload}>
        <Stack gap="sm">
          {data?.map((price) => (
            <Paper key={price.id} withBorder p="sm">
              <Group justify="space-between">
                <Text fw={600}>{formatKes(price.priceCents)}</Text>
                <Badge color={PRICE_STATUS_COLORS[price.status]} variant="light">
                  {price.status}
                </Badge>
              </Group>
              <Text size="xs" c="dimmed">
                {TIER_LABELS[price.tier]} · {price.branch ? price.branch.name : "All branches"} · from {dayjs(price.effectiveFrom).format("DD MMM YYYY HH:mm")}
              </Text>
              {price.minPriceCents != null && <Text size="xs">Minimum {formatKes(price.minPriceCents)}</Text>}
              <Text size="xs" mt={4}>
                {price.reason ?? "—"} — requested by {price.requestedBy?.name ?? "unknown"}
              </Text>
              {price.reviewedBy && (
                <Text size="xs" c="dimmed">
                  Reviewed by {price.reviewedBy.name}
                  {price.reviewNote ? `: ${price.reviewNote}` : ""}
                </Text>
              )}
            </Paper>
          ))}
        </Stack>
      </QueryState>
    </Drawer>
  );
}
