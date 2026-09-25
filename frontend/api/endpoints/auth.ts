import { api, ensureCsrfCookie } from "@/api/client";
import { AUTH_URLS, AUTHORIZATION_URLS } from "@/api/urls";
import type { AuthUser, LoginPayload, MenuItem } from "@/types/auth";
import type { ManagedUser, UserPayload, UsersList } from "@/types/users";

export const authApi = {
  async login(payload: LoginPayload): Promise<AuthUser> {
    await ensureCsrfCookie();
    return api.post<AuthUser>(AUTH_URLS.login, payload);
  },
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
};
