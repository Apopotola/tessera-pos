/** Mirrors Modules\Auth\Http\Resources\ManagedUserResource. */
export interface ManagedUser {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  role: string | null;
  branchIds: number[];
  hasPin: boolean;
  isActive: boolean;
  lastLoginAt: string | null;
}

export interface UsersList {
  items: ManagedUser[];
  roles: string[];
}

export interface UserPayload {
  name: string;
  email: string;
  phone: string | null;
  role: string;
  branchIds: number[];
  /** Create only; omit for till-only staff who sign in with a PIN. */
  password?: string | null;
  isActive?: boolean;
}
