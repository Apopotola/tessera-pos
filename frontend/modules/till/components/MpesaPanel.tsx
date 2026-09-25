"use client";

import { Alert, Button, Group, Loader, Paper, RingProgress, Stack, Text, TextInput, UnstyledButton } from "@mantine/core";
import { IconCircleCheck, IconDeviceMobileMessage, IconListSearch, IconRefresh } from "@tabler/icons-react";
import dayjs from "dayjs";
import { useCallback, useEffect, useState } from "react";
import { useApiQuery } from "@/hooks/useApiQuery";
import { ApiError, paymentsApi } from "@/api";
import type { StkRequest } from "@/types/payments";
import { formatKes } from "@/utils/money";

/** The confirmed payment the tender will use. */
export interface MpesaPaid {
  confirmationId: number;
  receipt: string;
  amountCents: number;
}

interface MpesaPanelProps {
  amountCents: number;
  /** Demo driver: no real money moves; show the simulate button. */
  demo: boolean;
  paid: MpesaPaid | null;
  onPaid: (paid: MpesaPaid | null) => void;
}

type Mode = "prompt" | "pick";

/**
 * M-PESA is only "paid" when Safaricom says so: either the customer approves the prompt on
 * their phone, or they paid the till themselves and the cashier picks that payment.
 */
export default function MpesaPanel({ amountCents, demo, paid, onPaid }: MpesaPanelProps) {
  const [mode, setMode] = useState<Mode>("prompt");

  if (paid) {
    return (
      <Paper withBorder radius="md" p="md" bg="var(--mantine-color-green-0)">
        <Group justify="space-between" wrap="nowrap">
          <Group gap="sm" wrap="nowrap">
            <IconCircleCheck size={28} color="var(--mantine-color-green-7)" />
            <div>
              <Text fw={700}>M-PESA received {formatKes(paid.amountCents)}</Text>
              <Text size="sm" c="dimmed" ff="monospace">
                {paid.receipt}
              </Text>
            </div>
          </Group>
          <Button variant="subtle" size="xs" onClick={() => onPaid(null)}>
            Use another
          </Button>
        </Group>
      </Paper>
    );
  }

  return (
    <Stack gap="sm">
      {demo && (
        <Text size="xs" c="yellow.8">
          Demo M-PESA: no real money moves. The customer &ldquo;approves&rdquo; after a few seconds; a number ending in 000 declines.
        </Text>
      )}
      {mode === "prompt" ? <StkPrompt amountCents={amountCents} onPaid={onPaid} /> : <PickPayment amountCents={amountCents} onPaid={onPaid} demo={demo} />}
      <Button
        variant="subtle"
        size="xs"
        w="fit-content"
        leftSection={mode === "prompt" ? <IconListSearch size={14} /> : <IconDeviceMobileMessage size={14} />}
        onClick={() => setMode(mode === "prompt" ? "pick" : "prompt")}
      >
        {mode === "prompt" ? "Customer already paid to the till?" : "Send a payment request instead"}
      </Button>
    </Stack>
  );
}

