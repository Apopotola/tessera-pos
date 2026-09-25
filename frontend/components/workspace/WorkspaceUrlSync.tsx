"use client";

import { usePathname, useRouter } from "next/navigation";
import { useEffect, useRef } from "react";
import { useAppDispatch, useAppSelector } from "@/store/hooks";
import { DASHBOARD_PATH, openTab, setActiveTab } from "@/store/slices/tabsSlice";
import { findMenuByPath } from "@/utils/menu";

/**
 * Keeps the browser URL and the active workspace tab in step:
 * - URL → tab: deep links, reloads and back/forward open or focus the matching tab.
 * - tab → URL: switching tabs updates the address bar.
 */
export default function WorkspaceUrlSync() {
  const pathname = usePathname();
  const router = useRouter();
  const dispatch = useAppDispatch();
  const menus = useAppSelector((state) => state.auth.menus);
  const { tabs, activeTabId } = useAppSelector((state) => state.tabs);
  const activePath = tabs.find((tab) => tab.id === activeTabId)?.path ?? DASHBOARD_PATH;
  const lastSyncedActivePath = useRef<string | null>(null);

  // URL → tab
  useEffect(() => {
    if (pathname === activePath) return;

    const openAtPath = tabs.find((tab) => tab.path === pathname);
    if (openAtPath) {
      dispatch(setActiveTab(openAtPath.id));
      return;
    }

    const menu = findMenuByPath(menus, pathname);
    if (menu) {
      dispatch(openTab({ title: menu.title, path: menu.path, view: menu.viewType }));
      return;
    }

    // Unknown or unauthorised path: fall back to the active tab.
    router.replace(activePath);
    // Only react to URL changes; tab changes are handled below.
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [pathname, menus]);

  // tab → URL
  useEffect(() => {
    const previous = lastSyncedActivePath.current;
    lastSyncedActivePath.current = activePath;

    // Skip the first run so a deep link is not overwritten before it opens its tab.
    if (previous === null || previous === activePath) return;
    if (pathname !== activePath) router.push(activePath);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [activePath]);

  return null;
}
