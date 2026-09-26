"use client";

import { Button, Group, Modal, NumberInput, SimpleGrid, Stack, Switch, Text } from "@mantine/core";
import { IconCash, IconSettings } from "@tabler/icons-react";
import { useCallback, useState } from "react";
import { customersApi, organisationApi } from "@/api";
import DataCard from "@/components/shared/DataCard";
import QueryState from "@/components/shared/QueryState";
import StatTile from "@/components/shared/StatTile";
import { useApiMutation } from "@/hooks/useApiMutation";
import { useApiQuery } from "@/hooks/useApiQuery";
import { usePermissions } from "@/hooks/usePermissions";
import { AgingTiles, PaymentsCard, RecordPaymentModal, StatementCard } from "@/modules/accounts/components/AccountParts";
import { CUSTOMER_PAYMENT_METHODS, type CustomerAccount } from "@/types/accounts";
import type { Customer } from "@/types/customers";
import { PERMISSIONS } from "@/types/permissions";
import { formatKes, optionalKesToCents } from "@/utils/money";

/** Credit account on the customer tab: limit, balance and aging, statement, payments. */
export default function CreditAccountSection({ customer }: { customer: Customer }) {
  const { can } = usePermissions();
  const fetchAccount = useCallback(() => customersApi.account(customer.id), [customer.id]);
  const account = useApiQuery(fetchAccount);
  const fetchBranches = useCallback(() => (can(PERMISSIONS.CUSTOMERS_PAYMENTS) ? organisationApi.branches() : Promise.resolve([])), [can]);
  const branches = useApiQuery(fetchBranches);
  const [dialog, setDialog] = useState<"credit" | "payment" | null>(null);
  const [version, setVersion] = useState(0);
  const loadStatement = useCallback((from: string, to: string) => customersApi.statement(customer.id, from, to), [customer.id, version]); // eslint-disable-line react-hooks/exhaustive-deps -- version reloads it
  const loadPayments = useCallback(() => customersApi.payments(customer.id), [customer.id]);

  const changed = () => {
    setDialog(null);
    setVersion((v) => v + 1);
    account.reload();
  };
  const a = account.data;

  return (
    <Stack gap="lg">
      <DataCard
        title="Credit account"
        description={
          a?.hasAccount
            ? `Pays within ${a.creditTermsDays} days. Sales "on account" at the till add to the balance; payments settle the oldest first.`
            : "No credit account: this customer pays at the till."
        }
        actions={
          <Group gap="xs">
            {can(PERMISSIONS.CUSTOMERS_CREDIT) && !customer.anonymisedAt && (
              <Button size="xs" variant="default" leftSection={<IconSettings size={14} />} onClick={() => setDialog("credit")}>
                {a?.hasAccount ? "Change limit" : "Open credit account"}
              </Button>
            )}
            {can(PERMISSIONS.CUSTOMERS_PAYMENTS) && a && (a.hasAccount || a.balanceCents > 0) && (
              <Button size="xs" leftSection={<IconCash size={14} />} onClick={() => setDialog("payment")}>
                Receive payment
              </Button>
            )}
          </Group>
        }
        padding="lg"
      >
        <QueryState loading={account.loading && !a} error={account.error} isEmpty={!a} onRetry={account.reload}>
          {a && (
            <Stack>
              <SimpleGrid cols={{ base: 2, md: 4 }}>
                <StatTile label="Credit limit" value={a.creditLimitCents === null ? "None" : formatKes(a.creditLimitCents)} hint={a.creditLimitCents === 0 ? "On hold: every sale needs a manager" : undefined} />
                <StatTile label="Owes" value={formatKes(a.balanceCents)} hint={a.balanceCents < 0 ? "Paid ahead" : undefined} />
                <StatTile label="Available" value={a.availableCents === null ? "—" : formatKes(a.availableCents)} />
                <StatTile label="Past due" value={formatKes(a.overdueCents)} highlight={a.overdueCents > 0} hint={a.oldestDays !== null ? `Oldest unpaid ${a.oldestDays} days` : undefined} />
              </SimpleGrid>
              {a.balanceCents > 0 && <AgingTiles aging={a.aging} />}
            </Stack>
          )}
        </QueryState>
      </DataCard>

      {a && (a.hasAccount || a.balanceCents !== 0) && (
        <>
          <StatementCard load={loadStatement} subject={`${customer.name} owes`} />
          <PaymentsCard
            load={loadPayments}
            refreshKey={version}
            methods={CUSTOMER_PAYMENT_METHODS}
            canReverse={can(PERMISSIONS.CUSTOMERS_PAYMENTS)}
            reverse={(id, reason) => customersApi.reversePayment(id, reason)}
            onChanged={changed}
          />
        </>
      )}

      {dialog === "credit" && a && <CreditLimitModal customer={customer} account={a} onClose={() => setDialog(null)} onSaved={changed} />}
      {dialog === "payment" && a && (
        <RecordPaymentModal
          title={`Payment from ${customer.name}`}
          balanceCents={a.balanceCents}
          methods={CUSTOMER_PAYMENT_METHODS}
          branches={branches.data ?? []}
          onSubmit={(v) => customersApi.receivePayment(customer.id, { amountCents: v.amountCents, method: v.method, reference: v.reference, branchId: v.branchId ?? 0, note: v.note })}
          onClose={() => setDialog(null)}
          onDone={changed}
        />
      )}
    </Stack>
  );
}

function CreditLimitModal({ customer, account, onClose, onSaved }: { customer: Customer; account: CustomerAccount; onClose: () => void; onSaved: () => void }) {
  // Opening an account, or changing one: on. Closing is switching it off.
  const [enabled, setEnabled] = useState(true);
  const [limit, setLimit] = useState<number | string>(account.creditLimitCents === null ? "" : account.creditLimitCents / 100);
  const [terms, setTerms] = useState<number | string>(account.creditTermsDays);
  const { mutate, pending } = useApiMutation(() => customersApi.setCredit(customer.id, enabled ? (optionalKesToCents(limit) ?? 0) : null, Number(terms) || 0), {
    successMessage: enabled ? "Credit account saved." : "Credit account closed.",
    onSuccess: onSaved,
  });

  return (
    <Modal opened onClose={onClose} title={`Credit account · ${customer.name}`} centered>
      <Stack>
        <Switch label="Can buy on account" checked={enabled} onChange={(e) => setEnabled(e.currentTarget.checked)} />
        {enabled && (
          <>
            <NumberInput
              label="Credit limit (KES)"
              description="Above this a manager approves each sale on account. 0 puts the account on hold."
              min={0}
              decimalScale={2}
              thousandSeparator=","
              value={limit}
              onChange={setLimit}
              data-autofocus
            />
            <NumberInput label="Payment terms (days)" min={0} max={365} allowDecimal={false} value={terms} onChange={setTerms} />
          </>
        )}
        {!enabled && account.balanceCents > 0 && (
          <Text size="sm" c="red">
            {customer.name} still owes {formatKes(account.balanceCents)}. Set the limit to 0 instead, and close the account once it is paid.
          </Text>
        )}
        <Text size="xs" c="dimmed">
          Customer credit must also be switched on under Settings → Payments. Every change is logged.
        </Text>
        <Group justify="flex-end">
          <Button variant="default" onClick={onClose} disabled={pending}>
            Cancel
          </Button>
          <Button loading={pending} disabled={enabled && limit === ""} onClick={() => void mutate()}>
            Save
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}
