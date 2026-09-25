"use client";

import { createElement, useMemo } from "react";
import { getViewComponent } from "@/components/workspace/ViewRegistry";
import { useAppSelector } from "@/store/hooks";
import type { WorkspaceTab } from "@/store/slices/tabsSlice";
import { sectionForPath } from "@/utils/menu";

/** Resolves a tab's viewType to its registered screen. */
export default function DynamicPage({ tab }: { tab: WorkspaceTab }) {
  const menus = useAppSelector((state) => state.auth.menus);
  const section = useMemo(() => sectionForPath(menus, tab.path), [menus, tab.path]);

  return createElement(getViewComponent(tab.view), {
    viewType: tab.view,
    title: tab.title,
    tabId: tab.id,
    section,
    props: tab.props,
  });
}
