"use client";

import { Button, Group, SimpleGrid, Text, UnstyledButton } from "@mantine/core";
import { IconBackspace } from "@tabler/icons-react";
import { useCallback, useEffect, useState } from "react";
import classes from "../Till.module.css";

export const PIN_LENGTH = 4;

/** PIN being typed on the pad or a keyboard. */
export function usePinEntry(busy: boolean) {
  const [pin, setPin] = useState("");

  const press = useCallback(
    (key: string) => {
      if (busy) return;
      if (key === "clear") setPin("");
      else if (key === "back") setPin((p) => p.slice(0, -1));
      else setPin((p) => (p.length < PIN_LENGTH ? p + key : p));
    },
    [busy],
  );

  return { pin, press, reset: useCallback(() => setPin(""), []) };
}

interface PinPadProps {
  pin: string;
  press: (key: string) => void;
  onSubmit: () => void;
  busy: boolean;
  error: string | null;
  submitLabel: string;
}

/** Dots, the error line, the 0–9 keypad (physical keyboard and numpad too) and the submit button. */
export default function PinPad({ pin, press, onSubmit, busy, error, submitLabel }: PinPadProps) {
  useEffect(() => {
    const onKey = (event: KeyboardEvent) => {
      if (/^\d$/.test(event.key)) press(event.key);
      else if (event.key === "Backspace") press("back");
      else if (event.key === "Escape") press("clear");
      else if (event.key === "Enter") onSubmit();
    };
    window.addEventListener("keydown", onKey);
    return () => window.removeEventListener("keydown", onKey);
  }, [press, onSubmit]);

  return (
    <>
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

      <Button fullWidth size="xl" mt="lg" radius="md" disabled={pin.length !== PIN_LENGTH} loading={busy} onClick={onSubmit} className={classes.start}>
        {submitLabel}
      </Button>
    </>
  );
}
