"use client";

import { Badge, Button, Group, Modal, Pagination, SegmentedControl, Stack, Table, Text, Textarea } from "@mantine/core";
import { useForm } from "@mantine/form";
import { modals } from "@mantine/modals";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { catalogueApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import WorkspacePage from "@/components/shared/WorkspacePage";
import QueryState from "@/components/shared/QueryState";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import { PRICE_STATUS_COLORS, TIER_LABELS } from "@/modules/catalogue/constants";
import { useAppSelector } from "@/store/hooks";
import type { PriceRecord, PriceStatus } from "@/types/catalogue";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes } from "@/utils/money";

/** Maker–checker queue: requesters see status; approvers act on requests that are not their own. */
export default function PriceChangesView({ title, section }: WorkspaceViewProps) {
  const { can } = usePermissions();
  const userId = useAppSelector((state) => state.auth.user?.id);
  const canApprove = can(PERMISSIONS.PRICES_APPROVE);
  const [status, setStatus] = useState<PriceStatus>("pending");
  const [page, setPage] = useState(1);
  const [rejecting, setRejecting] = useState<PriceRecord | null>(null);

  const fetchPrices = useCallback(() => catalogueApi.prices(status, page), [status, page]);
  const { data, loading, error, reload } = useApiQuery(fetchPrices);

  const approve = useApiMutation((price: PriceRecord) => catalogueApi.approvePrice(price.id), {
    successMessage: "Price approved.",
    onSuccess: reload,
  });

  const confirmApprove = (price: PriceRecord) =>
    modals.openConfirmModal({
      title: "Approve price",
      children: (
        <Text size="sm">
          Set {price.variant?.displayName} {TIER_LABELS[price.tier].toLowerCase()} price to <b>{formatKes(price.priceCents)}</b>
          {price.branch ? ` at ${price.branch.name}` : " at all branches"}?
        </Text>
      ),
      labels: { confirm: "Approve", cancel: "Cancel" },
      onConfirm: () => void approve.mutate(price),
    });

  return (
    <WorkspacePage section={section} title={title} description="Price changes by non-owners wait here for approval. Approved prices can never be edited, only replaced.">

      <SegmentedControl
        w="fit-content"
        value={status}
        onChange={(value) => {
          setStatus(value as PriceStatus);
          setPage(1);
        }}
        data={[
          { value: "pending", label: "Pending" },
          { value: "approved", label: "Approved" },
          { value: "rejected", label: "Rejected" },
        ]}
      />

      <DataCard>
        <QueryState loading={loading} error={error} isEmpty={!data?.items.length} emptyMessage={`No ${status} price changes.`} onRetry={reload}>
          <Table verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Item</Table.Th>
                <Table.Th>Tier / branch</Table.Th>
                <Table.Th ta="right">New price</Table.Th>
                <Table.Th>Effective</Table.Th>
                <Table.Th>Reason</Table.Th>
                <Table.Th>Requested</Table.Th>
                <Table.Th>{status === "pending" ? "" : "Review"}</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((price) => {
                const own = price.requestedBy?.id === userId;
                return (
                  <Table.Tr key={price.id}>
                    <Table.Td>
                      <Text size="sm" fw={600}>
                        {price.variant?.displayName}
                      </Text>
                      <Text size="xs" c="dimmed" ff="monospace">
                        {price.variant?.sku}
                      </Text>
                    </Table.Td>
                    <Table.Td>
                      <Text size="sm">{TIER_LABELS[price.tier]}</Text>
                      <Text size="xs" c="dimmed">
                        {price.branch?.name ?? "All branches"}
                      </Text>
                    </Table.Td>
                    <Table.Td ta="right">
                      <Text size="sm" fw={600}>
                        {formatKes(price.priceCents)}
                      </Text>
                      {price.minPriceCents != null && (
                        <Text size="xs" c="dimmed">
                          min {formatKes(price.minPriceCents)}
                        </Text>
                      )}
                    </Table.Td>
                    <Table.Td>
                      <Text size="sm">{dayjs(price.effectiveFrom).format("DD MMM YYYY")}</Text>
                    </Table.Td>
                    <Table.Td maw={220}>
                      <Text size="sm" lineClamp={2}>
                        {price.reason ?? "—"}
                      </Text>
                    </Table.Td>
                    <Table.Td>
                      <Text size="sm">{price.requestedBy?.name}</Text>
                      <Text size="xs" c="dimmed">
                        {price.createdAt ? dayjs(price.createdAt).format("DD MMM HH:mm") : ""}
                      </Text>
                    </Table.Td>
                    <Table.Td>
                      {price.status === "pending" ? (
                        canApprove && !own ? (
                          <Group gap="xs" wrap="nowrap">
                            <Button size="xs" onClick={() => confirmApprove(price)} loading={approve.pending}>
                              Approve
                            </Button>
                            <Button size="xs" variant="light" color="red" onClick={() => setRejecting(price)}>
                              Reject
                            </Button>
                          </Group>
                        ) : (
                          <Badge color="yellow" variant="light">
                            {own ? "Awaiting approver" : "Pending"}
                          </Badge>
                        )
                      ) : (
                        <Stack gap={0}>
                          <Badge color={PRICE_STATUS_COLORS[price.status]} variant="light">
                            {price.status}
                          </Badge>
                          <Text size="xs" c="dimmed">
                            {price.reviewedBy?.name}
                            {price.reviewNote ? ` — ${price.reviewNote}` : ""}
                          </Text>
                        </Stack>
                      )}
                    </Table.Td>
                  </Table.Tr>
                );
              })}
            </Table.Tbody>
          </Table>
        </QueryState>
      </DataCard>

      {data && data.meta.lastPage > 1 && (
        <Group justify="flex-end">
          <Pagination value={page} onChange={setPage} total={data.meta.lastPage} size="sm" />
        </Group>
      )}

      {rejecting && (
        <RejectPriceModal
          price={rejecting}
          onClose={() => setRejecting(null)}
          onRejected={() => {
            setRejecting(null);
            reload();
          }}
        />
      )}
    </WorkspacePage>
  );
}

function RejectPriceModal({ price, onClose, onRejected }: { price: PriceRecord; onClose: () => void; onRejected: () => void }) {
  const form = useForm({
    initialValues: { note: "" },
    validate: { note: (v) => (v.trim() ? null : "Tell the requester why") },
  });

  const { mutate, pending } = useApiMutation((note: string) => catalogueApi.rejectPrice(price.id, note), {
    successMessage: "Price rejected.",
    onValidationError: (errors) => form.setErrors(errors),
    onSuccess: onRejected,
  });

  return (
    <Modal opened onClose={onClose} title={`Reject price — ${price.variant?.displayName ?? ""}`}>
      <form onSubmit={form.onSubmit((values) => void mutate(values.note.trim()))} noValidate>
        <Stack>
          <Textarea label="Reason for rejecting" required autosize minRows={2} data-autofocus {...form.getInputProps("note")} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" color="red" loading={pending}>
              Reject
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
