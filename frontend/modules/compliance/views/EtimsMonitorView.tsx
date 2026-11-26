"use client";

import { Alert, Button, Group, Pagination, SegmentedControl, SimpleGrid, Stack, Table, Tabs, Text, TextInput } from "@mantine/core";
import { DateInput } from "@mantine/dates";
import { useDebouncedValue } from "@mantine/hooks";
import { IconAlertTriangle, IconSearch } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { complianceApi } from "@/api";
import type { EtimsFilter } from "@/api/endpoints/compliance";
import DataCard from "@/components/shared/DataCard";
import DocStatusBadge from "@/components/shared/DocStatusBadge";
import QueryState from "@/components/shared/QueryState";
import StatTile from "@/components/shared/StatTile";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import type { EtimsSubmissionRow } from "@/types/compliance";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes } from "@/utils/money";

const STATUS = {
  pending: { label: "Pending", color: "gray" },
  signed: { label: "Signed", color: "green" },
  failed: { label: "Retrying", color: "yellow" },
  rejected: { label: "Needs fixing", color: "red" },
} as const;

/**
 * Every sales invoice and credit note on its way to KRA eTIMS. A sale is only compliant
 * once KRA has signed it; this screen shows what is still waiting, what KRA refused, and a
 * daily check of POS sales against signed invoices.
 */
export default function EtimsMonitorView({ title, section }: WorkspaceViewProps) {
  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Sales and returns are queued for KRA eTIMS as they happen. A sale counts as compliant only once KRA signs it."
    >
      <Tabs defaultValue="submissions" keepMounted={false}>
        <Tabs.List mb="md">
          <Tabs.Tab value="submissions">Invoices & credit notes</Tabs.Tab>
          <Tabs.Tab value="daily">Daily check</Tabs.Tab>
        </Tabs.List>
        <Tabs.Panel value="submissions">
          <Submissions />
        </Tabs.Panel>
        <Tabs.Panel value="daily">
          <DailyCheck />
        </Tabs.Panel>
      </Tabs>
    </WorkspacePage>
  );
}

