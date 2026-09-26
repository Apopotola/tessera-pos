"use client";

import { Alert, Button, Group, Modal, NumberInput, SegmentedControl, SimpleGrid, Stack, Text, TextInput } from "@mantine/core";
import { IconCash, IconCreditCard, IconDeviceMobile, IconArrowsSplit, IconFileInvoice } from "@tabler/icons-react";
import { notifications } from "@mantine/notifications";
import { useState } from "react";
import MpesaPanel, { type MpesaPaid } from "@/modules/till/components/MpesaPanel";
import type { TillCustomer } from "@/types/customers";
import type { TenderMethod, TenderPayload } from "@/types/sales";
import { formatKes, optionalKesToCents, roundTo } from "@/utils/money";

type Mode = "cash" | "mpesa" | "card" | "credit" | "split";

interface TenderModalProps {
  totalCents: number;
  /** "stk": Safaricom confirms M-PESA; "manual": the cashier types the code (unverified). */
  mpesaMode: "stk" | "manual";
  mpesaDemo: boolean;
  /** Settings → Payments: accepted methods in button order, split payments, cash rounding, STK Push. */
  methods: TenderMethod[];
  splitAllowed: boolean;
  cashRoundingCents: number;
  stkPush: boolean;
  /** Registered customer on the sale; their KRA PIN goes on the invoice. */
  customer: TillCustomer | null;
  /** Offline: cash and card only (M-PESA needs Safaricom). */
  offline?: boolean;
  pending: boolean;
  serverError: string | null;
  onClose: () => void;
  onPay: (tenders: TenderPayload[], customerPin: string | null) => void;
}

const MODE_LABEL: Record<Mode, { text: string; icon: React.ReactNode }> = {
  cash: { text: "Cash", icon: <IconCash size={18} /> },
  mpesa: { text: "M-PESA", icon: <IconDeviceMobile size={18} /> },
  card: { text: "Card", icon: <IconCreditCard size={18} /> },
  credit: { text: "On account", icon: <IconFileInvoice size={18} /> },
  split: { text: "Split", icon: <IconArrowsSplit size={18} /> },
};

/** Quick cash buttons: exact, then the next round amounts a customer is likely to hand over. */
function quickCash(totalCents: number): number[] {
  const kes = Math.ceil(totalCents / 100);
  const options = [100, 500, 1000].map((step) => Math.ceil(kes / step) * step).filter((v) => v * 100 > totalCents);
  return [totalCents / 100, ...new Set(options)].slice(0, 4);
}

/**
 * Take payment. M-PESA and card are applied first; cash covers the rest and any excess is change
 * (the same rule the API applies). M-PESA codes are checked against the statement until the
 * Payments module confirms them automatically.
 */
