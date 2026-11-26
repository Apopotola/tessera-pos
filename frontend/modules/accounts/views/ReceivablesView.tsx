"use client";

import { Button } from "@mantine/core";
import { IconArrowUpRight } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback } from "react";
import { customersApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import QueryState from "@/components/shared/QueryState";
import WorkspacePage from "@/components/shared/WorkspacePage";
import type { WorkspaceViewProps } from "@/components/workspace/types";
import { useApiQuery } from "@/hooks/useApiQuery";
import { AgingTable } from "@/modules/accounts/components/AccountParts";
import { customerTab } from "@/modules/customers/routes";
import { reportTab } from "@/modules/reports/routes";
import { useAppDispatch } from "@/store/hooks";
import { openTab } from "@/store/slices/tabsSlice";
import { formatKes } from "@/utils/money";

/** Customer accounts (receivables): who owes what, aged. A row opens the customer's account. */
export default function ReceivablesView({ title, section, tabId }: WorkspaceViewProps) {
  const dispatch = useAppDispatch();
  const fetchAccounts = useCallback(() => customersApi.accounts(), []);
  const { data, loading, error, reload } = useApiQuery(fetchAccounts);

  return (
    <WorkspacePage
      section={section}
      title={title}
      description="Customers who buy on account. Payments settle the oldest sales first; past due means older than the customer's payment terms."
      actions={
        <Button variant="default" rightSection={<IconArrowUpRight size={16} />} onClick={() => dispatch(openTab(reportTab({ key: "receivables-aging", title: "Receivables aging" }, tabId)))}>
          Aging report (CSV / print)
        </Button>
      }
    >
      <DataCard title={data ? `Owed to you: ${formatKes(data.totals.balanceCents)}` : "Customer accounts"} description={data ? `As at ${dayjs(data.asAt).format("D MMM YYYY")}` : undefined}>
        <QueryState
          loading={loading && !data}
          error={error}
          isEmpty={!data?.items.length}
          emptyMessage="No customer has a credit account yet. Open one from the customer's page (Customers → customer → Credit account)."
          onRetry={reload}
        >
          {data && (
            <AgingTable
              rows={data.items}
              totals={data.totals}
              pastDue={(row) => row.overdueCents}
              pastDueLabel="Past due"
              extra={{ label: "Limit", render: (row) => (row.creditLimitCents === null ? "—" : formatKes(row.creditLimitCents)) }}
              onOpen={(row) => dispatch(openTab(customerTab({ id: row.id, name: row.name }, tabId)))}
            />
          )}
        </QueryState>
      </DataCard>
    </WorkspacePage>
  );
}
