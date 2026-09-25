"use client";

import { Alert, Button, Group, NumberInput, Stack, Table, Text } from "@mantine/core";
import { modals } from "@mantine/modals";
import { IconEyeOff, IconPlus } from "@tabler/icons-react";
import { useCallback, useEffect, useState } from "react";
import { inventoryApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import DocStatusBadge from "@/components/shared/DocStatusBadge";
import NoteModal from "@/components/shared/NoteModal";
import QueryState from "@/components/shared/QueryState";
import StatTile from "@/components/shared/StatTile";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import VariantPicker, { type PickedVariant } from "@/modules/inventory/components/VariantPicker";
import { DOCUMENT_STATUS_COLOR, STATUS_LABEL } from "@/modules/inventory/constants";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { updateTab } from "@/store/slices/tabsSlice";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes } from "@/utils/money";

/** One count sheet: enter counts blind, submit, then a different person approves the variances. */
export default function StockCountSheetView({ tabId, title, section, props }: WorkspaceViewProps) {
  const countId = Number(props?.countId);
  const dispatch = useAppDispatch();
  const { can } = usePermissions();
  const userId = useAppSelector((state) => state.auth.user?.id);
  const fetchCount = useCallback(() => inventoryApi.count(countId), [countId]);
  const { data: count, loading, error, reload } = useApiQuery(fetchCount);
  const [counted, setCounted] = useState<Record<number, number | string>>({});
  const [found, setFound] = useState<PickedVariant | null>(null);
  const [rejecting, setRejecting] = useState(false);

  const number = count?.number;
  useEffect(() => {
    if (number) dispatch(updateTab({ tabId, title: number }));
  }, [dispatch, tabId, number]);

  const counting = count?.status === "counting";
  const valueFor = (variantId: number, saved: number | null) => counted[variantId] ?? saved ?? "";

  const save = useApiMutation(
    () =>
      inventoryApi.recordCount(
        countId,
        (count?.lines ?? []).map((l) => {
          const v = valueFor(l.variant.id, l.countedQuantity);
          return { variantId: l.variant.id, countedQuantity: v === "" ? null : Number(v) };
        }),
      ),
    { successMessage: "Count saved.", onSuccess: reload },
  );

  const addFound = useApiMutation((variant: PickedVariant) => inventoryApi.recordCount(countId, [{ variantId: variant.id, countedQuantity: 1 }]), {
    successMessage: "Item added to the sheet.",
    onSuccess: () => {
      setFound(null);
      reload();
    },
  });

  const submit = useApiMutation(
    async () => {
      await inventoryApi.recordCount(
        countId,
        (count?.lines ?? []).map((l) => {
          const v = valueFor(l.variant.id, l.countedQuantity);
          return { variantId: l.variant.id, countedQuantity: v === "" ? null : Number(v) };
        }),
      );
      return inventoryApi.submitCount(countId);
    },
    { successMessage: "Count submitted for approval.", onSuccess: reload },
  );

  const approve = useApiMutation(() => inventoryApi.approveCount(countId), { successMessage: "Count approved — stock corrected.", onSuccess: reload });

  if (!Number.isFinite(countId) || countId <= 0) {
    return (
      <WorkspacePage section={section} title={title}>
        <Alert color="red">This tab has no count attached.</Alert>
      </WorkspacePage>
    );
  }

  const lines = count?.lines ?? [];
  const blanks = lines.filter((l) => valueFor(l.variant.id, l.countedQuantity) === "").length;
  const variances = lines.filter((l) => (l.variance ?? 0) !== 0);
  const varianceValue = lines.reduce((s, l) => s + (l.varianceValueCents ?? 0), 0);
  const hasValue = lines.some((l) => l.varianceValueCents !== null);

  return (
    <WorkspacePage
      section={section}
      title={count ? `${count.number} · ${count.location.name}` : title}
      description={count?.note ?? undefined}
      actions={count && <DocStatusBadge label={STATUS_LABEL[count.status]} color={DOCUMENT_STATUS_COLOR[count.status]} />}
    >
      <QueryState loading={loading && !count} error={error} isEmpty={false} onRetry={reload}>
        {count && (
          <Stack gap="lg">
            {counting ? (
              <Alert variant="light" icon={<IconEyeOff size={18} />} title="Blind count">
                Count what is physically on the shelf. The system figure is hidden until you submit, so counts are honest. Enter 0 for items you cannot find.
              </Alert>
            ) : (
              <Group grow>
                <StatTile label="Items counted" value={lines.length} />
                <StatTile label="Items with a difference" value={variances.length} highlight={variances.length > 0} />
                {hasValue && <StatTile label="Net difference (at cost)" value={formatKes(varianceValue)} />}
              </Group>
            )}

            <DataCard
              title="Count sheet"
              actions={
                counting &&
                can(PERMISSIONS.INVENTORY_COUNT) && (
                  <Group gap="xs" wrap="nowrap">
                    <div style={{ width: 280 }}>
                      <VariantPicker value={found} onChange={setFound} placeholder="Found something not listed?" />
                    </div>
                    <Button variant="light" leftSection={<IconPlus size={14} />} disabled={!found} loading={addFound.pending} onClick={() => found && void addFound.mutate(found)}>
                      Add
                    </Button>
                  </Group>
                )
              }
            >
              <Table verticalSpacing="xs">
                <Table.Thead>
                  <Table.Tr>
                    <Table.Th>Item</Table.Th>
                    <Table.Th w={150}>Counted</Table.Th>
                    {!counting && <Table.Th ta="right">System</Table.Th>}
                    {!counting && <Table.Th ta="right">Difference</Table.Th>}
                    {!counting && hasValue && <Table.Th ta="right">Value</Table.Th>}
                  </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                  {lines.map((line) => (
                    <Table.Tr key={line.id}>
                      <Table.Td>
                        <Text size="sm" fw={600}>
                          {line.variant.displayName}
                        </Text>
                        <Text size="xs" c="dimmed" ff="monospace">
                          {line.variant.sku}
                        </Text>
                      </Table.Td>
                      <Table.Td>
                        {counting ? (
                          <NumberInput
                            min={0}
                            value={valueFor(line.variant.id, line.countedQuantity)}
                            onChange={(v) => setCounted((c) => ({ ...c, [line.variant.id]: v }))}
                            aria-label={`Counted ${line.variant.displayName}`}
                          />
                        ) : (
                          <Text fw={600}>{line.countedQuantity}</Text>
                        )}
                      </Table.Td>
                      {!counting && <Table.Td ta="right">{line.expectedQuantity}</Table.Td>}
                      {!counting && (
                        <Table.Td ta="right">
                          <Text fw={700} c={!line.variance ? "dimmed" : line.variance > 0 ? "green.7" : "red.7"}>
                            {line.variance && line.variance > 0 ? `+${line.variance}` : line.variance}
                          </Text>
                        </Table.Td>
                      )}
                      {!counting && hasValue && <Table.Td ta="right">{formatKes(line.varianceValueCents)}</Table.Td>}
                    </Table.Tr>
                  ))}
                </Table.Tbody>
              </Table>
            </DataCard>

            {counting && can(PERMISSIONS.INVENTORY_COUNT) && (
              <Group justify="flex-end">
                <Button variant="default" loading={save.pending} onClick={() => void save.mutate()}>
                  Save progress
                </Button>
                <Button
                  loading={submit.pending}
                  disabled={blanks > 0}
                  onClick={() =>
                    modals.openConfirmModal({
                      title: "Submit count?",
                      children: <Text size="sm">You cannot change the counts after submitting. The system figures and differences will then be shown.</Text>,
                      labels: { confirm: "Submit", cancel: "Keep counting" },
                      onConfirm: () => void submit.mutate(),
                    })
                  }
                >
                  {blanks > 0 ? `${blanks} line(s) still blank` : "Submit count"}
                </Button>
              </Group>
            )}

            {count.status === "submitted" && can(PERMISSIONS.INVENTORY_COUNT_APPROVE) && count.submittedBy?.id !== userId && (
              <Group justify="flex-end">
                <Button variant="light" color="red" onClick={() => setRejecting(true)}>
                  Reject (recount)
                </Button>
                <Button loading={approve.pending} onClick={() => void approve.mutate()}>
                  Approve and correct stock
                </Button>
              </Group>
            )}

            {count.reviewedBy && (
              <Text size="sm" c="dimmed">
                {count.status === "approved" ? "Approved" : "Rejected"} by {count.reviewedBy.name}
                {count.reviewNote ? ` — ${count.reviewNote}` : ""}
              </Text>
            )}
          </Stack>
        )}
      </QueryState>

      {rejecting && count && (
        <NoteModal
          title={`Reject ${count.number}`}
          description="The variances will not be posted. Start a new count to recount."
          label="Reason"
          confirmLabel="Reject"
          danger
          successMessage={`${count.number} rejected.`}
          onSubmit={(note) => inventoryApi.rejectCount(count.id, note)}
          onClose={() => setRejecting(false)}
          onDone={() => {
            setRejecting(false);
            reload();
          }}
        />
      )}
    </WorkspacePage>
  );
}