function Submissions() {
  const { can } = usePermissions();
  const [status, setStatus] = useState<EtimsFilter>("all");
  const [search, setSearch] = useState("");
  const [debounced] = useDebouncedValue(search.trim(), 300);
  const [page, setPage] = useState(1);

  const fetchPage = useCallback(() => complianceApi.submissions({ status, search: debounced || undefined, page }), [status, debounced, page]);
  const { data, loading, error, reload } = useApiQuery(fetchPage);
  const summary = data?.summary;

  const retry = useApiMutation((row: EtimsSubmissionRow) => complianceApi.retry(row.id), {
    successMessage: (result) => (result.status === "signed" ? "Signed by KRA." : "Sent again — see the status for the result."),
    onSuccess: reload,
  });

  return (
    <Stack gap="lg">
      {summary?.driver === "disabled" && (
        <Alert color="yellow" icon={<IconAlertTriangle size={18} />}>
          eTIMS is not connected on this server. Invoices are queued but nothing is sent to KRA.
        </Alert>
      )}
      {summary?.driver === "fake" && (
        <Alert color="blue" variant="light">
          Demo mode: invoices are signed by a mock of KRA eTIMS. Nothing reaches KRA and demo receipts say so.
        </Alert>
      )}

      <SimpleGrid cols={{ base: 2, md: 4 }}>
        <StatTile label="Signed by KRA" value={summary?.signed ?? 0} />
        <StatTile label="Waiting" value={(summary?.pending ?? 0) + (summary?.failed ?? 0)} hint={summary?.failed ? `${summary.failed} retrying` : undefined} />
        <StatTile
          label={`Waiting over ${summary?.alertMinutes ?? 60} min`}
          value={summary?.waitingOverThreshold ?? 0}
          highlight={(summary?.waitingOverThreshold ?? 0) > 0}
        />
        <StatTile
          label="Needs fixing"
          value={summary?.rejected ?? 0}
          highlight={(summary?.rejected ?? 0) > 0}
          onClick={() => (setStatus("rejected"), setPage(1))}
        />
      </SimpleGrid>

      <Group align="flex-end">
        <SegmentedControl
          value={status}
          onChange={(v) => (setStatus(v as EtimsFilter), setPage(1))}
          data={[
            { value: "all", label: "All" },
            { value: "attention", label: "Needs attention" },
            { value: "pending", label: "Pending" },
            { value: "signed", label: "Signed" },
          ]}
        />
        <TextInput label="Document number" leftSection={<IconSearch size={16} />} value={search} onChange={(e) => (setSearch(e.currentTarget.value), setPage(1))} w={220} />
      </Group>

      <DataCard>
        <QueryState loading={loading} error={error} isEmpty={!data?.items.length} emptyMessage="Nothing here." onRetry={reload}>
          <Table verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Document</Table.Th>
                <Table.Th>Branch</Table.Th>
                <Table.Th>Status</Table.Th>
                <Table.Th>KRA invoice</Table.Th>
                <Table.Th>Problem</Table.Th>
                <Table.Th />
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.items.map((row) => (
                <Table.Tr key={row.id}>
                  <Table.Td>
                    <Text size="sm" ff="monospace" fw={600}>
                      {row.documentNumber}
                    </Text>
                    <Text size="xs" c="dimmed">
                      {row.documentType === "sale" ? "Sales invoice" : `Credit note for ${row.originalDocumentNumber}`} ·{" "}
                      {row.createdAt ? dayjs(row.createdAt).format("DD MMM, h:mm a") : ""}
                    </Text>
                  </Table.Td>
                  <Table.Td>{row.branch}</Table.Td>
                  <Table.Td>
                    <DocStatusBadge label={STATUS[row.status].label} color={STATUS[row.status].color} />
                    {row.status === "failed" && row.nextAttemptAt && (
                      <Text size="xs" c="dimmed">
                        Next try {dayjs(row.nextAttemptAt).format("h:mm a")} · {row.attempts} attempts
                      </Text>
                    )}
                  </Table.Td>
                  <Table.Td>
                    <Text size="sm" ff="monospace">
                      {row.kraInvoiceNumber ?? "—"}
                    </Text>
                    {row.signedAt && (
                      <Text size="xs" c="dimmed">
                        {dayjs(row.signedAt).format("DD MMM, h:mm a")}
                      </Text>
                    )}
                  </Table.Td>
                  <Table.Td maw={320}>
                    <Text size="sm" c={row.status === "rejected" ? "red.7" : "dimmed"}>
                      {row.lastError ?? ""}
                    </Text>
                  </Table.Td>
                  <Table.Td ta="right">
                    {row.status !== "signed" && can(PERMISSIONS.COMPLIANCE_MANAGE) && (
                      <Button size="xs" variant="light" loading={retry.pending} onClick={() => void retry.mutate(row)}>
                        Send now
                      </Button>
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
    </Stack>
  );
}

function DailyCheck() {
  const [from, setFrom] = useState<string | null>(dayjs().subtract(6, "day").format("YYYY-MM-DD"));
  const [to, setTo] = useState<string | null>(dayjs().format("YYYY-MM-DD"));
  const fetchRows = useCallback(() => complianceApi.reconciliation(from ?? dayjs().format("YYYY-MM-DD"), to ?? dayjs().format("YYYY-MM-DD")), [from, to]);
  const { data, loading, error, reload } = useApiQuery(fetchRows);

  return (
    <Stack gap="lg">
      <Group align="flex-end">
        <DateInput label="From" valueFormat="DD MMM YYYY" maxDate={new Date()} value={from} onChange={setFrom} w={170} />
        <DateInput label="To" valueFormat="DD MMM YYYY" maxDate={new Date()} value={to} onChange={setTo} w={170} />
      </Group>
      <DataCard>
        <QueryState loading={loading} error={error} isEmpty={!data?.length} emptyMessage="No sales in this period." onRetry={reload}>
          <Table verticalSpacing="sm">
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Day</Table.Th>
                <Table.Th>Branch</Table.Th>
                <Table.Th ta="right">POS sales</Table.Th>
                <Table.Th ta="right">Signed by KRA</Table.Th>
                <Table.Th ta="right">Credit notes</Table.Th>
                <Table.Th ta="right">Signed</Table.Th>
                <Table.Th>Check</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {data?.map((row) => (
                <Table.Tr key={`${row.day}-${row.branch}`}>
                  <Table.Td>{dayjs(row.day).format("ddd D MMM")}</Table.Td>
                  <Table.Td>{row.branch}</Table.Td>
                  <Table.Td ta="right">
                    {row.salesCount} · {formatKes(row.salesCents)}
                  </Table.Td>
                  <Table.Td ta="right">
                    {row.signedSalesCount} · {formatKes(row.signedSalesCents)}
                  </Table.Td>
                  <Table.Td ta="right">
                    {row.creditNotesCount} · {formatKes(row.creditNotesCents)}
                  </Table.Td>
                  <Table.Td ta="right">
                    {row.signedCreditNotesCount} · {formatKes(row.signedCreditNotesCents)}
                  </Table.Td>
                  <Table.Td>
                    <DocStatusBadge label={row.matches ? "All signed" : "Gap"} color={row.matches ? "green" : "red"} />
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </QueryState>
      </DataCard>
    </Stack>
  );
}