export default function TenderModal({ totalCents, mpesaMode, mpesaDemo, methods, splitAllowed, cashRoundingCents, stkPush, customer, offline = false, pending, serverError, onClose, onPay }: TenderModalProps) {
  const modes: Mode[] = [...methods, ...(splitAllowed && methods.length > 1 ? (["split"] as const) : [])];
  // On account: only for a picked account customer, and never offline (the limit cannot be checked).
  const creditAvailable = customer?.creditAvailableCents ?? null;
  const usable = (m: Mode) => !(offline && (m === "mpesa" || m === "credit")) && !(m === "credit" && creditAvailable === null);
  const [mode, setMode] = useState<Mode>(() => modes.find(usable) ?? "cash");
  const [cashKes, setCashKes] = useState<number | string>(roundTo(totalCents, cashRoundingCents) / 100);
  const [mpesaKes, setMpesaKes] = useState<number | string>("");
  const [mpesaCode, setMpesaCode] = useState("");
  const [cardKes, setCardKes] = useState<number | string>("");
  const [creditKes, setCreditKes] = useState<number | string>("");
  const [cardRef, setCardRef] = useState("");
  const [cardLast4, setCardLast4] = useState("");
  const [customerPin, setCustomerPin] = useState("");
  const [mpesaPaid, setMpesaPaid] = useState<MpesaPaid | null>(null);
  const stk = mpesaMode === "stk";

  const mpesaCents = mode === "mpesa" ? totalCents : mode === "split" ? (optionalKesToCents(mpesaKes) ?? 0) : 0;
  const cardCents = mode === "card" ? totalCents : mode === "split" ? (optionalKesToCents(cardKes) ?? 0) : 0;
  const creditCents = mode === "credit" ? totalCents : mode === "split" && usable("credit") ? (optionalKesToCents(creditKes) ?? 0) : 0;
  const cashCents = mode === "cash" || mode === "split" ? (optionalKesToCents(cashKes) ?? 0) : 0;
  const exactCashDue = totalCents - mpesaCents - cardCents - creditCents;
  // Cash is rounded to the owner's step; M-PESA and card are exact.
  const cashDue = exactCashDue > 0 ? roundTo(exactCashDue, cashRoundingCents) : exactCashDue;
  // M-PESA only counts as paid once Safaricom confirms it.
  const mpesaOutstanding = stk && mpesaCents > 0 && mpesaPaid?.amountCents !== mpesaCents ? mpesaCents : 0;
  const remaining = Math.max(0, cashDue - cashCents) + mpesaOutstanding;
  const change = cashDue >= 0 ? Math.max(0, cashCents - cashDue) : 0;

  const problems: string[] = [];
  if (cashDue < 0) problems.push("M-PESA, card and on-account amounts cannot be more than the total.");
  if (mpesaCents > 0 && stk && mpesaPaid?.amountCents !== mpesaCents) problems.push("Waiting for M-PESA: send the request or pick the customer's payment.");
  if (mpesaCents > 0 && !stk && !/^[A-Za-z0-9]{8,12}$/.test(mpesaCode.trim())) problems.push("Enter the M-PESA code from the customer's message.");
  if (cardCents > 0 && !/^[A-Za-z0-9]{4,30}$/.test(cardRef.trim())) problems.push("Enter the card approval code from the terminal slip.");
  if (cardLast4 && !/^\d{4}$/.test(cardLast4)) problems.push("Card last 4 must be 4 digits.");
  if (customerPin && !/^[A-Za-z]\d{9}[A-Za-z]$/.test(customerPin.trim())) problems.push("KRA PIN looks like A123456789B.");

  // A received M-PESA payment is never lost: it stays in the "already paid" list for this branch.
  const close = () => {
    if (mpesaPaid) {
      notifications.show({ color: "yellow", autoClose: false, message: `M-PESA ${mpesaPaid.receipt} is kept. Pick it under "Customer already paid to the till?" when you take payment again.` });
    }
    onClose();
  };

  const canPay = remaining === 0 && problems.length === 0;

  const pay = () => {
    if (!canPay) return;
    const tenders: TenderPayload[] = [];
    if (mpesaCents > 0) {
      tenders.push(
        stk && mpesaPaid
          ? { method: "mpesa", amountCents: mpesaCents, confirmationId: mpesaPaid.confirmationId, reference: mpesaPaid.receipt }
          : { method: "mpesa", amountCents: mpesaCents, reference: mpesaCode.trim() },
      );
    }
    if (cardCents > 0) tenders.push({ method: "card", amountCents: cardCents, reference: cardRef.trim(), cardLast4: cardLast4 || null });
    if (creditCents > 0) tenders.push({ method: "credit", amountCents: creditCents });
    if (cashCents > 0) tenders.push({ method: "cash", amountCents: cashCents });
    onPay(tenders, customerPin.trim() || null);
  };

  const cashInput = (
    <Stack gap="xs">
      <NumberInput label="Cash received (KES)" size="lg" min={0} decimalScale={2} thousandSeparator="," value={cashKes} onChange={setCashKes} data-autofocus />
      {mode === "cash" && (
        <Group gap="xs">
          {quickCash(cashDue).map((kes, i) => (
            <Button key={kes} variant="light" onClick={() => setCashKes(kes)}>
              {i === 0 ? "Exact" : kes.toLocaleString("en-KE")}
            </Button>
          ))}
        </Group>
      )}
    </Stack>
  );
  const mpesaAmountInput = mode === "split" && (
    <NumberInput
      label="M-PESA amount"
      description={stk ? "Whole shillings" : undefined}
      min={0}
      decimalScale={stk ? 0 : 2}
      thousandSeparator=","
      value={mpesaKes}
      onChange={setMpesaKes}
      disabled={Boolean(mpesaPaid)}
    />
  );
  const mpesaInputs = stk ? (
    <Stack gap="xs">
      {mpesaAmountInput}
      {mpesaCents > 0 && <MpesaPanel amountCents={mpesaCents} demo={mpesaDemo} paid={mpesaPaid} onPaid={setMpesaPaid} stkPush={stkPush} />}
    </Stack>
  ) : (
    <Group grow align="flex-start">
      {mpesaAmountInput}
      <TextInput label="M-PESA code" placeholder="e.g. SIP4XK9ABC" value={mpesaCode} onChange={(e) => setMpesaCode(e.currentTarget.value.toUpperCase())} styles={{ input: { fontFamily: "var(--font-mono), monospace" } }} />
    </Group>
  );
  const cardInputs = (
    <Group grow align="flex-start">
      {mode === "split" && <NumberInput label="Card amount" min={0} decimalScale={2} thousandSeparator="," value={cardKes} onChange={setCardKes} />}
      <TextInput label="Approval code" value={cardRef} onChange={(e) => setCardRef(e.currentTarget.value.toUpperCase())} styles={{ input: { fontFamily: "var(--font-mono), monospace" } }} />
      <TextInput label="Last 4 (optional)" maxLength={4} inputMode="numeric" value={cardLast4} onChange={(e) => setCardLast4(e.currentTarget.value.replace(/\D/g, ""))} />
    </Group>
  );

  return (
    <Modal opened onClose={close} title="Take payment" centered size="lg" closeOnClickOutside={!pending}>
      <Stack>
        <Group justify="space-between" align="baseline">
          <Text c="dimmed">Amount due</Text>
          <Text className="tessera-display" fz={40}>
            {formatKes(totalCents)}
          </Text>
        </Group>
        {mode === "cash" && cashDue !== totalCents && (
          <Text size="sm" c="dimmed" ta="right" mt={-12}>
            Cash rounded to {formatKes(cashDue)}
          </Text>
        )}

        <SegmentedControl
          fullWidth
          size="md"
          value={mode}
          onChange={(value) => setMode(value as Mode)}
          disabled={Boolean(mpesaPaid)}
          data={modes.map((m) => ({ value: m, label: <Label icon={MODE_LABEL[m].icon} text={MODE_LABEL[m].text} />, disabled: !usable(m) }))}
        />

        {mode === "credit" && customer && <CreditNote name={customer.name} available={creditAvailable} amount={creditCents} />}
        {mode === "cash" && cashInput}
        {mode === "mpesa" && mpesaInputs}
        {mode === "card" && cardInputs}
        {offline && (
          <Text size="xs" c="yellow.8">
            Offline: cash and card only. This sale is saved on the till and sent when the connection is back.
          </Text>
        )}
        {mode === "split" && (
          <Stack gap="sm">
            {!offline && methods.includes("mpesa") && mpesaInputs}
            {methods.includes("card") && cardInputs}
            {methods.includes("credit") && usable("credit") && customer && (
              <Stack gap={4}>
                <NumberInput label="On account" min={0} decimalScale={2} thousandSeparator="," value={creditKes} onChange={setCreditKes} />
                <CreditNote name={customer.name} available={creditAvailable} amount={creditCents} />
              </Stack>
            )}
            {methods.includes("cash") && cashInput}
          </Stack>
        )}

        {customer?.kraPin ? (
          <Text size="sm">
            Tax invoice to <b>{customer.name}</b> · KRA PIN <b>{customer.kraPin}</b>
          </Text>
        ) : (
          <TextInput label="Customer KRA PIN (optional, for a tax invoice)" placeholder="A123456789B" value={customerPin} onChange={(e) => setCustomerPin(e.currentTarget.value.toUpperCase())} />
        )}

        <SimpleGrid cols={2}>
          <div>
            <Text size="sm" c="dimmed">
              Still to pay
            </Text>
            <Text fw={700} fz="xl" c={remaining > 0 ? "red" : undefined}>
              {formatKes(remaining)}
            </Text>
          </div>
          <div>
            <Text size="sm" c="dimmed">
              Change
            </Text>
            <Text fw={700} fz="xl" c={change > 0 ? "green" : undefined}>
              {formatKes(change)}
            </Text>
          </div>
        </SimpleGrid>

        {problems.length > 0 && <Alert color="yellow">{problems[0]}</Alert>}
        {serverError && <Alert color="red">{serverError}</Alert>}

        <Group justify="flex-end">
          <Button variant="default" onClick={close} disabled={pending}>
            Back to sale
          </Button>
          <Button size="lg" color="amber.5" c="dark.9" onClick={pay} loading={pending} disabled={!canPay}>
            Complete sale
          </Button>
        </Group>
      </Stack>
    </Modal>
  );
}

function Label({ icon, text }: { icon: React.ReactNode; text: string }) {
  return (
    <Group gap={6} justify="center" wrap="nowrap">
      {icon}
      <span>{text}</span>
    </Group>
  );
}

/** Who the sale goes on account to and how much credit is left; over it, a manager approves. */
function CreditNote({ name, available, amount }: { name: string; available: number | null; amount: number }) {
  const over = available !== null && amount > available;

  return (
    <Alert color={over ? "yellow" : "tessera"} variant="light">
      On <b>{name}</b>&apos;s account · {formatKes(available)} available
      {over && ". This is over the limit: a manager approves when you complete the sale."}
    </Alert>
  );
}
