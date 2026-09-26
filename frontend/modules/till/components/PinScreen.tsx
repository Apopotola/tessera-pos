"use client";

import { Anchor, Avatar, Group, SimpleGrid, Stack, Text, UnstyledButton } from "@mantine/core";
import { IconClock } from "@tabler/icons-react";
import Link from "next/link";
import { useCallback, useState } from "react";
import { ApiError, salesApi } from "@/api";
import { brand } from "@/app/theme";
import PinPad, { PIN_LENGTH, usePinEntry } from "@/modules/till/components/PinPad";
import TillHeader from "@/modules/till/components/TillHeader";
import { useAppDispatch } from "@/store/hooks";
import { pinLogin } from "@/store/slices/authSlice";
import type { Shift, TillCashier, TillContext } from "@/types/till";
import { formatKes } from "@/utils/money";
import classes from "../Till.module.css";

export const AVATAR_COLORS = [brand.purple, brand.amber, brand.lilac, "#3dbb7f", "#e8664a", "#c9c6d6"];

interface PinScreenProps {
  context: TillContext;
  onShiftStarted: (shift: Shift) => void;
}

/** "Who's on the till?" — pick your card, enter your PIN, start (or resume) your shift. */
export default function PinScreen({ context, onShiftStarted }: PinScreenProps) {
  const dispatch = useAppDispatch();
  const [selected, setSelected] = useState<TillCashier | null>(context.cashiers[0] ?? null);
  const [error, setError] = useState<string | null>(null);
  const [busy, setBusy] = useState(false);
  const { pin, press: pressKey, reset } = usePinEntry(busy);
  const press = useCallback(
    (key: string) => {
      setError(null);
      pressKey(key);
    },
    [pressKey],
  );

  const select = (cashier: TillCashier) => {
    setSelected(cashier);
    reset();
    setError(null);
  };

  const submit = useCallback(async () => {
    if (!selected || pin.length !== PIN_LENGTH || busy) return;
    setBusy(true);
    const result = await dispatch(pinLogin({ userId: selected.id, pin }));

    if (pinLogin.rejected.match(result)) {
      setError(result.payload ?? "Wrong PIN. Try again.");
      reset();
      setBusy(false);
      return;
    }

    try {
      onShiftStarted(await salesApi.startShift());
    } catch (e) {
      setError(e instanceof ApiError ? (Object.values(e.fieldErrors)[0]?.[0] ?? e.message) : "Could not start the shift.");
      reset();
      setBusy(false);
    }
  }, [busy, dispatch, onShiftStarted, pin, selected, reset]);

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

            <PinPad pin={pin} press={press} onSubmit={() => void submit()} busy={busy} error={error} submitLabel="Start shift" />
          </Stack>
        ) : (
          <Text c="gray.5">Select your name to sign in.</Text>
        )}
      </section>
    </div>
  );
}
