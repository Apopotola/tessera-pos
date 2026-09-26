"use client";

import { Badge, Button, Group, Paper, SimpleGrid, Stack, Text, ThemeIcon, Title } from "@mantine/core";
import { IconAlertTriangle, IconCalculator, IconChartBar, IconCircleCheck, IconClock } from "@tabler/icons-react";
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
import { usePermissions } from "@/hooks/usePermissions";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
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
                  <StatTile
                    tone="dark"
                    label="Sales today"
                    value={formatKes(data.salesToday.netCents)}
                    hint={`${data.salesToday.transactions} ${data.salesToday.transactions === 1 ? "sale" : "sales"}${data.salesToday.refundsCents ? ` · ${formatKes(data.salesToday.refundsCents)} refunded` : ""}`}
                    onClick={() => dispatch(openTab(SALES_TAB))}
                  />
                  <StatTile tone="dark" label="Cash" value={formatKes(data.salesToday.cashCents)} />
                  <StatTile tone="dark" label="M-PESA · card" value={formatKes(data.salesToday.mpesaCents + data.salesToday.cardCents)} hint={`Card ${formatKes(data.salesToday.cardCents)}`} />
                  {data.salesToday.grossProfitCents !== null && (
                    <StatTile tone="dark" label="Gross profit today" value={formatKes(data.salesToday.grossProfitCents)} hint="After VAT and cost of goods" />
                  )}
                </SimpleGrid>
              )}
              <SimpleGrid cols={{ base: 2, md: 4 }}>
                {data.openShifts && <StatTile tone="dark" label="Shifts open now" value={data.openShifts.length} />}
                {data.tills && <StatTile tone="dark" label="Tills connected" value={`${data.tills.connected} / ${data.tills.total}`} />}
                {data.catalogue && (
                  <StatTile tone="dark" label="Items on sale" value={data.catalogue.activeVariants} hint={`${data.catalogue.activeProducts} products`} />
                )}
                {data.inventory && (
                  <StatTile
                    tone="dark"
                    highlight={data.inventory.lowStock > 0}
                    label="Items low on stock"
                    value={data.inventory.lowStock}
                    onClick={() => dispatch(openTab(STOCK_TAB))}
                  />
                )}
                {data.inventory?.stockValueCents != null && <StatTile tone="dark" label="Stock value (at cost)" value={formatKes(data.inventory.stockValueCents)} />}
                {data.inventory?.lossesThisMonthCents != null && (
                  <StatTile tone="dark" label="Breakage & losses this month" value={formatKes(data.inventory.lossesThisMonthCents)} />
                )}
                {data.pendingPriceChanges !== null && (
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

            <DataCard padding="lg">
              <Group gap="md" wrap="nowrap">
                <ThemeIcon size={44} radius="md" variant="light">
                  <IconChartBar size={22} />
                </ThemeIcon>
                <div>
                  <Text fw={700}>Sales and bottles sold</Text>
                  <Text size="sm" c="dimmed">
                    These figures appear here as soon as the till starts recording sales.
                  </Text>
                </div>
              </Group>
            </DataCard>
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
