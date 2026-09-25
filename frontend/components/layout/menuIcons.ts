import {
  IconBottle,
  IconBuildingWarehouse,
  IconCashRegister,
  IconCircleDot,
  IconLayoutDashboard,
  IconReceipt,
  IconReportAnalytics,
  IconSettings,
  IconShieldCheck,
  IconTruckDelivery,
  IconUsers,
  type Icon,
} from "@tabler/icons-react";

/**
 * Icon names sent by the backend menu seeder → Tabler components.
 * Explicit map keeps the bundle tree-shaken; unknown names fall back to a dot.
 */
const MENU_ICONS: Record<string, Icon> = {
  IconBottle,
  IconBuildingWarehouse,
  IconCashRegister,
  IconLayoutDashboard,
  IconReceipt,
  IconReportAnalytics,
  IconSettings,
  IconShieldCheck,
  IconTruckDelivery,
  IconUsers,
};

export function menuIcon(name: string | null): Icon {
  return (name && MENU_ICONS[name]) || IconCircleDot;
}
