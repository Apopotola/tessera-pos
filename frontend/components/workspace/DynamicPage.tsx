"use client";

import { createElement } from "react";
import { getViewComponent } from "@/components/workspace/ViewRegistry";
import type { WorkspaceTab } from "@/store/slices/tabsSlice";

/** Resolves a tab's viewType to its registered screen. */
export default function DynamicPage({ tab }: { tab: WorkspaceTab }) {
  return createElement(getViewComponent(tab.view), {
    viewType: tab.view,
    title: tab.title,
    tabId: tab.id,
    props: tab.props,
  });
}
