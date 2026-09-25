import type { MenuItem } from "@/types/auth";

/** Menu leaves that open a workspace view (have both a viewType and a path). */
export interface NavigableMenuItem extends MenuItem {
  viewType: string;
  path: string;
}

export function flattenMenus(items: MenuItem[]): NavigableMenuItem[] {
  return items.flatMap((item) => {
    const self = item.viewType && item.path ? [item as NavigableMenuItem] : [];
    return [...self, ...flattenMenus(item.children)];
  });
}

export function findMenuByPath(items: MenuItem[], path: string): NavigableMenuItem | undefined {
  return flattenMenus(items).find((item) => item.path === path);
}

/** Only allow same-app relative redirects (blocks //evil.com and absolute URLs). */
export function safeRedirectPath(value: string | null | undefined, fallback = "/dashboard"): string {
  if (!value || !value.startsWith("/") || value.startsWith("//")) return fallback;
  return value;
}
