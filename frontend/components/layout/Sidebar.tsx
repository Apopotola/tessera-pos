"use client";

import { NavLink, ScrollArea } from "@mantine/core";
import { createElement } from "react";
import { menuIcon } from "@/components/layout/menuIcons";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { openTab } from "@/store/slices/tabsSlice";
import type { MenuItem } from "@/types/auth";

function containsPath(item: MenuItem, path: string): boolean {
  return item.path === path || item.children.some((child) => containsPath(child, path));
}

export default function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const dispatch = useAppDispatch();
  const menus = useAppSelector((state) => state.auth.menus);
  const activePath = useAppSelector((state) => state.tabs.tabs.find((tab) => tab.id === state.tabs.activeTabId)?.path);

  const renderItem = (item: MenuItem, depth: number) => {
    const icon = depth === 0 ? createElement(menuIcon(item.icon), { size: 18, stroke: 1.6 }) : undefined;

    if (item.children.length > 0) {
      return (
        <NavLink
          key={item.key}
          label={item.title}
          leftSection={icon}
          defaultOpened={activePath ? containsPath(item, activePath) : false}
          childrenOffset={28}
        >
          {item.children.map((child) => renderItem(child, depth + 1))}
        </NavLink>
      );
    }

    const { viewType, path } = item;
    if (!viewType || !path) return null;

    return (
      <NavLink
        key={item.key}
        label={item.title}
        leftSection={icon}
        active={activePath === path}
        onClick={() => {
          dispatch(openTab({ title: item.title, path, view: viewType }));
          onNavigate?.();
        }}
      />
    );
  };

  return (
    <ScrollArea h="100%" type="scroll">
      {menus.map((item) => renderItem(item, 0))}
    </ScrollArea>
  );
}
