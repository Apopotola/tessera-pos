"use client";

import { Anchor, Avatar, Select, Stack, Text } from "@mantine/core";
import { IconLock } from "@tabler/icons-react";
import { useState } from "react";
import { ApiError, authApi } from "@/api";
import { brand } from "@/app/theme";
import PinPad, { PIN_LENGTH, usePinEntry } from "@/modules/till/components/PinPad";
import type { AuthUser } from "@/types/auth";
import type { TillContext } from "@/types/till";

interface LockScreenProps {
  context: TillContext;
  user: AuthUser;
  onUnlocked: () => void;
}

/**
 * The till after it sat idle (Settings → Staff → till lock) or was locked by the cashier.
 * The sale in progress is kept. The cashier unlocks with their PIN; if they have left,
 * a branch manager can unlock with theirs (logged) to park or finish the sale.
 */
export default function LockScreen({ context, user, onUnlocked }: LockScreenProps) {
  const managers = context.cashiers.filter((c) => c.id !== user.id && c.role !== "Cashier");
  const [unlockerId, setUnlockerId] = useState(user.id);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const { pin, press: pressKey, reset } = usePinEntry(busy);
  const press = (key: string) => {
    setError(null);
    pressKey(key);
  };
  const initials = user.name
    .split(" ")
    .map((p) => p[0])
    .join("")
    .slice(0, 2)
    .toUpperCase();

  const submit = async () => {
    if (pin.length !== PIN_LENGTH || busy) return;
    setBusy(true);
    try {
      await authApi.unlockTill(unlockerId, pin);
      onUnlocked();
    } catch (e) {
      setError(e instanceof ApiError ? (e.formErrors.pin ?? e.message) : "Could not unlock. Try again.");
      reset();
    } finally {
      setBusy(false);
    }
  };

  return (
    <div style={{ position: "fixed", inset: 0, zIndex: 300, background: brand.navy, display: "flex", alignItems: "center", justifyContent: "center", padding: 16 }}>
      <Stack align="center" gap="xs" w="100%" maw={380}>
        <IconLock size={32} color={brand.amber} />
        <Avatar size={84} radius={999} styles={{ placeholder: { background: brand.purple, color: "white", fontWeight: 700, fontSize: 28 } }}>
          {initials}
        </Avatar>
        <Text c="white" fw={700} fz="xl">
          Till locked
        </Text>
        <Text c="gray.4" size="sm" ta="center">
          {user.name}&apos;s sale is kept. {unlockerId === user.id ? "Enter your PIN to carry on." : "Manager: enter your PIN to unlock."}
        </Text>

        {unlockerId !== user.id && managers.length > 0 && (
          <Select
            w="100%"
            data={managers.map((m) => ({ value: String(m.id), label: `${m.displayName} · ${m.role ?? ""}` }))}
            value={String(unlockerId)}
            onChange={(v) => v && setUnlockerId(Number(v))}
            allowDeselect={false}
            aria-label="Manager"
          />
        )}

        <PinPad pin={pin} press={press} onSubmit={() => void submit()} busy={busy} error={error} submitLabel="Unlock" />

        {managers.length > 0 && (
          <Anchor
            component="button"
            type="button"
            c="gray.4"
            size="sm"
            mt="xs"
            onClick={() => {
              setUnlockerId(unlockerId === user.id ? managers[0].id : user.id);
              reset();
              setError(null);
            }}
          >
            {unlockerId === user.id ? `Not ${user.name.split(" ")[0]}? A manager can unlock` : `${user.name.split(" ")[0]} is back`}
          </Anchor>
        )}
      </Stack>
    </div>
  );
}
