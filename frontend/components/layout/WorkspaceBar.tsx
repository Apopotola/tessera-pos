"use client";

import { Burger, Group } from "@mantine/core";
import AccountMenu from "@/components/layout/AccountMenu";
import NotificationBell from "@/components/layout/NotificationBell";
import WorkspaceTabs from "@/components/workspace/WorkspaceTabs";

interface WorkspaceBarProps {
  navOpened: boolean;
  onToggleNav: () => void;
}

/** The only bar above the page: open tabs on the left, alerts and the account on the right. */
export default function WorkspaceBar({ navOpened, onToggleNav }: WorkspaceBarProps) {
  return (
    <Group
      h={48}
      px="md"
      gap="sm"
      wrap="nowrap"
      bg="white"
      style={{ position: "sticky", top: 0, zIndex: 100, borderBottom: "1px solid var(--mantine-color-default-border)" }}
    >
      <Burger opened={navOpened} onClick={onToggleNav} hiddenFrom="sm" size="sm" aria-label="Toggle navigation" />
      <WorkspaceTabs />
      <Group gap="sm" wrap="nowrap">
        <NotificationBell />
        <AccountMenu />
      </Group>
    </Group>
  );
}
