"use client";

import { Button, Group, Modal, Pagination, SegmentedControl, Select, Stack, Table, Text, Textarea } from "@mantine/core";
import { useForm } from "@mantine/form";
import { IconPlus } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { inventoryApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import DocStatusBadge from "@/components/shared/DocStatusBadge";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import { DOCUMENT_STATUS_COLOR, STATUS_LABEL } from "@/modules/inventory/constants";
import { useLocations } from "@/modules/inventory/hooks/useLocations";
import { countSheetTab } from "@/modules/inventory/routes";
import { useAppDispatch } from "@/store/hooks";
import { openTab } from "@/store/slices/tabsSlice";
import type { CountStatus, StockCount } from "@/types/inventory";
import { PERMISSIONS } from "@/types/permissions";

/** Blind stock counts: counters never see the system figure; variances post only when approved. */
export default function StockCountsView({ title, section, tabId }: WorkspaceViewProps) {
  const dispatch = useAppDispatch();
  const { can } = usePermissions();
  const [status, setStatus] = useState<CountStatus | "all">("all");
  const [page, setPage] = useState(1);
  const [starting, setStarting] = useState(false);

  const fetchCounts = useCallback(() => inventoryApi.counts(status === "all" ? undefined : status, page), [status, page]);
  const { data, loading, error, reload } = useApiQuery(fetchCounts);
  const open = (count: Pick<StockCount, "id" | "number">) => dispatch(openTab(countSheetTab(count, tabId)));

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Count what is physically there without seeing the system figure. Differences are reviewed and approved by someone else."
      actions={
        can(PERMISSIONS.INVENTORY_COUNT) && (
          <Button leftSection={<IconPlus size={16} />} onClick={() => setStarting(true)}>
            Start a count
          </Button>
        )
      }
    >
      <SegmentedControl
        w="fit-content"
        value={status}
        onChange={(v) => {
          setStatus(v as CountStatus | "all");
          setPage(1);
        }}
        data={[
          { value: "all", label: "All" },
          { value: "counting", label: "Counting" },
          { value: "submitted", label: "Waiting approval" },
          { value: "approved", label: "Approved" },
        ]}
      />

      <DataCard>
        <QueryState loading={loading} error={error} isEmpty={!data?.items.length} emptyMessage="No counts yet." onRetry={reload}>
          <Table verticalSpacing="sm" highlightOnHover>
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Number</Table.Th>
                <Table.Th>Location</Table.Th>
                <Table.Th ta="right">Items</Table.Th>
                <Table.Th>Started by</Table.Th>
                <Table.Th>Status</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((c) => (
                <Table.Tr key={c.id} style={{ cursor: "pointer" }} onClick={() => open(c)}>
                  <Table.Td>
                    <Text size="sm" ff="monospace">
                      {c.number}
                    </Text>
                  </Table.Td>
                  <Table.Td>{c.location.name}</Table.Td>
                  <Table.Td ta="right">{c.lineCount}</Table.Td>
                  <Table.Td>
                    <Text size="sm">{c.createdBy?.name}</Text>
                    <Text size="xs" c="dimmed">
                      {c.createdAt ? dayjs(c.createdAt).format("DD MMM YYYY HH:mm") : ""}
                    </Text>
                  </Table.Td>
                  <Table.Td>
                    <DocStatusBadge label={STATUS_LABEL[c.status]} color={DOCUMENT_STATUS_COLOR[c.status]} />
                  </Table.Td>
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

      {starting && (
        <StartCountModal
          onClose={() => setStarting(false)}
          onStarted={(count) => {
            setStarting(false);
            reload();
            open(count);
          }}
        />
      )}
    </WorkspacePage>
  );
}

function StartCountModal({ onClose, onStarted }: { onClose: () => void; onStarted: (count: StockCount) => void }) {
  const locations = useLocations();
  const form = useForm<{ locationId: string | null; note: string }>({
    initialValues: { locationId: null, note: "" },
    validate: { locationId: (v) => (v ? null : "Choose where you are counting") },
  });

  const { mutate, pending } = useApiMutation((values: { locationId: string | null; note: string }) => inventoryApi.startCount(Number(values.locationId), values.note.trim() || null), {
    successMessage: (c) => `${c.number} started.`,
    onValidationError: (errors) => form.setErrors({ ...errors, locationId: errors.locationId ?? errors.variantIds }),
    onSuccess: onStarted,
  });

  return (
    <Modal opened onClose={onClose} title="Start a stock count" centered>
      <form onSubmit={form.onSubmit((values) => void mutate(values))} noValidate>
        <Stack>
          <Text size="sm" c="dimmed">
            The sheet lists every item recorded at the location. You can add items you find that are not on it.
          </Text>
          <Select label="Location" data={locations.options} required {...form.getInputProps("locationId")} />
          <Textarea label="Note" placeholder="e.g. Month-end count" autosize {...form.getInputProps("note")} />
          <Group justify="flex-end">
            <Button variant="default" onClick={onClose} disabled={pending}>
              Cancel
            </Button>
            <Button type="submit" loading={pending}>
              Start count
            </Button>
          </Group>
        </Stack>
      </form>
    </Modal>
  );
}
