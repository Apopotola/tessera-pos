"use client";

import { ActionIcon, Group, Menu, ScrollArea, Tabs, Text } from "@mantine/core";
import { IconX } from "@tabler/icons-react";
import { useState, type MouseEvent } from "react";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { closeOtherTabs, closeTab, closeTabsToRight, setActiveTab } from "@/store/slices/tabsSlice";

/** Tab strip: click to switch, × or middle-click to close, right-click for bulk close. */
export default function WorkspaceTabs() {
  const dispatch = useAppDispatch();
  const { tabs, activeTabId } = useAppSelector((state) => state.tabs);
  const [menuTabId, setMenuTabId] = useState<string | null>(null);

  const onAuxClick = (event: MouseEvent, tabId: string) => {
    if (event.button === 1) dispatch(closeTab(tabId));
  };

  return (
    <ScrollArea type="never" offsetScrollbars={false} bg="white" style={{ borderBottom: "1px solid var(--mantine-color-gray-3)" }}>
      <Tabs value={activeTabId} onChange={(value) => value && dispatch(setActiveTab(value))} variant="pills" radius="md" px="sm" py={8}>
        <Tabs.List style={{ flexWrap: "nowrap", gap: 4 }}>
          {tabs.map((tab) => (
            <Menu
              key={tab.id}
              opened={menuTabId === tab.id}
              onChange={(opened) => setMenuTabId(opened ? tab.id : null)}
              position="bottom-start"
              withinPortal
            >
              <Menu.Target>
                <Tabs.Tab
                  value={tab.id}
                  onAuxClick={(event) => onAuxClick(event, tab.id)}
                  onContextMenu={(event) => {
                    event.preventDefault();
                    setMenuTabId(tab.id);
                  }}
                >
                  <Group gap={6} wrap="nowrap">
                    <Text size="sm" truncate maw={180}>
                      {tab.title}
                    </Text>
                    {tab.isClosable && (
                      <ActionIcon
                        component="span"
                        size="xs"
                        variant="transparent"
                        // Inherit the tab's text colour so the × stays visible on the active (filled) pill.
                        style={{ color: "inherit", opacity: 0.75 }}
                        aria-label={`Close ${tab.title}`}
                        onClick={(event: MouseEvent) => {
                          event.stopPropagation();
                          dispatch(closeTab(tab.id));
                        }}
                      >
                        <IconX size={12} />
                      </ActionIcon>
                    )}
                  </Group>
                </Tabs.Tab>
              </Menu.Target>
              <Menu.Dropdown>
                <Menu.Item disabled={!tab.isClosable} onClick={() => dispatch(closeTab(tab.id))}>
                  Close
                </Menu.Item>
                <Menu.Item onClick={() => dispatch(closeOtherTabs(tab.id))}>Close others</Menu.Item>
                <Menu.Item onClick={() => dispatch(closeTabsToRight(tab.id))}>Close tabs to the right</Menu.Item>
              </Menu.Dropdown>
            </Menu>
          ))}
        </Tabs.List>
      </Tabs>
    </ScrollArea>
  );
}