function StkPrompt({ amountCents, onPaid }: { amountCents: number; onPaid: (paid: MpesaPaid) => void }) {
  const [phone, setPhone] = useState("");
  const [request, setRequest] = useState<StkRequest | null>(null);
  const [error, setError] = useState<string | null>(null);
  const [sending, setSending] = useState(false);
  const [now, setNow] = useState(() => Date.now());

  const send = async () => {
    setSending(true);
    setError(null);
    try {
      setRequest(await paymentsApi.sendStk(phone, amountCents));
    } catch (e) {
      setError(e instanceof ApiError ? (Object.values(e.formErrors)[0] ?? e.message) : "Could not send the request.");
    } finally {
      setSending(false);
    }
  };

  // Poll every 3 s while waiting; the API asks Safaricom itself if the callback is late.
  const requestId = request?.id;
  const waiting = request?.status === "pending";
  useEffect(() => {
    if (!requestId || !waiting) return;
    const timer = window.setInterval(() => {
      setNow(Date.now());
      paymentsApi
        .stkStatus(requestId)
        .then((latest) => {
          setRequest(latest);
          if (latest.status === "paid" && latest.confirmation) {
            onPaid({ confirmationId: latest.confirmation.id, receipt: latest.confirmation.receipt, amountCents: latest.confirmation.amountCents });
          }
        })
        .catch(() => undefined);
    }, 3000);
    return () => window.clearInterval(timer);
  }, [requestId, waiting, onPaid]);

  if (request && request.status === "pending") {
    const elapsed = request.createdAt ? Math.max(0, (now - dayjs(request.createdAt).valueOf()) / 1000) : 0;
    const left = Math.max(0, Math.ceil(request.timeoutSeconds - elapsed));
    return (
      <Paper withBorder radius="md" p="md">
        <Group wrap="nowrap">
          <RingProgress
            size={72}
            thickness={6}
            sections={[{ value: (left / request.timeoutSeconds) * 100, color: "tessera" }]}
            label={
              <Text ta="center" fw={700}>
                {left}
              </Text>
            }
          />
          <div>
            <Text fw={700}>Waiting for the customer…</Text>
            <Text size="sm" c="dimmed">
              {formatKes(request.amountCents)} requested from {request.phoneMasked}. They enter their M-PESA PIN on their phone.
            </Text>
            {left === 0 && (
              <Group gap={6} mt={4}>
                <Loader size="xs" />
                <Text size="xs" c="dimmed">
                  No answer yet — checking with M-PESA. Do not send another request until this one finishes.
                </Text>
              </Group>
            )}
          </div>
        </Group>
      </Paper>
    );
  }

  return (
    <Stack gap="xs">
      {request?.status === "failed" && (
        <Alert color="red" title="Not paid">
          {request.resultDescription ?? "The customer did not complete the payment."} You can send the request again.
        </Alert>
      )}
      <Group align="flex-end" wrap="nowrap">
        <TextInput
          label="Customer's M-PESA number"
          placeholder="0712 345 678"
          inputMode="tel"
          value={phone}
          onChange={(e) => setPhone(e.currentTarget.value)}
          onKeyDown={(e) => e.key === "Enter" && phone.trim() && void send()}
          style={{ flex: 1 }}
          data-autofocus
        />
        <Button leftSection={<IconDeviceMobileMessage size={16} />} onClick={() => void send()} loading={sending} disabled={!phone.trim() || amountCents <= 0}>
          Send {formatKes(amountCents)} request
        </Button>
      </Group>
      {error && <Alert color="red">{error}</Alert>}
    </Stack>
  );
}

function PickPayment({ amountCents, onPaid, demo }: { amountCents: number; onPaid: (paid: MpesaPaid) => void; demo: boolean }) {
  const fetchUnallocated = useCallback(() => paymentsApi.unallocated(), []);
  const { data: items, error: loadError, reload: load } = useApiQuery(fetchUnallocated);
  const error = loadError ? loadError.message : null;
  const [simulating, setSimulating] = useState(false);

  const simulate = async () => {
    setSimulating(true);
    try {
      await paymentsApi.demoTillPayment(amountCents);
      load();
    } finally {
      setSimulating(false);
    }
  };

  return (
    <Stack gap="xs">
      <Group justify="space-between">
        <Text size="sm" fw={600}>
          Payments to the till not yet on a sale
        </Text>
        <Button variant="subtle" size="xs" leftSection={<IconRefresh size={14} />} onClick={load}>
          Refresh
        </Button>
      </Group>
      {!items && !error && <Loader size="sm" />}
      {items?.length === 0 && (
        <Text size="sm" c="dimmed">
          None yet. Ask the customer to show their M-PESA message, then refresh.
        </Text>
      )}
      {items?.map((c) => {
        const matches = c.amountCents === amountCents;
        return (
          <UnstyledButton
            key={c.id}
            disabled={!matches}
            onClick={() => onPaid({ confirmationId: c.id, receipt: c.receipt, amountCents: c.amountCents })}
            style={{ padding: "10px 12px", borderRadius: 10, border: "1px solid var(--mantine-color-gray-3)", opacity: matches ? 1 : 0.45 }}
          >
            <Group justify="space-between" wrap="nowrap">
              <div>
                <Text fw={600} ff="monospace" size="sm">
                  {c.receipt}
                </Text>
                <Text size="xs" c="dimmed">
                  {[c.payerName, c.phoneMasked, dayjs(c.transactedAt).format("h:mm a")].filter(Boolean).join(" · ")}
                </Text>
              </div>
              <Text fw={700}>{formatKes(c.amountCents)}</Text>
            </Group>
          </UnstyledButton>
        );
      })}
      {demo && (
        <Button variant="light" color="yellow" size="xs" w="fit-content" loading={simulating} onClick={() => void simulate()}>
          Demo: simulate customer paying {formatKes(amountCents)} to the till
        </Button>
      )}
      {items && items.some((c) => c.amountCents !== amountCents) && (
        <Text size="xs" c="dimmed">
          Only payments of exactly {formatKes(amountCents)} can be used for this amount.
        </Text>
      )}
      {error && <Alert color="red">{error}</Alert>}
    </Stack>
  );
}
