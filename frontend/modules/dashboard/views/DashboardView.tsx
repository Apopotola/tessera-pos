"use client";

import { Badge, Button, Group, Paper, SimpleGrid, Stack, Text, ThemeIcon, Title } from "@mantine/core";
import { IconAlertTriangle, IconCalculator, IconCircleCheck, IconClock } from "@tabler/icons-react";
import dayjs from "dayjs";
import Link from "next/link";
import { useCallback } from "react";
import { dashboardApi } from "@/api";
import { brand } from "@/app/theme";
import DataCard from "@/components/shared/DataCard";
import QueryState from "@/components/shared/QueryState";
import StatTile from "@/components/shared/StatTile";
import WorkspacePage from "@/components/shared/WorkspacePage";
import { useApiQuery } from "@/hooks/useApiQuery";
import { BranchesCard, CashVarianceCard, ExceptionsCard, LowStockCard, MoversCard } from "@/modules/dashboard/components/KpiCards";
import { usePermissions } from "@/hooks/usePermissions";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { reportTab } from "@/modules/reports/routes";
import { openTab, type OpenTabConfig } from "@/store/slices/tabsSlice";
import type { DashboardSummary } from "@/types/dashboard";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes } from "@/utils/money";

const PRICE_CHANGES_TAB: OpenTabConfig = { title: "Price changes", path: "/catalogue/prices", view: "priceChanges" };
const USERS_TAB: OpenTabConfig = { title: "Users & roles", path: "/admin/users", view: "usersList" };
const BRANCHES_TAB: OpenTabConfig = { title: "Branches", path: "/admin/branches", view: "branchesList" };
const STOCK_TAB: OpenTabConfig = { title: "Stock on hand", path: "/inventory/stock", view: "stockOnHand" };
const SALES_TAB: OpenTabConfig = { title: "Sales", path: "/sales", view: "salesList" };
const SHIFTS_TAB: OpenTabConfig = { title: "Shifts & cash-ups", path: "/sales/shifts", view: "shiftsList" };
const ETIMS_TAB: OpenTabConfig = { title: "eTIMS monitor", path: "/compliance/etims", view: "etimsMonitor" };
const ORDERS_TAB: OpenTabConfig = { title: "Purchase orders", path: "/purchasing/orders", view: "purchaseOrders" };
const INVOICES_TAB: OpenTabConfig = { title: "Supplier invoices", path: "/purchasing/invoices", view: "supplierInvoices" };
const ADJUSTMENTS_TAB: OpenTabConfig = { title: "Breakages & adjustments", path: "/inventory/adjustments", view: "stockAdjustments" };

/** "▲ 12% vs last Saturday" — same weekday, same time of day. */
function versusLastWeek(net: number, lastWeek: number): string {
  const day = dayjs().subtract(7, "day").format("dddd");
  if (lastWeek <= 0) return net > 0 ? `Nothing sold by now last ${day}` : `Same as last ${day}`;
  const change = Math.round(((net - lastWeek) * 100) / lastWeek);
  return `${change >= 0 ? "▲" : "▼"} ${Math.abs(change)}% vs last ${day} (${formatKes(lastWeek)})`;
}

/** "12 min", "3 h 5 min", "2 days" */
function waited(minutes: number): string {
  if (minutes < 60) return `${minutes} min`;
  if (minutes < 60 * 48) return `${Math.floor(minutes / 60)} h ${minutes % 60} min`;
  return `${Math.floor(minutes / 1440)} days`;
}

function greeting(): string {
  const hour = new Date().getHours();
  return hour < 12 ? "Good morning" : hour < 17 ? "Good afternoon" : "Good evening";
}

/**
 * Operational snapshot from real data only. Sales, stock-gap and eTIMS figures
 * join these tiles when the Sales and Inventory modules record them.
 */
