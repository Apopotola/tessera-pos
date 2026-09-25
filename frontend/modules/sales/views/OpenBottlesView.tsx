"use client";

import { Button, Group, Pagination, Progress, SegmentedControl, Table, Text } from "@mantine/core";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { salesApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import DocStatusBadge from "@/components/shared/DocStatusBadge";
import NoteModal from "@/components/shared/NoteModal";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import { PERMISSIONS } from "@/types/permissions";
import type { OpenBottle } from "@/types/sales";
import { formatKes } from "@/utils/money";

const STATUS = {
  open: { label: "Open", color: "blue" },
  finished: { label: "Finished", color: "green" },
  written_off: { label: "Written off", color: "red" },
} as const;

/** Bottles sold by the tot: what each has poured, what is left and what was written off. */
export default function OpenBottlesView({ title, section }: WorkspaceViewProps) {
  const { can } = usePermissions();
  const [status, setStatus] = useState<"open" | "closed">("open");
  const [page, setPage] = useState(1);
  const [writingOff, setWritingOff] = useState<OpenBottle | null>(null);

  const fetchBottles = useCallback(() => salesApi.openBottles(status, page), [status, page]);
  const { data, loading, error, reload } = useApiQuery(fetchBottles);
  const showCost = data?.items.some((b) => b.costCents !== null) ?? false;

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Selling a tot pours from the bar's open bottle; when it runs dry the next bottle is taken off the shelf. Write off what is left if a bottle is spilt or spoilt."
    >
      <SegmentedControl
        w="fit-content"
        value={status}
        onChange={(v) => {
          setStatus(v as "open" | "closed");
          setPage(1);
        }}
        data={[
          { value: "open", label: "Open now" },
          { value: "closed", label: "Finished & written off" },
        ]}
      />

      <DataCard>
        <QueryState loading={loading} error={error} isEmpty={!data?.items.length} emptyMessage={status === "open" ? "No bottles open at the bar." : "No closed bottles yet."} onRetry={reload}>
          <Table verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Bottle</Table.Th>
                <Table.Th>Opened</Table.Th>
                <Table.Th w={220}>Poured</Table.Th>
                <Table.Th ta="right">Tots sold</Table.Th>
                <Table.Th ta="right">Written off</Table.Th>
                {showCost && <Table.Th ta="right">Bottle cost</Table.Th>}
                <Table.Th>Status</Table.Th>
                <Table.Th />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((bottle) => {
                const tot = bottle.variant.totMl;
                return (
                  <Table.Tr key={bottle.id}>
                    <Table.Td>
                      <Text size="sm" fw={600}>
                        {bottle.variant.displayName}
                      </Text>
                      <Text size="xs" c="dimmed" ff="monospace">
                        {bottle.number} · {bottle.branch.name}
                      </Text>
                    </Table.Td>
                    <Table.Td>
                      <Text size="sm">{dayjs(bottle.openedAt).format("DD MMM, h:mm a")}</Text>
                      <Text size="xs" c="dimmed">
                        {bottle.openedBy}
                      </Text>
                    </Table.Td>
                    <Table.Td>
                      <Progress value={((bottle.volumeMl - bottle.remainingMl) / bottle.volumeMl) * 100} size="sm" />
                      <Text size="xs" c="dimmed" mt={4}>
                        {bottle.remainingMl}ml of {bottle.volumeMl}ml left
                      </Text>
                    </Table.Td>
                    <Table.Td ta="right">{tot ? Math.round(bottle.soldMl / tot) : "—"}</Table.Td>
                    <Table.Td ta="right">
                      <Text size="sm" c={bottle.writtenOffMl > 0 ? "red.7" : undefined}>
                        {bottle.writtenOffMl}ml
                      </Text>
                    </Table.Td>
                    {showCost && <Table.Td ta="right">{formatKes(bottle.costCents)}</Table.Td>}
                    <Table.Td>
                      <DocStatusBadge label={STATUS[bottle.status].label} color={STATUS[bottle.status].color} />
                    </Table.Td>
                    <Table.Td>
                      {bottle.status === "open" && can(PERMISSIONS.INVENTORY_ADJUST_APPROVE) && (
                        <Button size="xs" variant="light" color="red" onClick={() => setWritingOff(bottle)}>
                          Write off rest
                        </Button>
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

      {writingOff && (
        <NoteModal
          title={`Write off ${writingOff.number}`}
          description={`${writingOff.remainingMl}ml of ${writingOff.variant.displayName} will be recorded as lost and the bottle closed. The next tot opens a new bottle.`}
          label="Reason"
          confirmLabel="Write off"
          danger
          successMessage="Bottle written off."
          onSubmit={(reason) => salesApi.writeOffBottle(writingOff.id, reason)}
          onClose={() => setWritingOff(null)}
          onDone={() => {
            setWritingOff(null);
            reload();
          }}
        />
      )}
    </WorkspacePage>
  );
}
