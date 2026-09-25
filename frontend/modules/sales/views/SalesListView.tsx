"use client";

import { Button, Group, Modal, Pagination, SimpleGrid, Stack, Table, Text, TextInput } from "@mantine/core";
import { DateInput } from "@mantine/dates";
import { useDebouncedValue } from "@mantine/hooks";
import { IconPrinter, IconSearch } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useEffect, useState } from "react";
import { salesApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import DocStatusBadge from "@/components/shared/DocStatusBadge";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiQuery } from "@/hooks/useApiQuery";
import Receipt, { ReceiptPrint } from "@/modules/sales/components/Receipt";
import type { Sale } from "@/types/sales";
import { formatKes } from "@/utils/money";

const STATUS = {
  completed: { label: "Completed", color: "green" },
  partially_returned: { label: "Part returned", color: "yellow" },
  returned: { label: "Returned", color: "red" },
} as const;

const ETIMS = {
  pending: { label: "Pending", color: "gray" },
  signed: { label: "Signed", color: "green" },
  failed: { label: "Retrying", color: "yellow" },
  rejected: { label: "Needs fixing", color: "red" },
} as const;

const METHOD = { cash: "Cash", mpesa: "M-PESA", card: "Card" } as const;

/** Every till sale, newest first. Cost and profit show only to roles that may see them. */
export default function SalesListView({ title, section }: WorkspaceViewProps) {
  const [from, setFrom] = useState<string | null>(dayjs().format("YYYY-MM-DD"));
  const [to, setTo] = useState<string | null>(dayjs().format("YYYY-MM-DD"));
  const [search, setSearch] = useState("");
  const [debounced] = useDebouncedValue(search.trim(), 300);
  const [page, setPage] = useState(1);
  const [open, setOpen] = useState<Sale | null>(null);

  const fetchSales = useCallback(
    () => salesApi.sales({ from: from ?? undefined, to: to ?? undefined, search: debounced || undefined, page }),
    [from, to, debounced, page],
  );
  const { data, loading, error, reload } = useApiQuery(fetchSales);
  const items = data?.items ?? [];
  const showProfit = items.some((s) => s.grossProfitCents !== null);
  const pageTotal = items.reduce((sum, s) => sum + s.totalCents - s.returnedCents, 0);

  return (
    <WorkspacePage section={section} title={title} description="Receipts from every till. Open one to see the lines, payments and any returns, or print a copy.">
      <Group align="flex-end">
        <DateInput label="From" valueFormat="DD MMM YYYY" clearable maxDate={new Date()} value={from} onChange={(v) => (setFrom(v), setPage(1))} w={170} />
        <DateInput label="To" valueFormat="DD MMM YYYY" clearable maxDate={new Date()} value={to} onChange={(v) => (setTo(v), setPage(1))} w={170} />
        <TextInput
          label="Receipt number"
          placeholder="e.g. S-000123"
          leftSection={<IconSearch size={16} />}
          value={search}
          onChange={(e) => (setSearch(e.currentTarget.value), setPage(1))}
          w={220}
        />
      </Group>

      <DataCard>
        <QueryState loading={loading} error={error} isEmpty={!items.length} emptyMessage="No sales in this period." onRetry={reload}>
          <Table verticalSpacing="sm" highlightOnHover>
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Receipt</Table.Th>
                <Table.Th>Till · cashier</Table.Th>
                <Table.Th>Paid by</Table.Th>
                <Table.Th ta="right">Total</Table.Th>
                {showProfit && <Table.Th ta="right">Gross profit</Table.Th>}
                <Table.Th>Status</Table.Th>
                <Table.Th>eTIMS</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {items.map((sale) => (
                <Table.Tr key={sale.id} style={{ cursor: "pointer" }} onClick={() => setOpen(sale)}>
                  <Table.Td>
                    <Text size="sm" ff="monospace" fw={600}>
                      {sale.number}
                    </Text>
                    <Text size="xs" c="dimmed">
                      {dayjs(sale.completedAt).format("DD MMM, h:mm a")}
                    </Text>
                  </Table.Td>
                  <Table.Td>
                    <Text size="sm">{sale.till.name}</Text>
                    <Text size="xs" c="dimmed">
                      {sale.cashier.name}
                    </Text>
                  </Table.Td>
                  <Table.Td>
                    <Text size="sm">{[...new Set(sale.tenders.filter((t) => t.amountCents > 0).map((t) => METHOD[t.method]))].join(" + ")}</Text>
                  </Table.Td>
                  <Table.Td ta="right">
                    <Text size="sm" fw={600}>
                      {formatKes(sale.totalCents)}
                    </Text>
                    {sale.returnedCents > 0 && (
                      <Text size="xs" c="red.7">
                        −{formatKes(sale.returnedCents)} returned
                      </Text>
                    )}
                  </Table.Td>
                  {showProfit && <Table.Td ta="right">{formatKes(sale.grossProfitCents)}</Table.Td>}
                  <Table.Td>
                    <DocStatusBadge label={STATUS[sale.status].label} color={STATUS[sale.status].color} />
                  </Table.Td>
                  <Table.Td>
                    <DocStatusBadge label={ETIMS[sale.etimsStatus].label} color={ETIMS[sale.etimsStatus].color} />
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </QueryState>
      </DataCard>

      <Group justify="space-between">
        <Text size="sm" c="dimmed">
          {data ? `${data.meta.total} sales · this page ${formatKes(pageTotal)} net of returns` : ""}
        </Text>
        {data && data.meta.lastPage > 1 && <Pagination value={page} onChange={setPage} total={data.meta.lastPage} size="sm" />}
      </Group>

      {open && <SaleModal sale={open} onClose={() => setOpen(null)} />}
    </WorkspacePage>
  );
}

function SaleModal({ sale, onClose }: { sale: Sale; onClose: () => void }) {
  const [printing, setPrinting] = useState(false);

  useEffect(() => {
    if (!printing) return;
    const timer = window.setTimeout(() => {
      window.print();
      setPrinting(false);
    }, 50);
    return () => window.clearTimeout(timer);
  }, [printing]);

  return (
    <Modal opened onClose={onClose} title={`Sale ${sale.number}`} size="xl">
      <SimpleGrid cols={{ base: 1, sm: 2 }} spacing="xl">
        <Stack gap="sm">
          <Detail label="Completed" value={dayjs(sale.completedAt).format("dddd D MMM YYYY, h:mm a")} />
          <Detail label="Branch · till" value={`${sale.branch.name} · ${sale.till.name}`} />
          <Detail label="Cashier" value={sale.cashier.name} />
          {sale.costCents !== null && <Detail label="Cost of goods · gross profit" value={`${formatKes(sale.costCents)} · ${formatKes(sale.grossProfitCents)}`} />}
          {sale.lines.some((l) => l.approvedBy) && (
            <Detail
              label="Manager approvals"
              value={sale.lines
                .filter((l) => l.approvedBy)
                .map((l) => `${l.variant.displayName} (${l.approvedBy?.name})`)
                .join(", ")}
            />
          )}
          {sale.tenders.some((t) => t.status === "unverified") && (
            <Detail label="Payment check" value="M-PESA / card not yet confirmed automatically. Check the statement." />
          )}
          {sale.returns.map((r) => (
            <Detail key={r.id} label={`Return ${r.number}`} value={`${formatKes(r.totalCents)} — ${r.reason}`} />
          ))}
          <Button variant="default" leftSection={<IconPrinter size={16} />} onClick={() => setPrinting(true)} w="fit-content">
            Print copy
          </Button>
        </Stack>
        <div style={{ border: "1px solid var(--mantine-color-gray-3)", borderRadius: 8 }}>
          <Receipt sale={sale} copy />
        </div>
      </SimpleGrid>
      {printing && <ReceiptPrint sale={sale} copy />}
    </Modal>
  );
}

function Detail({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <Text size="xs" c="dimmed">
        {label}
      </Text>
      <Text size="sm">{value}</Text>
    </div>
  );
}