export default function DashboardView() {
  const dispatch = useAppDispatch();
  const { can } = usePermissions();
  const user = useAppSelector((state) => state.auth.user);
  const fetchSummary = useCallback(() => dashboardApi.summary(), []);
  const { data, loading, error, reload } = useApiQuery(fetchSummary);
  const firstName = user?.name.split(" ")[0] ?? "";
  // Settings → Notifications → dashboard tiles per role (empty = the standard tiles).
  const chosenTiles = useAppSelector((state) => state.settings.app?.dashboardTiles);
  const tile = (key: string) => !chosenTiles?.length || chosenTiles.includes(key);

  return (
    <WorkspacePage>
      <QueryState loading={loading && !data} error={error} isEmpty={false} onRetry={reload}>
        {data && (
          <>
            <Paper radius="xl" p="xl" style={{ background: brand.navyRaised }}>
              <Group justify="space-between" align="flex-start" mb="lg">
                <div>
                  <Text size="sm" c="gray.5">
                    {data.branches.length === 1 ? data.branches[0].name : `${data.branches.length} branches`} · {dayjs().format("dddd D MMMM")}
                  </Text>
                  <Title order={2} c="white" fz={34} className="tessera-display">
                    {greeting()}, {firstName}
                  </Title>
                </div>
                {can(PERMISSIONS.SALES_SELL) && (
                  <Button component={Link} href="/till" color="amber.5" c={brand.navy} leftSection={<IconCalculator size={18} />}>
                    Open till
                  </Button>
                )}
              </Group>
              {data.salesToday && (
                <SimpleGrid cols={{ base: 2, md: 4 }} mb="md">
                  {tile("sales_today") && (
                    <StatTile
                      tone="dark"
                      label="Sales today"
                      value={formatKes(data.salesToday.netCents)}
                      hint={`${data.salesToday.transactions} ${data.salesToday.transactions === 1 ? "sale" : "sales"} · ${versusLastWeek(data.salesToday.netCents, data.salesToday.lastWeekNetCents)}`}
                      onClick={() => dispatch(openTab(SALES_TAB))}
                    />
                  )}
                  {tile("takings") && <StatTile tone="dark" label="Cash" value={formatKes(data.salesToday.cashCents)} />}
                  {tile("takings") && (
                    <StatTile tone="dark" label="M-PESA · card" value={formatKes(data.salesToday.mpesaCents + data.salesToday.cardCents)} hint={`Card ${formatKes(data.salesToday.cardCents)}`} />
                  )}
                  {tile("gross_profit") && data.salesToday.grossProfitCents !== null && (
                    <StatTile
                      tone="dark"
                      label="Gross profit today"
                      value={formatKes(data.salesToday.grossProfitCents)}
                      hint={data.salesToday.marginPercent !== null ? `${data.salesToday.marginPercent}% margin, after VAT and cost of goods` : "After VAT and cost of goods"}
                    />
                  )}
                </SimpleGrid>
              )}
              <SimpleGrid cols={{ base: 2, md: 4 }}>
                {tile("shifts") && data.openShifts && <StatTile tone="dark" label="Shifts open now" value={data.openShifts.length} />}
                {tile("tills") && data.tills && <StatTile tone="dark" label="Tills connected" value={`${data.tills.connected} / ${data.tills.total}`} />}
                {tile("catalogue") && data.catalogue && (
                  <StatTile tone="dark" label="Items on sale" value={data.catalogue.activeVariants} hint={`${data.catalogue.activeProducts} products`} />
                )}
                {tile("low_stock") && data.inventory && (
                  <StatTile
                    tone="dark"
                    highlight={data.inventory.lowStock > 0}
                    label="Items low on stock"
                    value={data.inventory.lowStock}
                    onClick={() => dispatch(openTab(STOCK_TAB))}
                  />
                )}
                {tile("stock_value") && data.inventory?.stockValueCents != null && <StatTile tone="dark" label="Stock value (at cost)" value={formatKes(data.inventory.stockValueCents)} />}
                {tile("losses") && data.inventory?.lossesThisMonthCents != null && (
                  <StatTile
                    tone="dark"
                    label="Shrinkage this month"
                    value={formatKes(data.inventory.lossesThisMonthCents)}
                    hint={data.shrinkage?.percentOfCogs != null ? `${data.shrinkage.percentOfCogs}% of cost of goods sold · breakage and missing` : "Breakage and missing stock at cost"}
                    onClick={() => dispatch(openTab(reportTab({ key: "losses-by-reason", title: "Losses by reason" })))}
                  />
                )}
                {tile("etims") && data.compliance && (
                  <StatTile
                    tone="dark"
                    highlight={data.compliance.waitingOverThreshold > 0 || data.compliance.rejected > 0}
                    label="eTIMS invoices not yet signed"
                    value={data.compliance.pending + data.compliance.failed + data.compliance.rejected}
                    hint={
                      data.compliance.rejected > 0
                        ? `${data.compliance.rejected} refused by KRA — fix and retry`
                        : data.compliance.oldestPendingMinutes !== null
                          ? `Oldest waiting ${waited(data.compliance.oldestPendingMinutes)}${data.compliance.failed ? ` · ${data.compliance.failed} retrying` : ""}`
                          : "All signed by KRA"
                    }
                    onClick={() => dispatch(openTab(ETIMS_TAB))}
                  />
                )}
                {tile("price_changes") && data.pendingPriceChanges !== null && (
                  <StatTile
                    tone="dark"
                    highlight={data.pendingPriceChanges > 0}
                    label="Price changes waiting"
                    value={data.pendingPriceChanges}
                    onClick={() => dispatch(openTab(PRICE_CHANGES_TAB))}
                  />
                )}
              </SimpleGrid>
            </Paper>

            <SimpleGrid cols={{ base: 1, md: 2 }} spacing="lg">
              {data.openShifts && <OpenShiftsCard shifts={data.openShifts} />}
              <AttentionCard data={data} onOpen={(tab) => dispatch(openTab(tab))} />
            </SimpleGrid>

            {/* The owner's KPIs (requirements → Dashboard); each card opens the report that acts on it. */}
            <SimpleGrid cols={{ base: 1, lg: 2 }} spacing="lg">
              {tile("exceptions") && data.exceptions && <ExceptionsCard data={data.exceptions} onOpen={(tab) => dispatch(openTab(tab))} />}
              {tile("cash_variance") && data.cashVariance && <CashVarianceCard data={data.cashVariance} onOpen={(tab) => dispatch(openTab(tab))} />}
              {tile("low_stock") && data.inventory && (
                <LowStockCard items={data.inventory.lowStockTop} total={data.inventory.lowStock} onOpen={(tab) => dispatch(openTab(tab))} />
              )}
              {tile("branches") && data.branchComparison && <BranchesCard rows={data.branchComparison} onOpen={(tab) => dispatch(openTab(tab))} />}
            </SimpleGrid>
            {tile("movers") && data.movers && <MoversCard data={data.movers} onOpen={(tab) => dispatch(openTab(tab))} />}
          </>
        )}
      </QueryState>
    </WorkspacePage>
  );
}

