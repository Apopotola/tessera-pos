"use client";

import { Badge, Button, SimpleGrid, Stack, Table, Text } from "@mantine/core";
import { IconArrowUpRight } from "@tabler/icons-react";
import DataCard from "@/components/shared/DataCard";
import { reportTab } from "@/modules/reports/routes";
import type { OpenTabConfig } from "@/store/slices/tabsSlice";
import type { DashboardSummary, ExceptionKind, Tally } from "@/types/dashboard";
import { formatKes } from "@/utils/money";

type Open = (tab: OpenTabConfig) => void;

/** "Open report" link in a card header: every KPI leads to the screen that acts on it. */
function OpenLink({ label = "Open report", tab, onOpen }: { label?: string; tab: OpenTabConfig; onOpen: Open }) {
  return (
    <Button size="xs" variant="subtle" rightSection={<IconArrowUpRight size={14} />} onClick={() => onOpen(tab)}>
      {label}
    </Button>
  );
}

function Empty({ children }: { children: string }) {
  return (
    <Text size="sm" c="dimmed" p="lg">
      {children}
    </Text>
  );
}

const tally = (t: Tally) => (t.count === 0 ? "—" : `${t.count} · ${formatKes(t.cents)}`);
const KINDS: { key: ExceptionKind; label: string }[] = [
  { key: "discounts", label: "Discounts" },
  { key: "voids", label: "Removed items" },
  { key: "refunds", label: "Refunds" },
];

/** Discounts, removed lines and refunds today per cashier: the main cashier-fraud signals. */
export function ExceptionsCard({ data, onOpen }: { data: NonNullable<DashboardSummary["exceptions"]>; onOpen: Open }) {
  return (
    <DataCard
      title="Discounts, voids and refunds today"
      description={KINDS.map((k) => `${k.label}: ${tally(data.totals[k.key])}`).join(" · ")}
      actions={<OpenLink tab={reportTab({ key: "voids-discounts-overrides", title: "Voids, discounts & overrides" })} onOpen={onOpen} />}
    >
      {data.byCashier.length === 0 ? (
        <Empty>None today.</Empty>
      ) : (
        <Table verticalSpacing="xs" fz="sm">
          <Table.Thead>
            <Table.Tr>
              <Table.Th>Cashier</Table.Th>
              {KINDS.map((k) => (
                <Table.Th key={k.key} ta="right">
                  {k.label}
                </Table.Th>
              ))}
            </Table.Tr>
          </Table.Thead>
          <Table.Tbody>
            {data.byCashier.map((row) => (
              <Table.Tr key={row.cashier}>
                <Table.Td fw={600}>{row.cashier}</Table.Td>
                {KINDS.map((k) => (
                  <Table.Td key={k.key} ta="right">
                    {tally(row[k.key])}
                  </Table.Td>
                ))}
              </Table.Tr>
            ))}
          </Table.Tbody>
        </Table>
      )}
    </DataCard>
  );
}

/** Cash-up differences over 30 days per cashier: a pattern of shortages is an early warning. */
export function CashVarianceCard({ data, onOpen }: { data: NonNullable<DashboardSummary["cashVariance"]>; onOpen: Open }) {
  return (
    <DataCard
      title="Cash variance by cashier"
      description={`Closed cash-ups, last 30 days. Flagged when out by more than ${formatKes(data.allowedCents)}.`}
      actions={<OpenLink tab={reportTab({ key: "cash-ups", title: "Cash-ups" })} onOpen={onOpen} />}
    >
      {data.byCashier.length === 0 ? (
        <Empty>No cash-ups in the last 30 days.</Empty>
      ) : (
        <Table verticalSpacing="xs" fz="sm">
          <Table.Thead>
            <Table.Tr>
              <Table.Th>Cashier</Table.Th>
              <Table.Th ta="right">Shifts</Table.Th>
              <Table.Th ta="right">Total short</Table.Th>
              <Table.Th ta="right">Net</Table.Th>
              <Table.Th ta="right">Flagged</Table.Th>
            </Table.Tr>
          </Table.Thead>
          <Table.Tbody>
            {data.byCashier.map((row) => (
              <Table.Tr key={row.cashier}>
                <Table.Td fw={600}>{row.cashier}</Table.Td>
                <Table.Td ta="right">{row.shifts}</Table.Td>
                <Table.Td ta="right" c={row.shortCents < 0 ? "red.7" : undefined}>
                  {formatKes(row.shortCents)}
                </Table.Td>
                <Table.Td ta="right">{formatKes(row.netCents)}</Table.Td>
                <Table.Td ta="right">
                  {row.overAllowed > 0 ? (
                    <Badge color="red" variant="light">
                      {row.overAllowed}
                    </Badge>
                  ) : (
                    "—"
                  )}
                </Table.Td>
              </Table.Tr>
            ))}
          </Table.Tbody>
        </Table>
      )}
    </DataCard>
  );
}

