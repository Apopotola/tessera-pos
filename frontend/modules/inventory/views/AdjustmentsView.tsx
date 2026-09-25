"use client";

import { Badge, Button, Group, Pagination, SegmentedControl, Stack, Table, Text } from "@mantine/core";
import { modals } from "@mantine/modals";
import { IconPlus } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { inventoryApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import DocStatusBadge from "@/components/shared/DocStatusBadge";
import NoteModal from "@/components/shared/NoteModal";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import AdjustmentFormModal from "@/modules/inventory/components/AdjustmentFormModal";
import { ADJUSTMENT_LABEL, DOCUMENT_STATUS_COLOR, STAGES, STATUS_LABEL } from "@/modules/inventory/constants";
import { useAppSelector } from "@/store/hooks";
import type { Adjustment, DocumentStatus } from "@/types/inventory";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes } from "@/utils/money";

/** Breakages, losses, found and opening stock — each waits for a second person to approve. */
export default function AdjustmentsView({ title, section }: WorkspaceViewProps) {
  const { can } = usePermissions();
  const userId = useAppSelector((state) => state.auth.user?.id);
  const [status, setStatus] = useState<DocumentStatus>("pending");
  const [page, setPage] = useState(1);
  const [creating, setCreating] = useState(false);
  const [rejecting, setRejecting] = useState<Adjustment | null>(null);

  const fetchAdjustments = useCallback(() => inventoryApi.adjustments(status, page), [status, page]);
  const { data, loading, error, reload } = useApiQuery(fetchAdjustments);
  const canApprove = can(PERMISSIONS.INVENTORY_ADJUST_APPROVE);

  const approve = useApiMutation((adj: Adjustment) => inventoryApi.approveAdjustment(adj.id), {
    successMessage: (adj) => `${adj.number} approved — stock updated.`,
    onSuccess: reload,
  });

  const confirmApprove = (adj: Adjustment) =>
    modals.openConfirmModal({
      title: `Approve ${adj.number}?`,
      children: (
        <Text size="sm">
          {adj.direction < 0 ? "Remove" : "Add"} {adj.lines.reduce((sum, l) => sum + l.quantity, 0)} unit(s) {adj.direction < 0 ? "from" : "to"} {adj.location.name}. This
          is posted to the stock ledger and cannot be edited afterwards.
        </Text>
      ),
      labels: { confirm: "Approve", cancel: "Cancel" },
      onConfirm: () => void approve.mutate(adj),
    });

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Breakages and losses are recorded by who, where and why. Stock only changes once a different person approves."
      actions={
        (can(PERMISSIONS.INVENTORY_BREAKAGE_REPORT) || can(PERMISSIONS.INVENTORY_ADJUST)) && (
          <Button leftSection={<IconPlus size={16} />} onClick={() => setCreating(true)}>
            New adjustment
          </Button>
        )
      }
    >
      <SegmentedControl
        w="fit-content"
        value={status}
        onChange={(v) => {
          setStatus(v as DocumentStatus);
          setPage(1);
        }}
        data={[
          { value: "pending", label: "Waiting approval" },
          { value: "approved", label: "Approved" },
          { value: "rejected", label: "Rejected" },
        ]}
      />

      <DataCard>
        <QueryState loading={loading} error={error} isEmpty={!data?.items.length} emptyMessage="Nothing here." onRetry={reload}>
          <Table verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Number</Table.Th>
                <Table.Th>Type</Table.Th>
                <Table.Th>Items</Table.Th>
                <Table.Th>Location</Table.Th>
                <Table.Th>Reason</Table.Th>
                <Table.Th>Raised by</Table.Th>
                <Table.Th />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((adj) => {
                const own = adj.requestedBy?.id === userId;
                const value = adj.lines.every((l) => l.unitCostCents !== null) ? adj.lines.reduce((s, l) => s + l.quantity * (l.unitCostCents ?? 0), 0) : null;
                return (
                  <Table.Tr key={adj.id}>
                    <Table.Td>
                      <Text size="sm" ff="monospace">
                        {adj.number}
                      </Text>
                      <Text size="xs" c="dimmed">
                        {adj.createdAt ? dayjs(adj.createdAt).format("DD MMM HH:mm") : ""}
                      </Text>
                    </Table.Td>
                    <Table.Td>
                      <Badge variant="light" color={adj.direction < 0 ? "red" : "green"} radius="sm">
                        {ADJUSTMENT_LABEL[adj.type]}
                      </Badge>
                      {adj.stage && (
                        <Text size="xs" c="dimmed">
                          {STAGES.find((s) => s.value === adj.stage)?.label}
                        </Text>
                      )}
                    </Table.Td>
                    <Table.Td>
                      <Stack gap={0}>
                        {adj.lines.map((l) => (
                          <Text key={l.id} size="sm">
                            {adj.direction < 0 ? "−" : "+"}
                            {l.quantity} × {l.variant.displayName}
                          </Text>
                        ))}
                        {value !== null && (
                          <Text size="xs" c="dimmed">
                            Value {formatKes(value)}
                          </Text>
                        )}
                      </Stack>
                    </Table.Td>
                    <Table.Td>{adj.location.name}</Table.Td>
                    <Table.Td maw={240}>
                      <Text size="sm" lineClamp={2}>
                        {adj.reason}
                      </Text>
                    </Table.Td>
                    <Table.Td>
                      <Text size="sm">{adj.requestedBy?.name}</Text>
                      {adj.reviewedBy && (
                        <Text size="xs" c="dimmed">
                          {adj.status === "approved" ? "✓" : "✗"} {adj.reviewedBy.name}
                          {adj.reviewNote ? ` — ${adj.reviewNote}` : ""}
                        </Text>
                      )}
                    </Table.Td>
                    <Table.Td>
                      {adj.status === "pending" && canApprove && !own ? (
                        <Group gap="xs" wrap="nowrap">
                          <Button size="xs" onClick={() => confirmApprove(adj)} loading={approve.pending}>
                            Approve
                          </Button>
                          <Button size="xs" variant="light" color="red" onClick={() => setRejecting(adj)}>
                            Reject
                          </Button>
                        </Group>
                      ) : (
                        <DocStatusBadge label={own && adj.status === "pending" ? "Awaiting approver" : STATUS_LABEL[adj.status]} color={DOCUMENT_STATUS_COLOR[adj.status]} />
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

      {creating && (
        <AdjustmentFormModal
          onClose={() => setCreating(false)}
          onSaved={() => {
            setCreating(false);
            setStatus("pending");
            reload();
          }}
        />
      )}
      {rejecting && (
        <NoteModal
          title={`Reject ${rejecting.number}`}
          label="Reason for rejecting"
          confirmLabel="Reject"
          danger
          successMessage={`${rejecting.number} rejected.`}
          onSubmit={(note) => inventoryApi.rejectAdjustment(rejecting.id, note)}
          onClose={() => setRejecting(null)}
          onDone={() => {
            setRejecting(null);
            reload();
          }}
        />
      )}
    </WorkspacePage>
  );
}
