"use client";

import { Badge, Button, Group, SimpleGrid, Table, Text } from "@mantine/core";
import { IconPackageImport, IconPrinter, IconTable } from "@tabler/icons-react";
import { useCallback } from "react";
import { dashboardApi, inventoryApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import QueryState from "@/components/shared/QueryState";
import StatTile from "@/components/shared/StatTile";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import StockStatusBadge from "@/modules/inventory/components/StockStatusBadge";
import { useAppDispatch } from "@/store/hooks";
import { openTab, type OpenTabConfig } from "@/store/slices/tabsSlice";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes } from "@/utils/money";

const STOCK_LEVELS_TAB: OpenTabConfig = { title: "Stock levels", path: "/inventory/stock", view: "stockOnHand" };
const RECEIVE_TAB: OpenTabConfig = { title: "Receive stock", path: "/inventory/receive", view: "receiveStock" };
const ORDERS_TAB: OpenTabConfig = { title: "Purchase orders", path: "/purchasing/orders", view: "purchaseOrders" };

const quantity = (bottles: number, tots: number) => [bottles ? `${bottles} btl` : null, tots ? `${tots} tots` : null].filter(Boolean).join(" · ") || "0";

/** Stock at a glance: value, profit in stock, fast and slow movers and what to reorder. */
export default function StockDashboardView({ title, section }: WorkspaceViewProps) {
  const dispatch = useAppDispatch();
  const { can } = usePermissions();
  const fetchOverview = useCallback(() => inventoryApi.overview(), []);
  const fetchSummary = useCallback(() => dashboardApi.summary(), []);
  const overview = useApiQuery(fetchOverview);
  const summary = useApiQuery(fetchSummary);
  const o = overview.data;
  const movers = summary.data?.movers ?? null;
  const lowStock = summary.data?.inventory?.lowStockTop ?? [];
  const topTotal = movers?.top.reduce((sum, m) => sum + m.netCents, 0) ?? 0;

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Stock value, the profit sitting on the shelves, what sells, what doesn't, and what to reorder."
      actions={
        <>
          <Button variant="default" leftSection={<IconPrinter size={16} />} onClick={() => window.print()}>
            Print
          </Button>
          <Button variant="default" leftSection={<IconTable size={16} />} onClick={() => dispatch(openTab(STOCK_LEVELS_TAB))}>
            Stock levels
          </Button>
          {can(PERMISSIONS.INVENTORY_RECEIVE) && (
            <Button leftSection={<IconPackageImport size={16} />} onClick={() => dispatch(openTab(RECEIVE_TAB))}>
              Receive stock
            </Button>
          )}
        </>
      }
    >
      <QueryState loading={overview.loading && !o} error={overview.error} isEmpty={false} onRetry={overview.reload}>
        {o && (
          <SimpleGrid cols={{ base: 1, xs: 2, lg: 4 }}>
            {o.costValueCents !== null && <StatTile label="Stock value (at cost)" value={formatKes(o.costValueCents)} hint={`${o.items} items · ${o.units} units`} />}
            <StatTile label="Stock value (at selling price)" value={formatKes(o.sellingValueCents)} hint="Current retail prices, incl. VAT" />
            {o.potentialProfitCents !== null && (
              <StatTile
                label="Potential profit"
                value={formatKes(o.potentialProfitCents)}
                hint={o.marginPercent !== null ? `${o.marginPercent}% margin, after VAT` : "After VAT"}
              />
            )}
            <StatTile
              label="Low / out of stock"
              value={`${o.lowCount} / ${o.outCount}`}
              hint="At or below the reorder level · none left"
              highlight={o.lowCount + o.outCount > 0}
              onClick={() => dispatch(openTab(STOCK_LEVELS_TAB))}
            />
          </SimpleGrid>
        )}
      </QueryState>

      <SimpleGrid cols={{ base: 1, lg: 2 }}>
        <DataCard title="Fast-moving items" description="Top 10 by net sales, last 30 days" actions={<Badge color="green" variant="light">Reorder often</Badge>}>
          <QueryState loading={summary.loading && !summary.data} error={summary.error} isEmpty={!movers?.top.length} emptyMessage="No sales in the last 30 days." onRetry={summary.reload}>
            <Table verticalSpacing="sm" highlightOnHover>
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Product</Table.Th>
                  <Table.Th ta="right">Qty sold</Table.Th>
                  <Table.Th ta="right">Net sales</Table.Th>
                  <Table.Th ta="right">% of top 10</Table.Th>
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {movers?.top.map((m) => (
                  <Table.Tr key={m.variantId}>
                    <Table.Td fw={600}>{m.displayName}</Table.Td>
                    <Table.Td ta="right">{quantity(m.bottles, m.tots)}</Table.Td>
                    <Table.Td ta="right">{formatKes(m.netCents)}</Table.Td>
                    <Table.Td ta="right">{topTotal ? `${((m.netCents * 100) / topTotal).toFixed(1)}%` : "—"}</Table.Td>
                  </Table.Tr>
                ))}
              </Table.Tbody>
            </Table>
          </QueryState>
        </DataCard>

        <DataCard
          title="Slow-moving & dead stock"
          description={`In stock, no sale in 60 days${movers ? ` · ${movers.slowCount} items` : ""}`}
          actions={movers?.slowValueCents != null ? <Badge color="red" variant="light">{formatKes(movers.slowValueCents)} tied up</Badge> : undefined}
        >
          <QueryState loading={summary.loading && !summary.data} error={summary.error} isEmpty={!movers?.slow.length} emptyMessage="Everything in stock has sold in the last 60 days." onRetry={summary.reload}>
            <Table verticalSpacing="sm" highlightOnHover>
              <Table.Thead>
                <Table.Tr>
                  <Table.Th>Product</Table.Th>
                  <Table.Th ta="right">In stock</Table.Th>
                  {movers?.slowValueCents != null && <Table.Th ta="right">Value (cost)</Table.Th>}
                  <Table.Th>Status</Table.Th>
                </Table.Tr>
              </Table.Thead>
              <Table.Tbody>
                {movers?.slow.map((m) => (
                  <Table.Tr key={m.variantId}>
                    <Table.Td fw={600}>{m.displayName}</Table.Td>
                    <Table.Td ta="right">{m.quantity}</Table.Td>
                    {movers.slowValueCents != null && <Table.Td ta="right">{formatKes(m.valueCents)}</Table.Td>}
                    <Table.Td>
                      <Badge color="amber" variant="light" radius="sm">
                        Slow
                      </Badge>
                    </Table.Td>
                  </Table.Tr>
                ))}
              </Table.Tbody>
            </Table>
          </QueryState>
        </DataCard>
      </SimpleGrid>

      <DataCard
        title="Low stock — reorder list"
        description="At or below the reorder level, best sellers first"
        actions={
          can(PERMISSIONS.PURCHASING_VIEW) ? (
            <Button size="xs" onClick={() => dispatch(openTab(ORDERS_TAB))}>
              Create purchase order
            </Button>
          ) : undefined
        }
      >
        <QueryState loading={summary.loading && !summary.data} error={summary.error} isEmpty={!lowStock.length} emptyMessage="Nothing is below its reorder level." onRetry={summary.reload}>
          <Table verticalSpacing="sm" highlightOnHover>
            <Table.Thead>
              <Table.Tr>
                <Table.Th>Product</Table.Th>
                {(summary.data?.branches.length ?? 0) > 1 && <Table.Th>Branch</Table.Th>}
                <Table.Th ta="right">In stock</Table.Th>
                <Table.Th ta="right">Reorder level</Table.Th>
                <Table.Th ta="right">Sold, 30 days</Table.Th>
                <Table.Th>Status</Table.Th>
              </Table.Tr>
            </Table.Thead>
            <Table.Tbody>
              {lowStock.map((r) => (
                <Table.Tr key={`${r.variantId}-${r.branchCode}`}>
                  <Table.Td fw={600}>{r.displayName}</Table.Td>
                  {(summary.data?.branches.length ?? 0) > 1 && <Table.Td>{r.branchCode}</Table.Td>}
                  <Table.Td ta="right">{r.available}</Table.Td>
                  <Table.Td ta="right">{r.level}</Table.Td>
                  <Table.Td ta="right">{r.soldLast30Days}</Table.Td>
                  <Table.Td>
                    <StockStatusBadge status={r.available <= 0 ? "out" : "low"} />
                  </Table.Td>
                </Table.Tr>
              ))}
            </Table.Tbody>
          </Table>
        </QueryState>
      </DataCard>

      <Group justify="flex-end">
        <Text size="xs" c="dimmed">
          Cost and profit figures show only for roles that may see cost prices.
        </Text>
      </Group>
    </WorkspacePage>
  );
}