/** Items at or below their level, fast movers first: the stock-outs that lose sales. */
export function LowStockCard({ items, total, onOpen }: { items: NonNullable<DashboardSummary["inventory"]>["lowStockTop"]; total: number; onOpen: Open }) {
  return (
    <DataCard
      title="Low stock — reorder first"
      description={total > items.length ? `Top ${items.length} of ${total}, by units sold in the last 30 days.` : "By units sold in the last 30 days."}
      actions={<OpenLink tab={reportTab({ key: "low-stock", title: "Low stock" })} onOpen={onOpen} />}
    >
      {items.length === 0 ? (
        <Empty>Nothing is below its reorder level.</Empty>
      ) : (
        <Table verticalSpacing="xs" fz="sm">
          <Table.Thead>
            <Table.Tr>
              <Table.Th>Item</Table.Th>
              <Table.Th>Branch</Table.Th>
              <Table.Th ta="right">In stock</Table.Th>
              <Table.Th ta="right">Level</Table.Th>
              <Table.Th ta="right">Sold (30 d)</Table.Th>
            </Table.Tr>
          </Table.Thead>
          <Table.Tbody>
            {items.map((row) => (
              <Table.Tr key={`${row.variantId}-${row.branchCode}`}>
                <Table.Td fw={600}>{row.displayName}</Table.Td>
                <Table.Td>{row.branchCode}</Table.Td>
                <Table.Td ta="right" c={row.available <= 0 ? "red.7" : undefined} fw={row.available <= 0 ? 700 : undefined}>
                  {row.available}
                </Table.Td>
                <Table.Td ta="right">{row.level}</Table.Td>
                <Table.Td ta="right">{row.soldLast30Days}</Table.Td>
              </Table.Tr>
            ))}
          </Table.Tbody>
        </Table>
      )}
    </DataCard>
  );
}

/** Best sellers (30 days) for buying decisions; slow movers (no sale in 60 days) tie up cash. */
export function MoversCard({ data, onOpen }: { data: NonNullable<DashboardSummary["movers"]>; onOpen: Open }) {
  return (
    <DataCard title="Best sellers and slow movers" actions={<OpenLink tab={reportTab({ key: "sales-by-item", title: "Sales by item" })} onOpen={onOpen} />}>
      <SimpleGrid cols={{ base: 1, sm: 2 }} spacing={0}>
        <Stack gap={4} p="md">
          <Text size="xs" fw={700} tt="uppercase" c="dimmed" style={{ letterSpacing: "0.08em" }}>
            Top 10 · last 30 days
          </Text>
          {data.top.length === 0 ? (
            <Text size="sm" c="dimmed">
              No sales yet.
            </Text>
          ) : (
            data.top.map((row, i) => (
              <SimpleGrid key={row.variantId} cols={2} spacing="xs">
                <Text size="sm" lineClamp={1}>
                  {i + 1}. {row.displayName}
                </Text>
                <Text size="sm" ta="right" c="dimmed">
                  {row.bottles} btl{row.tots ? ` · ${row.tots} tots` : ""} · <b style={{ color: "var(--mantine-color-text)" }}>{formatKes(row.netCents)}</b>
                </Text>
              </SimpleGrid>
            ))
          )}
        </Stack>
        <Stack gap={4} p="md" style={{ borderLeft: "1px solid var(--mantine-color-gray-2)" }}>
          <Text size="xs" fw={700} tt="uppercase" c="dimmed" style={{ letterSpacing: "0.08em" }}>
            No sale in 60 days · {data.slowCount} item{data.slowCount === 1 ? "" : "s"}
            {data.slowValueCents !== null && data.slowCount > 0 ? ` · ${formatKes(data.slowValueCents)} at cost` : ""}
          </Text>
          {data.slow.length === 0 ? (
            <Text size="sm" c="dimmed">
              Everything in stock has sold recently.
            </Text>
          ) : (
            data.slow.map((row) => (
              <SimpleGrid key={row.variantId} cols={2} spacing="xs">
                <Text size="sm" lineClamp={1}>
                  {row.displayName}
                </Text>
                <Text size="sm" ta="right" c="dimmed">
                  {row.quantity} in stock{row.valueCents !== null ? ` · ${formatKes(row.valueCents)}` : ""}
                </Text>
              </SimpleGrid>
            ))
          )}
        </Stack>
      </SimpleGrid>
    </DataCard>
  );
}

/** Month to date per outlet: sales, margin and losses side by side. */
export function BranchesCard({ rows, onOpen }: { rows: NonNullable<DashboardSummary["branchComparison"]>; onOpen: Open }) {
  const showCost = rows.some((r) => r.marginPercent !== null || r.lossesCents !== null);

  return (
    <DataCard
      title="Branches this month"
      actions={<OpenLink tab={reportTab({ key: "sales-by-cashier-branch-tender", title: "Sales by cashier, branch & tender" })} onOpen={onOpen} />}
    >
      <Table verticalSpacing="xs" fz="sm">
        <Table.Thead>
          <Table.Tr>
            <Table.Th>Branch</Table.Th>
            <Table.Th ta="right">Sales</Table.Th>
            <Table.Th ta="right">Net sales</Table.Th>
            {showCost && <Table.Th ta="right">Margin</Table.Th>}
            {showCost && <Table.Th ta="right">Losses</Table.Th>}
          </Table.Tr>
        </Table.Thead>
        <Table.Tbody>
          {rows.map((row) => (
            <Table.Tr key={row.branchId}>
              <Table.Td fw={600}>{row.name}</Table.Td>
              <Table.Td ta="right">{row.transactions}</Table.Td>
              <Table.Td ta="right">{formatKes(row.netCents)}</Table.Td>
              {showCost && <Table.Td ta="right">{row.marginPercent === null ? "—" : `${row.marginPercent}%`}</Table.Td>}
              {showCost && <Table.Td ta="right">{formatKes(row.lossesCents)}</Table.Td>}
            </Table.Tr>
          ))}
        </Table.Tbody>
      </Table>
    </DataCard>
  );
}
