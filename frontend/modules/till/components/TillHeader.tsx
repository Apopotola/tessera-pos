"use client";

import { Group, Text } from "@mantine/core";
import { LogoMark } from "@/components/brand/Logo";
import { formatClock, useClock } from "@/modules/till/hooks/useClock";
import type { TillContext } from "@/types/till";

/** Shop + till name on the left, live clock on the right. */
export default function TillHeader({ context }: { context: TillContext }) {
  const { time, day } = formatClock(useClock());
  const tillLabel = [context.till.name, context.till.description].filter(Boolean).join(" · ");

  return (
    <Group justify="space-between" align="flex-start" wrap="nowrap">
      <Group gap="sm" wrap="nowrap">
        <LogoMark size={28} />
        <div>
          <Text c="white" fw={700} lh={1.2}>
            {context.business.name}
          </Text>
          <Text c="gray.5" size="sm" lh={1.3}>
            {tillLabel}
          </Text>
        </div>
      </Group>
      <div style={{ textAlign: "right" }}>
        <Text c="white" className="tessera-display" fz={32}>
          {time}
        </Text>
        <Text c="gray.5" size="sm">
          {day}
        </Text>
      </div>
    </Group>
  );
}
