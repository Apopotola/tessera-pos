"use client";

import { Alert, Button, Group, Modal, Pagination, SegmentedControl, SimpleGrid, Stack, Table, Text, TextInput, Title, UnstyledButton } from "@mantine/core";
import { DateInput } from "@mantine/dates";
import { useDebouncedValue } from "@mantine/hooks";
import { IconSearch } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { paymentsApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import DocStatusBadge from "@/components/shared/DocStatusBadge";
import QueryState from "@/components/shared/QueryState";
import StatTile from "@/components/shared/StatTile";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import type { UnverifiedMpesaTender } from "@/types/payments";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes } from "@/utils/money";

type Status = "all" | "matched" | "unallocated";

/**
 * End-of-day M-PESA check: every payment Safaricom confirmed, the sale it paid for, and
 * what still needs matching — payments nobody used, and codes typed in without confirmation.
 */
export default function MpesaReconciliationView({ title, section }: WorkspaceViewProps) {
  const { can } = usePermissions();
  const [from, setFrom] = useState<string | null>(dayjs().format("YYYY-MM-DD"));
  const [to, setTo] = useState<string | null>(dayjs().format("YYYY-MM-DD"));
  const [status, setStatus] = useState<Status>("all");
  const [search, setSearch] = useState("");
  const [debounced] = useDebouncedValue(search.trim(), 300);
  const [page, setPage] = useState(1);
  const [matching, setMatching] = useState<UnverifiedMpesaTender | null>(null);

  const fetchConfirmations = useCallback(
    () => paymentsApi.confirmations({ from: from ?? undefined, to: to ?? undefined, status, search: debounced || undefined, page }),
    [from, to, status, debounced, page],
  );
  const confirmations = useApiQuery(fetchConfirmations);
  const fetchUnverified = useCallback(() => paymentsApi.unverified(), []);
  const unverified = useApiQuery(fetchUnverified);
  const summary = confirmations.data?.summary;

  const reloadAll = () => {
    confirmations.reload();
    unverified.reload();
  };

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="M-PESA counts as paid only when Safaricom confirms it. Match anything left over before closing the day."
    >
      <SimpleGrid cols={{ base: 2, md: 4 }}>
        <StatTile label="Matched to sales" value={formatKes(summary?.matchedCents)} hint={`${summary?.matchedCount ?? 0} payments`} />
        <StatTile
          label="Not on any sale"
          value={formatKes(summary?.unallocatedCents)}
          hint={`${summary?.unallocatedCount ?? 0} payments to investigate`}
          highlight={(summary?.unallocatedCount ?? 0) > 0}
          onClick={() => (setStatus("unallocated"), setPage(1))}
        />
        <StatTile
          label="Typed codes not confirmed"
          value={unverified.data?.length ?? 0}
          hint={formatKes(unverified.data?.reduce((sum, t) => sum + t.amountCents, 0) ?? 0)}
          highlight={(unverified.data?.length ?? 0) > 0}
        />
      </SimpleGrid>

      {(unverified.data?.length ?? 0) > 0 && (
        <DataCard>
          <Stack gap="sm" p="md">
            <Title order={4}>Typed M-PESA codes waiting for confirmation</Title>
            <Text size="sm" c="dimmed">
              Taken while M-PESA was in manual mode. Match each one to Safaricom&apos;s confirmation of the same amount.
            </Text>
          </Stack>
          <Table verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Sale</Table.Th>
                <Table.Th>Code typed</Table.Th>
                <Table.Th>Cashier</Table.Th>
                <Table.Th ta="right">Amount</Table.Th>
                <Table.Th />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {unverified.data?.map((t) => (
                <Table.Tr key={t.id}>
                  <Table.Td>
                    <Text size="sm" ff="monospace" fw={600}>
                      {t.sale?.number ?? "—"}
                    </Text>
                    <Text size="xs" c="dimmed">
                      {t.createdAt ? dayjs(t.createdAt).format("DD MMM, h:mm a") : ""}
                    </Text>
                  </Table.Td>
                  <Table.Td ff="monospace">{t.reference ?? "—"}</Table.Td>
                  <Table.Td>{t.cashier}</Table.Td>
                  <Table.Td ta="right">{formatKes(t.amountCents)}</Table.Td>
                  <Table.Td ta="right">
                    {can(PERMISSIONS.PAYMENTS_RECONCILE) && (
                      <Button size="xs" variant={t.suggestedConfirmationId ? "filled" : "light"} onClick={() => setMatching(t)}>
                        {t.suggestedConfirmationId ? "Confirm match" : "Match"}
                      </Button>
                    )}
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </DataCard>
      )}

      <Group align="flex-end">
        <SegmentedControl
          value={status}
          onChange={(v) => (setStatus(v as Status), setPage(1))}
          data={[
            { value: "all", label: "All" },
            { value: "unallocated", label: "Not on a sale" },
            { value: "matched", label: "Matched" },
          ]}
        />
        <DateInput label="From" valueFormat="DD MMM YYYY" clearable maxDate={new Date()} value={from} onChange={(v) => (setFrom(v), setPage(1))} w={160} />
        <DateInput label="To" valueFormat="DD MMM YYYY" clearable maxDate={new Date()} value={to} onChange={(v) => (setTo(v), setPage(1))} w={160} />
        <TextInput label="M-PESA code" leftSection={<IconSearch size={16} />} value={search} onChange={(e) => (setSearch(e.currentTarget.value), setPage(1))} w={200} />
      </Group>

      <DataCard>
        <QueryState
          loading={confirmations.loading}
          error={confirmations.error}
          isEmpty={!confirmations.data?.items.length}
          emptyMessage="No M-PESA payments in this period."
          onRetry={confirmations.reload}
        >
          <Table verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>M-PESA code</Table.Th>
                <Table.Th>Paid by</Table.Th>
                <Table.Th>How</Table.Th>
                <Table.Th ta="right">Amount</Table.Th>
                <Table.Th>Sale</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {confirmations.data?.items.map((c) => (
                <Table.Tr key={c.id}>
                  <Table.Td>
                    <Text size="sm" ff="monospace" fw={600}>
                      {c.receipt}
                    </Text>
                    <Text size="xs" c="dimmed">
                      {dayjs(c.transactedAt).format("DD MMM, h:mm a")}
                    </Text>
                  </Table.Td>
                  <Table.Td>
                    <Text size="sm">{c.payerName ?? "—"}</Text>
                    <Text size="xs" c="dimmed">
                      {c.phoneMasked}
                    </Text>
                  </Table.Td>
                  <Table.Td>
                    <Text size="sm">{c.source === "stk" ? "Payment request" : "Paid to till"}</Text>
                    {c.billReference && (
                      <Text size="xs" c="dimmed">
                        Ref {c.billReference}
                      </Text>
                    )}
                  </Table.Td>
                  <Table.Td ta="right">{formatKes(c.amountCents)}</Table.Td>
                  <Table.Td>
                    {c.sale ? (
                      <>
                        <Text size="sm" ff="monospace">
                          {c.sale.number}
                        </Text>
                        {c.allocatedBy && (
                          <Text size="xs" c="dimmed">
                            {c.allocatedBy}
                          </Text>
                        )}
                      </>
                    ) : (
                      <DocStatusBadge label="Not on a sale" color="yellow" />
                    )}
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </QueryState>
      </DataCard>

      {confirmations.data && confirmations.data.meta.lastPage > 1 && (
        <Group justify="flex-end">
          <Pagination value={page} onChange={setPage} total={confirmations.data.meta.lastPage} size="sm" />
        </Group>
      )}

      {matching && (
        <MatchModal
          tender={matching}
          onClose={() => setMatching(null)}
          onMatched={() => {
            setMatching(null);
            reloadAll();
          }}
        />
      )}
    </WorkspacePage>
  );
}

/** Pick the confirmation of the same amount; the one with the typed code is suggested first. */
function MatchModal({ tender, onClose, onMatched }: { tender: UnverifiedMpesaTender; onClose: () => void; onMatched: () => void }) {
  const fetchCandidates = useCallback(() => paymentsApi.confirmations({ status: "unallocated" }), []);
  const { data, loading } = useApiQuery(fetchCandidates);
  const candidates = (data?.items ?? [])
    .filter((c) => c.amountCents === tender.amountCents)
    .sort((a, b) => Number(b.id === tender.suggestedConfirmationId) - Number(a.id === tender.suggestedConfirmationId));
  const [selected, setSelected] = useState<number | null>(tender.suggestedConfirmationId);

  const { mutate, pending } = useApiMutation((confirmationId: number) => paymentsApi.match(confirmationId, tender.id), {
    successMessage: (row) => `${row.receipt} matched to ${row.sale?.number ?? "the sale"}.`,
    onSuccess: onMatched,
  });

  return (
    <Modal opened onClose={onClose} title={`Match ${tender.sale?.number ?? "payment"} · ${formatKes(tender.amountCents)}`} centered>
      <Stack gap="sm">
        <Text size="sm" c="dimmed">
          The cashier typed <b>{tender.reference ?? "no code"}</b>. Choose Safaricom&apos;s confirmation for this payment.
        </Text>
        {!loading && candidates.length === 0 && <Alert color="yellow">No unmatched M-PESA payment of {formatKes(tender.amountCents)} yet.</Alert>}
        {candidates.map((c) => (
          <UnstyledButton
            key={c.id}
            onClick={() => setSelected(c.id)}
            style={{
              padding: "10px 12px",
              borderRadius: 10,
              border: `2px solid ${selected === c.id ? "var(--mantine-color-tessera-6)" : "var(--mantine-color-gray-3)"}`,
            }}
          >
            <Group justify="space-between">
              <div>
                <Text ff="monospace" fw={600} size="sm">
                  {c.receipt} {c.id === tender.suggestedConfirmationId && "· same code"}
                </Text>
                <Text size="xs" c="dimmed">
                  {[c.payerName, c.phoneMasked, dayjs(c.transactedAt).format("DD MMM, h:mm a")].filter(Boolean).join(" · ")}
                </Text>
              </div>
              <Text fw={700}>{formatKes(c.amountCents)}</Text>
            </Group>
          </UnstyledButton>
        ))}
        <Group justify="flex-end">
          <Button variant="default" onClick={onClose} disabled={pending}>
            Cancel
          </Button>
          <Button disabled={!selected} loading={pending} onClick={() => selected && void mutate(selected)}>
            Match
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}
