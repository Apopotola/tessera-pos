import { productDetailTab } from "@/modules/catalogue/routes";
import type { OpenTabConfig } from "@/store/slices/tabsSlice";

/**
 * Record screens that are not menu items but must survive reload / deep links.
 * The API still authorises the record; a forbidden or missing id shows the view's error state.
 */
const DYNAMIC_ROUTES: { pattern: RegExp; toTab: (match: RegExpMatchArray) => OpenTabConfig }[] = [
  {
    pattern: /^\/catalogue\/products\/(\d+)$/,
    toTab: (m) => productDetailTab({ id: Number(m[1]), name: `Product #${m[1]}` }),
  },
];

export function matchDynamicRoute(path: string): OpenTabConfig | undefined {
  for (const route of DYNAMIC_ROUTES) {
    const match = path.match(route.pattern);
    if (match) return route.toTab(match);
  }
  return undefined;
}
