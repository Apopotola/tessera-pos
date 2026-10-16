"use client";

import { CloseButton, Group, NavLink, ScrollArea, Stack, Text } from "@mantine/core";
import { Fragment } from "react";
import { LogoMark } from "@/components/brand/Logo";
import { useBrand } from "@/components/brand/useBrand";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { openTab } from "@/store/slices/tabsSlice";
import type { MenuItem } from "@/types/auth";
import classes from "./Sidebar.module.css";

/** The trade under the shop name (Settings → Business → Industry preset). */
const TRADE: Record<string, string> = {
  wines_spirits: "Wines & Spirits",
  general_retail: "General retail",
  pharmacy: "Pharmacy",
  supermarket: "Supermarket",
  hardware: "Hardware",
  restaurant_bar: "Restaurant & bar",
};

/**
 * Navy sidebar: the shop's name and trade, then a flat, permission-filtered menu. Groups are
 * small captions with their screens listed under them, so every screen is one click away.
 */
export default function Sidebar({ onNavigate }: { onNavigate?: () => void }) {
  const dispatch = useAppDispatch();
  const brand = useBrand();
  const menus = useAppSelector((state) => state.auth.menus);
  const preset = useAppSelector((state) => state.settings.app?.values["business.preset"]);
  const activePath = useAppSelector((state) => state.tabs.tabs.find((tab) => tab.id === state.tabs.activeTabId)?.path);
  const trade = typeof preset === "string" ? TRADE[preset] : undefined;
  // "Tessera Wines & Spirits" already says it: don't repeat the trade underneath.
  const subtitle = trade && !brand.name.toLowerCase().includes(trade.toLowerCase()) ? trade : "Back office";

  const link = (item: MenuItem) => {
    const { viewType, path } = item;
    if (!viewType || !path) return null;

    return (
      <NavLink
        key={item.key}
        label={item.title}
        active={activePath === path}
        classNames={{ root: classes.link, label: classes.label }}
        onClick={() => {
          dispatch(openTab({ title: item.title, path, view: viewType }));
          onNavigate?.();
        }}
      />
    );
  };

  return (
    <Stack h="100%" gap={0}>
      <Group className={classes.brand} wrap="nowrap" gap="sm">
        <LogoMark size={30} tone="light" />
        <div style={{ minWidth: 0, flex: 1 }}>
          <Text className={classes.shop} truncate>
            {brand.name}
          </Text>
          <Text className={classes.trade} truncate>
            {subtitle}
          </Text>
        </div>
        <CloseButton hiddenFrom="sm" variant="transparent" c="gray.4" aria-label="Close menu" onClick={onNavigate} />
      </Group>

      <ScrollArea style={{ flex: 1 }} type="scroll" scrollbarSize={6} px="sm" pb="md">
        {menus.map((item) =>
          item.children.length > 0 ? (
            <Fragment key={item.key}>
              <div className={classes.caption}>{item.title}</div>
              {item.children.map(link)}
            </Fragment>
          ) : (
            link(item)
          ),
        )}
      </ScrollArea>
    </Stack>
  );
}
