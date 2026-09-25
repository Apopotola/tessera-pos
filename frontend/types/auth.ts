/**
 * Mirrors Modules\Auth\Http\Resources\AuthUserResource.
 * Permission names come from Modules\Authorization\Support\Permissions.
 */
export interface AuthUser {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  mustChangePassword: boolean;
  roles: string[];
  permissions: string[];
}

export interface LoginPayload {
  /** Email address or Kenyan phone number. */
  login: string;
  password: string;
  /** "Keep me signed in on this computer". */
  remember?: boolean;
}

/** Mirrors Modules\Authorization\Services\MenuService::treeFor(). */
export interface MenuItem {
  key: string;
  title: string;
  /** Tabler icon component name, e.g. "IconBottle". */
  icon: string | null;
  /** Key in components/workspace/ViewRegistry.tsx; null for groups. */
  viewType: string | null;
  path: string | null;
  children: MenuItem[];
}
