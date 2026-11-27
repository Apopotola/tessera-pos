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
import type { CashCheck, ShiftRow } from "@/types/sales";
import { formatKes } from "@/utils/money";

/** Till shifts: takings by payment method, the blind count, and manager sign-off. */
export default function ShiftsListView({ title, section }: WorkspaceViewProps) {
  const [page, setPage] = useState(1);
  const [filter, setFilter] = useState<"all" | "to_review" | "checks">("to_review");
  const [open, setOpen] = useState<ShiftRow | null>(null);
  const fetchShifts = useCallback(() => salesApi.shifts(page, filter === "to_review" ? "to_review" : undefined), [page, filter]);
  const { data, loading, error, reload } = useApiQuery(fetchShifts);

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Cashiers count the drawer by note and coin without seeing the expected amount. Expected cash = float (or the last cash check) + cash sales − cash refunds − drops to the safe − pay-outs. A manager signs off every cash-up and cash check."
    >
      <SegmentedControl
        w="fit-content"
        value={filter}
        onChange={(v) => (setFilter(v as "all" | "to_review" | "checks"), setPage(1))}
        data={[
          { value: "to_review", label: "Waiting for sign-off" },
          { value: "all", label: "All shifts" },
          { value: "checks", label: "Cash checks" },
        ]}
      />
      {filter !== "checks" && (
        <>
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
        </>
      )}
      {filter !== "all" && <CashChecksCard status={filter === "to_review" ? "to_review" : undefined} />}

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

/**
 * Cash checks on a shared till that stays open: a manager counted the drawer without closing it.
 * Waiting for sign-off, only the ones not signed off (hidden when there are none).
 */
function CashChecksCard({ status }: { status?: "to_review" }) {
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<CashCheck | null>(null);
  const fetchChecks = useCallback(() => salesApi.cashChecks(page, status), [page, status]);
  const { data, loading, error, reload } = useApiQuery(fetchChecks);

  if (status === "to_review" && !loading && !error && !data?.items.length) return null;

  return (
    <>
      {status === "to_review" && (
        <Text fw={600} mt="sm">
          Cash checks (till kept open)
        </Text>
      )}
      <DataCard>
        <QueryState loading={loading} error={error} isEmpty={!data?.items.length} emptyMessage="No cash checks yet. A manager does them from the till menu on a shared till." onRetry={reload}>
          <Table verticalSpacing="sm" highlightOnHover style={{ whiteSpace: "nowrap" }}>
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Till · counted by</Table.Th>
                <Table.Th>Period</Table.Th>
                <Table.Th ta="right">Expected</Table.Th>
                <Table.Th ta="right">Counted</Table.Th>
                <Table.Th ta="right">Variance</Table.Th>
                <Table.Th>Sign-off</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((check) => (
                <Table.Tr key={check.id} style={{ cursor: "pointer" }} onClick={() => setOpen(check)}>
                  <Table.Td>
                    <Text size="sm" fw={600}>
                      {check.tillName}
                    </Text>
                    <Text size="xs" c="dimmed">
                      {check.countedBy?.name}
                    </Text>
                  </Table.Td>
                  <Table.Td>
                    <Text size="sm">{dayjs(check.countedAt).format("DD MMM, h:mm a")}</Text>
                    <Text size="xs" c="dimmed">
                      since {dayjs(check.periodStartedAt).format(dayjs(check.periodStartedAt).isSame(check.countedAt, "day") ? "h:mm a" : "DD MMM, h:mm a")}
                    </Text>
                  </Table.Td>
                  <Table.Td ta="right">{formatKes(check.expectedCashCents)}</Table.Td>
                  <Table.Td ta="right">{formatKes(check.countedCashCents)}</Table.Td>
                  <Table.Td ta="right">
                    <Variance cents={check.varianceCents} />
                  </Table.Td>
                  <Table.Td>
                    {check.reviewedAt ? <Text size="xs">Signed off · {check.reviewedBy?.name}</Text> : <DocStatusBadge label="Waiting" color={check.varianceCents === 0 ? "gray" : "red"} />}
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
        <CashCheckSignOffModal
          check={open}
          onClose={() => setOpen(null)}
          onSigned={() => {
            setOpen(null);
            reload();
          }}
        />
      )}
    </>
  );
}

/** One cash check: the count by denomination, the note, and sign-off by another manager. */
function CashCheckSignOffModal({ check, onClose, onSigned }: { check: CashCheck; onClose: () => void; onSigned: () => void }) {
  const { can } = usePermissions();
  const [note, setNote] = useState("");
  const variance = check.varianceCents;
  const { mutate, pending } = useApiMutation(() => salesApi.reviewCashCheck(check.id, note.trim() || null), {
    successMessage: "Cash check signed off.",
    onSuccess: onSigned,
  });

  return (
    <Modal opened onClose={onClose} title={`Cash check · ${check.tillName ?? ""} · ${dayjs(check.countedAt).format("D MMM, h:mm a")}`} size="lg" centered>
      <Stack>
        <Text size="sm" c="dimmed">
          Counted by {check.countedBy?.name ?? "a manager"} while the till stayed open, for the cash taken since {dayjs(check.periodStartedAt).format("D MMM, h:mm a")}.
        </Text>
        <SimpleGrid cols={3}>
          <Figure label="Expected" value={formatKes(check.expectedCashCents)} />
          <Figure label="Counted" value={formatKes(check.countedCashCents)} />
          <div>
            <Text size="xs" c="dimmed">
              Difference
            </Text>
            <Variance cents={variance} />
          </div>
        </SimpleGrid>
        {check.countBreakdown && (
          <Table verticalSpacing={2}>
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Counted</Table.Th>
                <Table.Th ta="right">Pieces</Table.Th>
                <Table.Th ta="right">Value</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {check.countBreakdown.map((row) => (
                <Table.Tr key={row.denominationCents}>
                  <Table.Td>KES {(row.denominationCents / 100).toLocaleString("en-KE")}</Table.Td>
                  <Table.Td ta="right">{row.count}</Table.Td>
                  <Table.Td ta="right">{formatKes(row.denominationCents * row.count)}</Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        )}
        {check.note && (
          <Text size="sm" c="dimmed">
            Note at the count: {check.note}
          </Text>
        )}
        {check.reviewedAt ? (
          <Alert color="green" title={`Signed off by ${check.reviewedBy?.name ?? "a manager"} · ${dayjs(check.reviewedAt).format("D MMM, h:mm a")}`}>
            {check.reviewNote ?? "No note."}
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
            <Text size="xs" c="dimmed">
              A manager other than the one who counted signs it off.
            </Text>
            <Group justify="flex-end">
              <Button variant="default" onClick={onClose} disabled={pending}>
                Close
              </Button>
              <Button onClick={() => void mutate()} loading={pending} disabled={variance !== 0 && !note.trim()}>
                Sign off cash check
              </Button>
            </Group>
          </>
        ) : (
          <Badge color="gray">Waiting for a manager to sign off</Badge>
        )}
      </Stack>
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
