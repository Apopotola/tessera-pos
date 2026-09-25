"use client";

import { Anchor, Avatar, Button, Group, SimpleGrid, Stack, Text, UnstyledButton } from "@mantine/core";
import { IconBackspace, IconClock } from "@tabler/icons-react";
import Link from "next/link";
import { useCallback, useEffect, useState } from "react";
import { ApiError, salesApi } from "@/api";
import { brand } from "@/app/theme";
import TillHeader from "@/modules/till/components/TillHeader";
import { useAppDispatch } from "@/store/hooks";
import { pinLogin } from "@/store/slices/authSlice";
import type { Shift, TillCashier, TillContext } from "@/types/till";
import { formatKes } from "@/utils/money";
import classes from "../Till.module.css";

const PIN_LENGTH = 4;
const AVATAR_COLORS = [brand.purple, brand.amber, brand.lilac, "#3dbb7f", "#e8664a", "#c9c6d6"];

interface PinScreenProps {
  context: TillContext;
  onShiftStarted: (shift: Shift) => void;
}

/** "Who's on the till?" — pick your card, enter your PIN, start (or resume) your shift. */
export default function PinScreen({ context, onShiftStarted }: PinScreenProps) {
  const dispatch = useAppDispatch();
  const [selected, setSelected] = useState<TillCashier | null>(context.cashiers[0] ?? null);
  const [pin, setPin] = useState("");
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);

  const select = (cashier: TillCashier) => {
    setSelected(cashier);
    setPin("");
    setError(null);
  };

  const press = useCallback(
    (key: string) => {
      if (busy) return;
      setError(null);
      if (key === "clear") setPin("");
      else if (key === "back") setPin((p) => p.slice(0, -1));
      else setPin((p) => (p.length < PIN_LENGTH ? p + key : p));
    },
    [busy],
  );

  const submit = useCallback(async () => {
    if (!selected || pin.length !== PIN_LENGTH || busy) return;
    setBusy(true);
    const result = await dispatch(pinLogin({ userId: selected.id, pin }));

    if (pinLogin.rejected.match(result)) {
      setError(result.payload ?? "Wrong PIN. Try again.");
      setPin("");
      setBusy(false);
      return;
    }

    try {
      onShiftStarted(await salesApi.startShift());
    } catch (e) {
      setError(e instanceof ApiError ? (Object.values(e.fieldErrors)[0]?.[0] ?? e.message) : "Could not start the shift.");
      setPin("");
      setBusy(false);
    }
  }, [busy, dispatch, onShiftStarted, pin, selected]);

  // Physical keyboard / numpad support.
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (/^\d$/.test(event.key)) press(event.key);
      else if (event.key === "Backspace") press("back");
      else if (event.key === "Escape") press("clear");
      else if (event.key === "Enter") void submit();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [press, submit]);

  const colorFor = (cashier: TillCashier) => AVATAR_COLORS[context.cashiers.indexOf(cashier) % AVATAR_COLORS.length];

  return (
    <div className={classes.split}>
      <section className={classes.main} style={{ background: brand.navy }}>
        <TillHeader context={context} />

        <h1 className={`tessera-display ${classes.heading}`}>Who&apos;s on the till?</h1>

        {context.cashiers.length === 0 ? (
          <Text c="gray.4">
            Nobody can sign in here yet. A manager must give staff the Cashier role, this branch and a PIN in Administration → Users.
          </Text>
        ) : (
          <SimpleGrid cols={{ base: 2, sm: 3 }} spacing="md">
            {context.cashiers.map((cashier) => (
              <UnstyledButton
                key={cashier.id}
                className={classes.card}
                data-active={selected?.id === cashier.id || undefined}
                onClick={() => select(cashier)}
                aria-pressed={selected?.id === cashier.id}
              >
                <Group gap="sm" wrap="nowrap">
                  <Avatar size={52} radius="xl" styles={{ placeholder: { background: colorFor(cashier), color: brand.navy, fontWeight: 700 } }}>
                    {cashier.initials}
                  </Avatar>
                  <div>
                    <Text c="white" fw={700}>
                      {cashier.displayName}
                    </Text>
                    <Text c="gray.5" size="sm">
                      {cashier.role}
                    </Text>
                  </div>
                </Group>
              </UnstyledButton>
            ))}
          </SimpleGrid>
        )}

        <Group className={classes.footer} gap="sm" justify="space-between">
          <Group gap="sm">
            <IconClock size={18} color={brand.amber} />
            <Text c="gray.3" size="sm">
              Opening float {formatKes(context.till.defaultFloatCents)}
            </Text>
          </Group>
          <Anchor component={Link} href="/login" c="gray.5" size="sm">
            Back office
          </Anchor>
        </Group>
      </section>

      <section className={classes.side} style={{ background: brand.navyRaised }}>
        {selected ? (
          <Stack align="center" gap="xs" w="100%" maw={380}>
            <Avatar size={84} radius={999} styles={{ placeholder: { background: colorFor(selected), color: brand.navy, fontWeight: 700, fontSize: 28 } }}>
              {selected.initials}
            </Avatar>
            <Text c="white" fw={700} fz="xl">
              {selected.displayName}
            </Text>
            <Text c="gray.5" size="sm">
              Enter your {PIN_LENGTH}-digit PIN
            </Text>

            <Group gap="md" my="md" aria-label={`${pin.length} of ${PIN_LENGTH} digits entered`}>
              {Array.from({ length: PIN_LENGTH }, (_, i) => (
                <span key={i} className={classes.dot} data-filled={i < pin.length || undefined} />
              ))}
            </Group>

            <Text c="red.4" size="sm" mih={20} role="alert">
              {error}
            </Text>

            <SimpleGrid cols={3} spacing="sm" w="100%">
              {["1", "2", "3", "4", "5", "6", "7", "8", "9"].map((digit) => (
                <UnstyledButton key={digit} className={classes.key} onClick={() => press(digit)}>
                  {digit}
                </UnstyledButton>
              ))}
              <UnstyledButton className={classes.keyText} onClick={() => press("clear")}>
                Clear
              </UnstyledButton>
              <UnstyledButton className={classes.key} onClick={() => press("0")}>
                0
              </UnstyledButton>
              <UnstyledButton className={classes.keyText} onClick={() => press("back")} aria-label="Delete last digit">
                <IconBackspace size={24} />
              </UnstyledButton>
            </SimpleGrid>

            <Button fullWidth size="xl" mt="lg" radius="md" disabled={pin.length !== PIN_LENGTH} loading={busy} onClick={() => void submit()} className={classes.start}>
              Start shift
            </Button>
          </Stack>
        ) : (
          <Text c="gray.5">Select your name to sign in.</Text>
        )}
      </section>
    </div>
  );
}
