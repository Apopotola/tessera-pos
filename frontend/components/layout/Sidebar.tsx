"use client";

import { NavLink, ScrollArea, Stack } from "@mantine/core";
import { createElement } from "react";
import BottleSkyline from "@/components/brand/BottleSkyline";
import { menuIcon } from "@/components/layout/menuIcons";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { openTab } from "@/store/slices/tabsSlice";
import type { MenuItem } from "@/types/auth";
import classes from "./Sidebar.module.css";

function containsPath(item: MenuItem, path: string): boolean {
  return item.path === path || item.children.some((child) => containsPath(child, path));
}

/** Permission-filtered menu on the navy brand panel. */
export default function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const dispatch = useAppDispatch();
  const menus = useAppSelector((state) => state.auth.menus);
  const activePath = useAppSelector((state) => state.tabs.tabs.find((tab) => tab.id === state.tabs.activeTabId)?.path);

  const navClasses = (depth: number) => ({
    root: depth === 0 ? classes.link : `${classes.link} ${classes.child}`,
    section: classes.section,
    chevron: classes.chevron,
    children: classes.children,
  });

  const renderItem = (item: MenuItem, depth: number) => {
    const icon = depth === 0 ? createElement(menuIcon(item.icon), { size: 18, stroke: 1.6 }) : undefined;

    if (item.children.length > 0) {
      return (
        <NavLink
          key={item.key}
          label={item.title}
          leftSection={icon}
          defaultOpened={activePath ? containsPath(item, activePath) : false}
          childrenOffset={0}
          classNames={navClasses(depth)}
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
        classNames={navClasses(depth)}
        onClick={() => {
          dispatch(openTab({ title: item.title, path, view: viewType }));
          onNavigate?.();
        }}
      />
    );
  };

  return (
    <Stack h="100%" gap={0}>
      <ScrollArea style={{ flex: 1 }} type="scroll" scrollbarSize={6}>
        <div className={classes.caption}>Menu</div>
        {menus.map((item) => renderItem(item, 0))}
      </ScrollArea>
      <div className={classes.footer} aria-hidden="true">
        <BottleSkyline height={56} withShelf={false} />
      </div>
    </Stack>
  );
}
