import { useCallback } from "react";
import { useAppSelector } from "@/store/hooks";

/**
 * UI-level permission checks. The backend re-checks every request;
 * this only hides actions the user cannot perform.
 */
export function usePermissions() {
  const permissions = useAppSelector((state) => state.auth.user?.permissions);

  const can = useCallback((permission: string) => permissions?.includes(permission) ?? false, [permissions]);

  return { can };
}
