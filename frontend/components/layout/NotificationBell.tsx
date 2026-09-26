"use client";

import { ActionIcon, Anchor, Group, Indicator, Popover, ScrollArea, Stack, Text, UnstyledButton } from "@mantine/core";
import { IconBell } from "@tabler/icons-react";
import dayjs from "dayjs";
import relativeTime from "dayjs/plugin/relativeTime";
import { useCallback, useEffect, useState } from "react";
import { notificationsApi } from "@/api";
import { useAppDispatch } from "@/store/hooks";
import { openTab } from "@/store/slices/tabsSlice";
import type { Inbox, InboxItem } from "@/types/notifications";

dayjs.extend(relativeTime);

const POLL_MS = 60_000;

/** The header bell: my alerts (low stock, large refund, cash variance, eTIMS, end of day). */
export default function NotificationBell() {
  const dispatch = useAppDispatch();
  const [inbox, setInbox] = useState<Inbox | null>(null);
  const [opened, setOpened] = useState(false);

  const load = useCallback(() => {
    notificationsApi
      .inbox()
      .then(setInbox)
      .catch(() => undefined);
  }, []);

  // Poll quietly; the API does not count this as activity for the idle sign-out.
  useEffect(() => {
    load();
    const timer = window.setInterval(load, POLL_MS);
    return () => window.clearInterval(timer);
  }, [load]);

  const open = (item: InboxItem) => {
    if (!item.readAt) {
      void notificationsApi.read(item.id).then(load);
    }
    if (item.link) dispatch(openTab(item.link));
    setOpened(false);
  };

  const readAll = () => void notificationsApi.readAll().then(load);
  const unread = inbox?.unread ?? 0;

  return (
    <Popover opened={opened} onChange={setOpened} position="bottom-end" width={360} shadow="md" withinPortal>
      <Popover.Target>
        <Indicator label={unread > 99 ? "99+" : unread} size={16} color="amber.5" disabled={unread === 0} offset={4}>
          <ActionIcon
            variant="subtle"
            color="gray.0"
            size="lg"
            aria-label={unread ? `${unread} unread notifications` : "Notifications"}
            onClick={() => {
              setOpened((o) => !o);
              load();
            }}
          >
            <IconBell size={20} />
          </ActionIcon>
        </Indicator>
      </Popover.Target>
      <Popover.Dropdown p={0}>
        <Group justify="space-between" px="md" py="sm" style={{ borderBottom: "1px solid var(--mantine-color-gray-2)" }}>
          <Text fw={700}>Notifications</Text>
          {unread > 0 && (
            <Anchor component="button" size="xs" onClick={readAll}>
              Mark all read
            </Anchor>
          )}
        </Group>
        <ScrollArea.Autosize mah={420}>
          {!inbox?.items.length ? (
            <Text size="sm" c="dimmed" p="md">
              Nothing yet. Low stock, large refunds, cash differences, eTIMS problems and the end-of-day summary show up here.
            </Text>
          ) : (
            <Stack gap={0}>
              {inbox.items.map((item) => (
                <UnstyledButton
                  key={item.id}
                  onClick={() => open(item)}
                  px="md"
                  py="sm"
                  style={{ borderBottom: "1px solid var(--mantine-color-gray-1)", background: item.readAt ? undefined : "var(--mantine-color-tessera-0)" }}
                >
                  <Text size="sm" fw={item.readAt ? 500 : 700} lineClamp={1}>
                    {item.title}
                  </Text>
                  <Text size="xs" c="dimmed" lineClamp={3} style={{ whiteSpace: "pre-line" }}>
                    {item.body}
                  </Text>
                  <Text size="xs" c="dimmed" mt={2}>
                    {dayjs(item.createdAt).fromNow()}
                  </Text>
                </UnstyledButton>
              ))}
            </Stack>
          )}
        </ScrollArea.Autosize>
      </Popover.Dropdown>
    </Popover>
  );
}
