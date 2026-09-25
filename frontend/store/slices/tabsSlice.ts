import { createSlice, type PayloadAction } from "@reduxjs/toolkit";

/**
 * Tab-based workspace (same model as NAMRIMS). Every open tab stays mounted
 * and hidden tabs keep their state; `view` is a key in ViewRegistry.tsx.
 */
export interface WorkspaceTab {
  id: string;
  title: string;
  path: string;
  view: string;
  recordId?: string | number;
  parentTabId: string | null;
  isClosable: boolean;
  props?: Record<string, unknown>;
}

export interface OpenTabConfig {
  title: string;
  path: string;
  view: string;
  recordId?: string | number;
  parentTabId?: string | null;
  props?: Record<string, unknown>;
  isClosable?: boolean;
}

export interface TabsState {
  activeTabId: string;
  tabs: WorkspaceTab[];
}

export const MAX_TABS = 8;
export const DASHBOARD_TAB_ID = "dashboard";
export const DASHBOARD_PATH = "/dashboard";

function normalizePath(path: string): string {
  const trimmed = path.trim();
  if (!trimmed) return DASHBOARD_PATH;
  return trimmed.startsWith("/") ? trimmed : `/${trimmed}`;
}

/** Same view + record + parent = same tab, so re-opening focuses instead of duplicating. */
export function tabIdentity(config: Pick<OpenTabConfig, "path" | "recordId" | "parentTabId">): string {
  return [normalizePath(config.path), config.recordId ?? "", config.parentTabId ?? ""].join("|");
}

function dashboardTab(): WorkspaceTab {
  return {
    id: DASHBOARD_TAB_ID,
    title: "Dashboard",
    path: DASHBOARD_PATH,
    view: "dashboard",
    parentTabId: null,
    isClosable: false,
  };
}

function buildTab(config: OpenTabConfig): WorkspaceTab {
  const path = normalizePath(config.path);
  if (path === DASHBOARD_PATH) return dashboardTab();

  return {
    id: tabIdentity({ ...config, path }),
    title: config.title,
    path,
    view: config.view,
    recordId: config.recordId,
    parentTabId: config.parentTabId ?? null,
    isClosable: config.isClosable ?? true,
    props: config.props,
  };
}

/** Removes a tab and every descendant opened from it. */
function removeWithDescendants(state: TabsState, tabId: string): void {
  const doomed = new Set<string>([tabId]);
  let grew = true;
  while (grew) {
    grew = false;
    for (const tab of state.tabs) {
      if (tab.parentTabId && doomed.has(tab.parentTabId) && !doomed.has(tab.id)) {
        doomed.add(tab.id);
        grew = true;
      }
    }
  }
  state.tabs = state.tabs.filter((tab) => !doomed.has(tab.id));
}

function ensureActiveExists(state: TabsState, fallbackIndex = 0): void {
  if (state.tabs.some((tab) => tab.id === state.activeTabId)) return;
  const fallback = state.tabs[Math.max(0, Math.min(fallbackIndex, state.tabs.length - 1))] ?? dashboardTab();
  state.activeTabId = fallback.id;
}

const initialState: TabsState = {
  activeTabId: DASHBOARD_TAB_ID,
  tabs: [dashboardTab()],
};

const tabsSlice = createSlice({
  name: "tabs",
  initialState,
  reducers: {
    openTab(state, action: PayloadAction<OpenTabConfig>) {
      const tab = buildTab(action.payload);
      const existing = state.tabs.find((t) => t.id === tab.id);

      if (existing) {
        existing.title = tab.title;
        existing.props = tab.props ?? existing.props;
        state.activeTabId = existing.id;
        return;
      }

      if (state.tabs.length >= MAX_TABS) {
        // Evict the oldest closable tab that is not active.
        const evictable = state.tabs.find((t) => t.isClosable && t.id !== state.activeTabId);
        if (!evictable) return;
        removeWithDescendants(state, evictable.id);
      }

      const parentIndex = tab.parentTabId ? state.tabs.findIndex((t) => t.id === tab.parentTabId) : -1;
      if (parentIndex >= 0) {
        state.tabs.splice(parentIndex + 1, 0, tab);
      } else {
        state.tabs.push(tab);
      }
      state.activeTabId = tab.id;
    },

    setActiveTab(state, action: PayloadAction<string>) {
      if (state.tabs.some((t) => t.id === action.payload)) {
        state.activeTabId = action.payload;
      }
    },

    updateTab(state, action: PayloadAction<{ tabId: string; title?: string; props?: Record<string, unknown> }>) {
      const tab = state.tabs.find((t) => t.id === action.payload.tabId);
      if (!tab) return;
      if (action.payload.title !== undefined) tab.title = action.payload.title;
      if (action.payload.props !== undefined) tab.props = action.payload.props;
    },

    closeTab(state, action: PayloadAction<string>) {
      const index = state.tabs.findIndex((t) => t.id === action.payload);
      const tab = state.tabs[index];
      if (!tab?.isClosable) return;

      removeWithDescendants(state, tab.id);
      ensureActiveExists(state, index - 1);
    },

    closeOtherTabs(state, action: PayloadAction<string>) {
      state.tabs = state.tabs.filter((t) => !t.isClosable || t.id === action.payload);
      state.activeTabId = action.payload;
      ensureActiveExists(state);
    },

    closeTabsToRight(state, action: PayloadAction<string>) {
      const index = state.tabs.findIndex((t) => t.id === action.payload);
      if (index < 0) return;
      state.tabs = state.tabs.filter((t, i) => i <= index || !t.isClosable);
      ensureActiveExists(state, index);
    },

    resetTabs() {
      return initialState;
    },
  },
});

export const { openTab, setActiveTab, updateTab, closeTab, closeOtherTabs, closeTabsToRight, resetTabs } = tabsSlice.actions;
export default tabsSlice.reducer;
