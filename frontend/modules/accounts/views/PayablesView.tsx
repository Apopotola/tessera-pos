"use client";

import { Alert, Button, Group, Modal, SimpleGrid, Stack } from "@mantine/core";
import { IconArrowUpRight, IconCash } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useState } from "react";
import { purchasingApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import QueryState from "@/components/shared/QueryState";
import StatTile from "@/components/shared/StatTile";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import { AgingTable, AgingTiles, PaymentsCard, RecordPaymentModal, StatementCard } from "@/modules/accounts/components/AccountParts";
import { reportTab } from "@/modules/reports/routes";
import { useAppDispatch } from "@/store/hooks";
import { openTab } from "@/store/slices/tabsSlice";
import { SUPPLIER_PAYMENT_METHODS } from "@/types/accounts";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes } from "@/utils/money";

/** Supplier accounts (payables): what we owe each supplier, aged; pay from the supplier's account. */
export default function PayablesView({ title, section, tabId }: WorkspaceViewProps) {
  const dispatch = useAppDispatch();
  const fetchPayables = useCallback(() => purchasingApi.payables(), []);
  const { data, loading, error, reload } = useApiQuery(fetchPayables);
  const [open, setOpen] = useState<{ id: number; name: string } | null>(null);

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Supplier invoices less payments and credit notes. Invoices on query do not match the goods received — check with the supplier before paying them."
      actions={
        <Button variant="default" rightSection={<IconArrowUpRight size={16} />} onClick={() => dispatch(openTab(reportTab({ key: "payables-aging", title: "Payables aging" }, tabId)))}>
          Aging report (CSV / print)
        </Button>
      }
    >
      <DataCard title={data ? `You owe: ${formatKes(data.totals.balanceCents)}` : "Supplier accounts"} description={data ? `As at ${dayjs(data.asAt).format("D MMM YYYY")}` : undefined}>
        <QueryState loading={loading && !data} error={error} isEmpty={!data?.items.length} emptyMessage="Nothing owed to suppliers." onRetry={reload}>
          {data && (
            <AgingTable
              rows={data.items}
              totals={data.totals}
              pastDue={(row) => row.dueCents}
              pastDueLabel="Past due"
              extra={{ label: "On query", render: (row) => (row.onQueryCents ? formatKes(row.onQueryCents) : "—") }}
              onOpen={(row) => setOpen({ id: row.id, name: row.name })}
            />
          )}
        </QueryState>
      </DataCard>

      {open && (
        <SupplierAccountModal
          supplier={open}
          onClose={() => {
            setOpen(null);
            reload();
          }}
        />
      )}
    </WorkspacePage>
  );
}

function SupplierAccountModal({ supplier, onClose }: { supplier: { id: number; name: string }; onClose: () => void }) {
  const { can } = usePermissions();
  const fetchAccount = useCallback(() => purchasingApi.supplierAccount(supplier.id), [supplier.id]);
  const account = useApiQuery(fetchAccount);
  const [paying, setPaying] = useState(false);
  const [version, setVersion] = useState(0);
  const loadStatement = useCallback((from: string, to: string) => purchasingApi.supplierStatement(supplier.id, from, to), [supplier.id, version]); // eslint-disable-line react-hooks/exhaustive-deps -- version reloads it
  const loadPayments = useCallback(() => purchasingApi.supplierPayments(supplier.id), [supplier.id]);
  const changed = () => {
    setPaying(false);
    setVersion((v) => v + 1);
    account.reload();
  };
  const a = account.data;

  return (
    <Modal opened onClose={onClose} title={supplier.name} size="xl">
      <QueryState loading={account.loading && !a} error={account.error} isEmpty={!a} onRetry={account.reload}>
        {a && (
          <Stack>
            <SimpleGrid cols={{ base: 2, md: 3 }}>
              <StatTile label="You owe" value={formatKes(a.balanceCents)} hint={`Terms ${a.paymentTermsDays} days`} />
              <StatTile label="Past due" value={formatKes(a.dueCents)} highlight={a.dueCents > 0} />
              <StatTile label="On query" value={formatKes(a.onQueryCents)} hint="Invoices not matching goods received" />
            </SimpleGrid>
            {a.balanceCents > 0 && <AgingTiles aging={a.aging} />}
            {a.onQueryCents > 0 && (
              <Alert color="yellow" variant="light">
                {formatKes(a.onQueryCents)} of invoices do not match the goods received. Settle them with the supplier (a credit note, or the missing goods) before paying.
              </Alert>
            )}
            {can(PERMISSIONS.PURCHASING_PAY) && (
              <Group justify="flex-end">
                <Button leftSection={<IconCash size={16} />} onClick={() => setPaying(true)}>
                  Record payment
                </Button>
              </Group>
            )}
            <StatementCard load={loadStatement} subject={`you owe ${supplier.name}`} />
            <PaymentsCard
              load={loadPayments}
              refreshKey={version}
              methods={SUPPLIER_PAYMENT_METHODS}
              canReverse={can(PERMISSIONS.PURCHASING_PAY)}
              reverse={(id, reason) => purchasingApi.reverseSupplierPayment(id, reason)}
              onChanged={changed}
            />
          </Stack>
        )}
      </QueryState>

      {paying && a && (
        <RecordPaymentModal
          title={`Payment to ${supplier.name}`}
          balanceCents={a.balanceCents}
          methods={SUPPLIER_PAYMENT_METHODS}
          onSubmit={(v) => purchasingApi.paySupplier(supplier.id, { amountCents: v.amountCents, method: v.method, reference: v.reference, paidOn: v.date, note: v.note })}
          onClose={() => setPaying(false)}
          onDone={changed}
        />
      )}
    </Modal>
  );
}
