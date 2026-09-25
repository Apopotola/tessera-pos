"use client";

import { Button, Group, Pagination, SegmentedControl, Stack, Table, Text } from "@mantine/core";
import { IconArrowRight, IconPlus } from "@tabler/icons-react";
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
import TransferFormModal from "@/modules/inventory/components/TransferFormModal";
import TransferQuantitiesModal from "@/modules/inventory/components/TransferQuantitiesModal";
import { DOCUMENT_STATUS_COLOR, STATUS_LABEL } from "@/modules/inventory/constants";
import { useAppSelector } from "@/store/hooks";
import type { Transfer, TransferStatus } from "@/types/inventory";
import { PERMISSIONS } from "@/types/permissions";

type Filter = "open" | TransferStatus;
type Dialog = { kind: "create" } | { kind: "dispatch" | "receive" | "cancel"; transfer: Transfer };

/** Request → approve → dispatch → receive. Stock in between is shown as in transit. */
export default function TransfersView({ title, section }: WorkspaceViewProps) {
  const { can } = usePermissions();
  const userId = useAppSelector((state) => state.auth.user?.id);
  const [filter, setFilter] = useState<Filter>("open");
  const [page, setPage] = useState(1);
  const [dialog, setDialog] = useState<Dialog | null>(null);

  const fetchTransfers = useCallback(() => inventoryApi.transfers(filter, page), [filter, page]);
  const { data, loading, error, reload } = useApiQuery(fetchTransfers);
  const rows = data?.items;

  const approve = useApiMutation((t: Transfer) => inventoryApi.approveTransfer(t.id), {
    successMessage: (t) => `${t.number} approved.`,
    onSuccess: reload,
  });

  const done = () => {
    setDialog(null);
    reload();
  };

  const actionsFor = (t: Transfer) => {
    const own = t.requestedBy?.id === userId;
    switch (t.status) {
      case "requested":
        return (
          <Group gap="xs" wrap="nowrap">
            {can(PERMISSIONS.INVENTORY_TRANSFER_APPROVE) && !own && (
              <Button size="xs" onClick={() => void approve.mutate(t)} loading={approve.pending}>
                Approve
              </Button>
            )}
            <Button size="xs" variant="subtle" color="red" onClick={() => setDialog({ kind: "cancel", transfer: t })}>
              Cancel
            </Button>
          </Group>
        );
      case "approved":
        return can(PERMISSIONS.INVENTORY_TRANSFER) ? (
          <Button size="xs" onClick={() => setDialog({ kind: "dispatch", transfer: t })}>
            Dispatch
          </Button>
        ) : null;
      case "in_transit":
        return can(PERMISSIONS.INVENTORY_RECEIVE) || can(PERMISSIONS.INVENTORY_TRANSFER) ? (
          <Button size="xs" color="green" onClick={() => setDialog({ kind: "receive", transfer: t })}>
            Receive
          </Button>
        ) : null;
      default:
        return null;
    }
  };

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Move stock between branches or from the store to the shop floor. Short deliveries become a breakage for approval."
      actions={
        can(PERMISSIONS.INVENTORY_TRANSFER) && (
          <Button leftSection={<IconPlus size={16} />} onClick={() => setDialog({ kind: "create" })}>
            Request transfer
          </Button>
        )
      }
    >
      <SegmentedControl
        w="fit-content"
        value={filter}
        onChange={(v) => {
          setFilter(v as Filter);
          setPage(1);
        }}
        data={[
          { value: "open", label: "Open" },
          { value: "received", label: "Received" },
          { value: "cancelled", label: "Cancelled" },
        ]}
      />

      <DataCard>
        <QueryState loading={loading} error={error} isEmpty={!rows?.length} emptyMessage="No transfers here." onRetry={reload}>
          <Table verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Number</Table.Th>
                <Table.Th>Route</Table.Th>
                <Table.Th>Items</Table.Th>
                <Table.Th>Status</Table.Th>
                <Table.Th />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {rows?.map((t) => (
                <Table.Tr key={t.id}>
                  <Table.Td>
                    <Text size="sm" ff="monospace">
                      {t.number}
                    </Text>
                    <Text size="xs" c="dimmed">
                      {t.requestedBy?.name} · {t.createdAt ? dayjs(t.createdAt).format("DD MMM HH:mm") : ""}
                    </Text>
                  </Table.Td>
                  <Table.Td>
                    <Group gap={6} wrap="nowrap">
                      <Text size="sm">
                        {t.from.location.name} <Text span c="dimmed">({t.from.branchCode})</Text>
                      </Text>
                      <IconArrowRight size={14} />
                      <Text size="sm">
                        {t.to.location.name} <Text span c="dimmed">({t.to.branchCode})</Text>
                      </Text>
                    </Group>
                  </Table.Td>
                  <Table.Td>
                    <Stack gap={0}>
                      {t.lines.map((l) => (
                        <Text key={l.id} size="sm">
                          {l.quantityReceived ?? l.quantityDispatched ?? l.quantityRequested} × {l.variant.displayName}
                          {l.quantityReceived !== null && l.quantityDispatched !== null && l.quantityReceived < l.quantityDispatched && (
                            <Text span c="red.7" size="xs">
                              {" "}
                              ({l.quantityDispatched - l.quantityReceived} short)
                            </Text>
                          )}
                        </Text>
                      ))}
                    </Stack>
                  </Table.Td>
                  <Table.Td>
                    <DocStatusBadge label={STATUS_LABEL[t.status]} color={DOCUMENT_STATUS_COLOR[t.status]} />
                  </Table.Td>
                  <Table.Td>{actionsFor(t)}</Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </QueryState>
      </DataCard>

      {data && data.meta.lastPage > 1 && (
        <Group justify="flex-end">
          <Pagination value={page} onChange={setPage} total={data.meta.lastPage} size="sm" />
        </Group>
      )}

      {dialog?.kind === "create" && <TransferFormModal onClose={() => setDialog(null)} onSaved={done} />}
      {(dialog?.kind === "dispatch" || dialog?.kind === "receive") && (
        <TransferQuantitiesModal transfer={dialog.transfer} mode={dialog.kind} onClose={() => setDialog(null)} onDone={done} />
      )}
      {dialog?.kind === "cancel" && (
        <NoteModal
          title={`Cancel ${dialog.transfer.number}`}
          label="Why is it cancelled?"
          confirmLabel="Cancel transfer"
          danger
          successMessage={`${dialog.transfer.number} cancelled.`}
          onSubmit={(note) => inventoryApi.cancelTransfer(dialog.transfer.id, note)}
          onClose={() => setDialog(null)}
          onDone={done}
        />
      )}
    </WorkspacePage>
  );
}
