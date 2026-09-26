"use client";

import { Alert, Badge, Button, Group, Modal, Pagination, SegmentedControl, SimpleGrid, Stack, Table, Text, Textarea } from "@mantine/core";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { salesApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import DocStatusBadge from "@/components/shared/DocStatusBadge";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import { PERMISSIONS } from "@/types/permissions";
import type { ShiftRow } from "@/types/sales";
import { formatKes } from "@/utils/money";

/** Till shifts: takings by payment method, the blind count, and manager sign-off. */
export default function ShiftsListView({ title, section }: WorkspaceViewProps) {
  const [page, setPage] = useState(1);
  const [filter, setFilter] = useState<"all" | "to_review">("to_review");
  const [open, setOpen] = useState<ShiftRow | null>(null);
  const fetchShifts = useCallback(() => salesApi.shifts(page, filter === "to_review" ? "to_review" : undefined), [page, filter]);
  const { data, loading, error, reload } = useApiQuery(fetchShifts);

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Cashiers count the drawer by note and coin without seeing the expected amount. Expected cash = float + cash sales − cash refunds − drops to the safe. A manager signs off every cash-up."
    >
      <SegmentedControl
        w="fit-content"
        value={filter}
        onChange={(v) => (setFilter(v as "all" | "to_review"), setPage(1))}
        data={[
          { value: "to_review", label: "Waiting for sign-off" },
          { value: "all", label: "All shifts" },
        ]}
      />
      <DataCard>
        <QueryState
          loading={loading}
          error={error}
          isEmpty={!data?.items.length}
          emptyMessage={filter === "to_review" ? "Every cash-up is signed off." : "No shifts yet."}
          onRetry={reload}
        >
          <Table verticalSpacing="sm" highlightOnHover style={{ whiteSpace: "nowrap" }}>
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Till · cashier</Table.Th>
                <Table.Th>Shift</Table.Th>
                <Table.Th ta="right">Sales</Table.Th>
                <Table.Th ta="right">Cash</Table.Th>
                <Table.Th ta="right">M-PESA</Table.Th>
                <Table.Th ta="right">Card</Table.Th>
                <Table.Th ta="right">To safe</Table.Th>
                <Table.Th ta="right">Expected</Table.Th>
                <Table.Th ta="right">Counted</Table.Th>
                <Table.Th ta="right">Variance</Table.Th>
                <Table.Th>Sign-off</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((shift) => (
                <Table.Tr key={shift.id} style={{ cursor: shift.isOpen ? undefined : "pointer" }} onClick={() => !shift.isOpen && setOpen(shift)}>
                  <Table.Td>
                    <Text size="sm" fw={600}>
                      {shift.tillName}
                    </Text>
                    <Text size="xs" c="dimmed">
                      {shift.user?.name}
                    </Text>
                  </Table.Td>
                  <Table.Td>
                    <Text size="sm">{dayjs(shift.openedAt).format("DD MMM, h:mm a")}</Text>
                    {shift.isOpen ? (
                      <DocStatusBadge label="Open" color="blue" />
                    ) : (
                      <Text size="xs" c="dimmed">
                        to {dayjs(shift.closedAt).format(dayjs(shift.closedAt).isSame(shift.openedAt, "day") ? "h:mm a" : "DD MMM, h:mm a")}
                      </Text>
                    )}
                  </Table.Td>
                  <Table.Td ta="right">{shift.salesCount}</Table.Td>
                  <Table.Td ta="right">{formatKes(shift.takings.cashCents)}</Table.Td>
                  <Table.Td ta="right">{formatKes(shift.takings.mpesaCents)}</Table.Td>
                  <Table.Td ta="right">{formatKes(shift.takings.cardCents)}</Table.Td>
                  <Table.Td ta="right">{shift.dropsCents > 0 ? formatKes(shift.dropsCents) : "—"}</Table.Td>
                  <Table.Td ta="right">{shift.isOpen ? "—" : formatKes(shift.expectedCashCents)}</Table.Td>
                  <Table.Td ta="right">{shift.isOpen ? "—" : formatKes(shift.countedCashCents)}</Table.Td>
                  <Table.Td ta="right">{shift.isOpen ? "—" : <Variance cents={shift.varianceCents ?? 0} />}</Table.Td>
                  <Table.Td>
                    {shift.isOpen ? (
                      "—"
                    ) : shift.reviewedAt ? (
                      <Text size="xs">Signed off · {shift.reviewedBy?.name}</Text>
                    ) : (
                      <DocStatusBadge label="Waiting" color={(shift.varianceCents ?? 0) === 0 ? "gray" : "red"} />
                    )}
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

      {open && (
        <CashUpModal
          shiftId={open.id}
          onClose={() => setOpen(null)}
          onSigned={() => {
            setOpen(null);
            reload();
          }}
        />
      )}
    </WorkspacePage>
  );
}

function Variance({ cents }: { cents: number }) {
  return (
    <Text size="sm" fw={700} c={cents === 0 ? "green.7" : "red.7"}>
      {cents > 0 ? "Over " : cents < 0 ? "Short " : ""}
      {formatKes(Math.abs(cents))}
    </Text>
  );
}

/** One cash-up: the count by denomination, drops to the safe, the cashier's reason, and sign-off. */
function CashUpModal({ shiftId, onClose, onSigned }: { shiftId: number; onClose: () => void; onSigned: () => void }) {
  const { can } = usePermissions();
  const fetchDetail = useCallback(() => salesApi.shiftDetail(shiftId), [shiftId]);
  const { data: shift, loading, error, reload } = useApiQuery(fetchDetail);
  const [note, setNote] = useState("");
  const variance = shift?.varianceCents ?? 0;

  const { mutate, pending } = useApiMutation(() => salesApi.reviewShift(shiftId, note.trim() || null), {
    successMessage: "Cash-up signed off.",
    onSuccess: onSigned,
  });

  return (
    <Modal opened onClose={onClose} title={shift ? `Cash-up · ${shift.tillName} · ${shift.user?.name ?? ""}` : "Cash-up"} size="lg" centered>
      <QueryState loading={loading} error={error} isEmpty={false} onRetry={reload}>
        {shift && (
          <Stack>
            <SimpleGrid cols={{ base: 2, sm: 4 }}>
              <Figure label="Opening float" value={formatKes(shift.openingFloatCents)} />
              <Figure label="Dropped to safe" value={formatKes(shift.dropsCents)} />
              <Figure label="Expected" value={formatKes(shift.expectedCashCents)} />
              <Figure label="Counted" value={formatKes(shift.countedCashCents)} />
            </SimpleGrid>
            <Group justify="space-between">
              <Text fw={600}>Difference</Text>
              <Variance cents={variance} />
            </Group>

            {shift.countBreakdown && (
              <Table verticalSpacing={2}>
                <Table.Thead>
                  <Table.Tr>
                    <Table.Th>Counted</Table.Th>
                    <Table.Th ta="right">Pieces</Table.Th>
                    <Table.Th ta="right">Value</Table.Th>
                  </Table.Tr>
                </Table.Thead>
                <Table.Tbody>
                  {shift.countBreakdown.map((row) => (
                    <Table.Tr key={row.denominationCents}>
                      <Table.Td>KES {(row.denominationCents / 100).toLocaleString("en-KE")}</Table.Td>
                      <Table.Td ta="right">{row.count}</Table.Td>
                      <Table.Td ta="right">{formatKes(row.denominationCents * row.count)}</Table.Td>
                    </Table.Tr>
                  ))}
                </Table.Tbody>
              </Table>
            )}

            {shift.drops.length > 0 && (
              <Stack gap={4}>
                <Text fw={600} size="sm">
                  Drops to the safe
                </Text>
                {shift.drops.map((d) => (
                  <Text key={d.id} size="sm">
                    {dayjs(d.at).format("h:mm a")} · {formatKes(d.amountCents)} · witnessed by {d.witness}
                    {d.note ? ` · ${d.note}` : ""}
                  </Text>
                ))}
              </Stack>
            )}

            {variance !== 0 && (
              <Alert color={shift.varianceReason ? "yellow" : "red"} title="Cashier's reason">
                {shift.varianceReason ?? "No reason given."}
              </Alert>
            )}
            {shift.closeNote && (
              <Text size="sm" c="dimmed">
                Note at close: {shift.closeNote}
              </Text>
            )}

            {shift.reviewedAt ? (
              <Alert color="green" title={`Signed off by ${shift.reviewedBy?.name ?? "a manager"} · ${dayjs(shift.reviewedAt).format("D MMM, h:mm a")}`}>
                {shift.reviewNote ?? "No note."}
              </Alert>
            ) : can(PERMISSIONS.SHIFTS_CASHUP_APPROVE) ? (
              <>
                <Textarea
                  label={variance === 0 ? "Note (optional)" : "What was done about the difference?"}
                  required={variance !== 0}
                  autosize
                  minRows={2}
                  value={note}
                  onChange={(e) => setNote(e.currentTarget.value)}
                />
                <Group justify="flex-end">
                  <Button variant="default" onClick={onClose} disabled={pending}>
                    Close
                  </Button>
                  <Button onClick={() => void mutate()} loading={pending} disabled={variance !== 0 && !note.trim()}>
                    Sign off cash-up
                  </Button>
                </Group>
              </>
            ) : (
              <Badge color="gray">Waiting for a manager to sign off</Badge>
            )}
          </Stack>
        )}
      </QueryState>
    </Modal>
  );
}

function Figure({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <Text size="xs" c="dimmed">
        {label}
      </Text>
      <Text fw={700}>{value}</Text>
    </div>
  );
}
