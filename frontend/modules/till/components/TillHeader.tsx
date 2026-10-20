"use client";

import { Group, Text } from "@mantine/core";
import { brand } from "@/app/theme";
import { LogoMark } from "@/components/brand/Logo";
import { formatClock, useClock } from "@/modules/till/hooks/useClock";
import type { TillContext } from "@/types/till";

/** Shop + till name on the left, live clock on the right. `onDark` for the blue sign-in screen. */
export default function TillHeader({ context, onDark = false }: { context: TillContext; onDark?: boolean }) {
  const strong = onDark ? "white" : brand.navy;
  const soft = onDark ? "gray.4" : "dimmed";
  const { time, day } = formatClock(useClock());
  const tillLabel = [context.till.name, context.till.description].filter(Boolean).join(" · ");

  return (
    <Group justify="space-between" align="flex-start" wrap="nowrap">
      <Group gap="sm" wrap="nowrap">
        <LogoMark size={28} />
        <div>
          <Text c={strong} fw={700} lh={1.2}>
            {context.business.name}
          </Text>
          <Text c={soft} size="sm" lh={1.3}>
            {tillLabel}
          </Text>
        </div>
      </Group>
      <div style={{ textAlign: "right" }}>
        <Text c={strong} className="tessera-display" fz={28}>
          {time}
        </Text>
        <Text c={soft} size="sm">
          {day}
        </Text>
      </div>
    </Group>
  );
}