function OpenShiftsCard({ shifts }: { shifts: NonNullable<DashboardSummary["openShifts"]> }) {
  return (
    <DataCard padding="lg" title="Shifts open now">
      {shifts.length === 0 ? (
        <Text size="sm" c="dimmed">
          No till is open.
        </Text>
      ) : (
        <Stack gap="sm">
          {shifts.map((shift) => (
            <Group key={shift.id} justify="space-between" wrap="nowrap">
              <Group gap="sm" wrap="nowrap">
                <ThemeIcon variant="light" radius="xl">
                  <IconClock size={16} />
                </ThemeIcon>
                <div>
                  <Text size="sm" fw={600}>
                    {shift.cashier}
                  </Text>
                  <Text size="xs" c="dimmed">
                    {shift.branchCode} · {shift.till} · since {dayjs(shift.openedAt).format("h:mm a")}
                  </Text>
                </div>
              </Group>
              <Badge variant="light">Float {formatKes(shift.openingFloatCents)}</Badge>
            </Group>
          ))}
        </Stack>
      )}
    </DataCard>
  );
}

function AttentionCard({ data, onOpen }: { data: DashboardSummary; onOpen: (tab: OpenTabConfig) => void }) {
  const items: { text: string; tab: OpenTabConfig }[] = [];

  if (data.pendingPriceChanges) {
    items.push({ text: `${data.pendingPriceChanges} price change${data.pendingPriceChanges === 1 ? "" : "s"} waiting for approval`, tab: PRICE_CHANGES_TAB });
  }
  if (data.inventory?.pendingApprovals) {
    items.push({ text: `${data.inventory.pendingApprovals} stock document(s) waiting for approval`, tab: ADJUSTMENTS_TAB });
  }
  if (data.inventory?.lowStock) {
    items.push({ text: `${data.inventory.lowStock} item(s) at or below their reorder level`, tab: STOCK_TAB });
  }
  if (data.purchasing?.ordersAwaitingApproval) {
    items.push({ text: `${data.purchasing.ordersAwaitingApproval} purchase order(s) waiting for approval`, tab: ORDERS_TAB });
  }
  if (data.purchasing?.invoicesWithVariance) {
    items.push({ text: `${data.purchasing.invoicesWithVariance} supplier invoice(s) don't match the goods received`, tab: INVOICES_TAB });
  }
  if (data.cashUps?.toReview) {
    const diff = data.cashUps.withDifference ? `, ${data.cashUps.withDifference} with a difference` : "";
    items.push({ text: `${data.cashUps.toReview} cash-up(s) waiting for your sign-off${diff}`, tab: SHIFTS_TAB });
  }
  if (data.compliance?.rejected) {
    items.push({ text: `${data.compliance.rejected} eTIMS invoice(s) refused by KRA — fix the item data and retry`, tab: ETIMS_TAB });
  }
  if (data.compliance?.waitingOverThreshold) {
    items.push({ text: `${data.compliance.waitingOverThreshold} eTIMS invoice(s) waiting over an hour to be signed`, tab: ETIMS_TAB });
  }
  if (data.staff?.cashiersWithoutPin) {
    items.push({ text: `${data.staff.cashiersWithoutPin} staff who can sell have no till PIN`, tab: USERS_TAB });
  }
  if (data.tills && data.tills.total === 0) {
    items.push({ text: "No till is set up yet — open /till on the till device", tab: BRANCHES_TAB });
  } else if (data.tills && data.tills.connected < data.tills.total) {
    items.push({ text: `${data.tills.total - data.tills.connected} till(s) not connected`, tab: BRANCHES_TAB });
  }

  return (
    <DataCard padding="lg" title="Needs attention">
      {items.length === 0 ? (
        <Group gap="xs">
          <IconCircleCheck size={18} color="var(--mantine-color-green-6)" />
          <Text size="sm" c="dimmed">
            Nothing waiting on you.
          </Text>
        </Group>
      ) : (
        <Stack gap="xs">
          {items.map((item) => (
            <Button
              key={item.text}
              variant="subtle"
              color="dark"
              justify="flex-start"
              leftSection={<IconAlertTriangle size={16} color={brand.amber} />}
              onClick={() => onOpen(item.tab)}
              styles={{ label: { whiteSpace: "normal", textAlign: "left" } }}
            >
              {item.text}
            </Button>
          ))}
        </Stack>
      )}
    </DataCard>
  );
}
