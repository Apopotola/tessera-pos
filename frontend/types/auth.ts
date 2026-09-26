/**
 * Mirrors Modules\Auth\Http\Resources\AuthUserResource.
 * Permission names come from Modules\Authorization\Support\Permissions.
 */
export interface AuthUser {
  id: number;
  name: string;
  email: string;
  phone: string | null;
  /** Set by an admin, or the password is older than Settings → Staff → Password expiry. */
  mustChangePassword: boolean;
  mfaEnabled: boolean;
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

/** Secret and otpauth:// link for an authenticator app (shown as a QR code). */
export interface MfaSetup {
  secret: string;
  uri: string;
}

/** POST /auth/login when a two-step code is needed: "verify" (enrolled) or "setup" (first time). */
export interface MfaChallenge {
  mfaStep: "verify" | "setup";
  setup: MfaSetup | null;
}

export interface MfaStatus {
  enabled: boolean;
  /** Required for the user's role (Settings → Staff → Two-step login; always for Tessera support). */
  required: boolean;
  recoveryCodesLeft: number;
}

export interface ChangePasswordPayload {
  currentPassword: string;
  password: string;
  password_confirmation: string;
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
