import { api, ensureCsrfCookie } from "@/api/client";
import { AUTH_URLS, AUTHORIZATION_URLS } from "@/api/urls";
import type { AuthUser, ChangePasswordPayload, LoginPayload, MenuItem, MfaChallenge, MfaSetup, MfaStatus } from "@/types/auth";
import type { ManagedUser, UserPayload, UsersList } from "@/types/users";

export const authApi = {
  /** Signed in, or a two-step code is needed first. */
  async login(payload: LoginPayload): Promise<AuthUser | MfaChallenge> {
    await ensureCsrfCookie();
    return api.post<AuthUser | MfaChallenge>(AUTH_URLS.login, payload);
  },
  verifyMfa: (code: string) => api.post<AuthUser>(AUTH_URLS.mfaVerify, { code }),
  /** First sign-in with two-step login: confirm the app's code; recovery codes come back once. */
  setupMfa: (code: string) => api.post<{ user: AuthUser; recoveryCodes: string[] }>(AUTH_URLS.mfaSetup, { code }),
  changePassword: (payload: ChangePasswordPayload) => api.post<AuthUser>(AUTH_URLS.password, payload),
  mfaStatus: () => api.get<MfaStatus>(AUTH_URLS.mfa),
  mfaStart: () => api.post<MfaSetup>(AUTH_URLS.mfaStart),
  mfaEnable: (code: string) => api.post<{ recoveryCodes: string[] }>(AUTH_URLS.mfaEnable, { code }),
  mfaDisable: (code: string) => api.post<null>(AUTH_URLS.mfaDisable, { code }),
  /** Till screen lock; the sale in progress stays on the device. */
  lockTill: (reason: "idle" | "manual") => api.post<null>(AUTH_URLS.tillLock, { reason }),
  unlockTill: (userId: number, pin: string) => api.post<AuthUser>(AUTH_URLS.tillUnlock, { userId, pin }),
  /** Till sign-in; the device token header is added by the API client. */
  async pinLogin(userId: number, pin: string): Promise<AuthUser> {
    await ensureCsrfCookie();
    return api.post<AuthUser>(AUTH_URLS.pinLogin, { userId, pin });
  },
  logout: () => api.post<null>(AUTH_URLS.logout),
  me: () => api.get<AuthUser>(AUTH_URLS.me),
};

export const authorizationApi = {
  menus: () => api.get<MenuItem[]>(AUTHORIZATION_URLS.menus),
};

export const usersApi = {
  list: () => api.get<UsersList>(AUTH_URLS.users),
  create: (payload: UserPayload) => api.post<ManagedUser>(AUTH_URLS.users, payload),
  update: (id: number, payload: UserPayload) => api.put<ManagedUser>(AUTH_URLS.user(id), payload),
  setPin: (id: number, pin: string, pinConfirmation: string) =>
    api.post<ManagedUser>(AUTH_URLS.userPin(id), { pin, pin_confirmation: pinConfirmation }),
  resetMfa: (id: number) => api.post<ManagedUser>(AUTH_URLS.userMfaReset(id)),
};
