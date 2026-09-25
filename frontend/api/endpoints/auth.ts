import { api, ensureCsrfCookie } from "@/api/client";
import { AUTH_URLS, AUTHORIZATION_URLS } from "@/api/urls";
import type { AuthUser, LoginPayload, MenuItem } from "@/types/auth";

export const authApi = {
  async login(payload: LoginPayload): Promise<AuthUser> {
    await ensureCsrfCookie();
    return api.post<AuthUser>(AUTH_URLS.login, payload);
  },
  logout: () => api.post<null>(AUTH_URLS.logout),
  me: () => api.get<AuthUser>(AUTH_URLS.me),
};

export const authorizationApi = {
  menus: () => api.get<MenuItem[]>(AUTHORIZATION_URLS.menus),
};
