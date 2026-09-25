import { createAsyncThunk, createSlice } from "@reduxjs/toolkit";
import { ApiError, authApi, authorizationApi } from "@/api";
import type { AuthUser, LoginPayload, MenuItem } from "@/types/auth";

export type AuthStatus = "idle" | "loading" | "authenticated" | "unauthenticated";

export interface AuthState {
  status: AuthStatus;
  user: AuthUser | null;
  menus: MenuItem[];
  loginError: string | null;
  loginFieldErrors: Record<string, string>;
}

const initialState: AuthState = {
  status: "idle",
  user: null,
  menus: [],
  loginError: null,
  loginFieldErrors: {},
};

interface SessionPayload {
  user: AuthUser;
  menus: MenuItem[];
}

interface LoginRejection {
  message: string;
  fieldErrors: Record<string, string>;
}

/** Restores an existing cookie session on page load. Resolves to null when signed out. */
export const bootstrapSession = createAsyncThunk<SessionPayload | null>("auth/bootstrap", async () => {
  try {
    const user = await authApi.me();
    const menus = await authorizationApi.menus();
    return { user, menus };
  } catch (error) {
    if (error instanceof ApiError && error.status === 401) return null;
    throw error;
  }
});

export const login = createAsyncThunk<SessionPayload, LoginPayload, { rejectValue: LoginRejection }>(
  "auth/login",
  async (payload, { rejectWithValue }) => {
    try {
      const user = await authApi.login(payload);
      const menus = await authorizationApi.menus();
      return { user, menus };
    } catch (error) {
      if (error instanceof ApiError) {
        return rejectWithValue({ message: error.message, fieldErrors: error.formErrors });
      }
      return rejectWithValue({ message: "Unable to sign in. Try again.", fieldErrors: {} });
    }
  },
);

export const logout = createAsyncThunk("auth/logout", async () => {
  try {
    await authApi.logout();
  } catch {
    // The local session is cleared regardless; the server session may already be gone.
  }
});

const authSlice = createSlice({
  name: "auth",
  initialState,
  reducers: {
    sessionExpired(state) {
      state.status = "unauthenticated";
      state.user = null;
      state.menus = [];
    },
  },
  extraReducers: (builder) => {
    builder
      .addCase(bootstrapSession.pending, (state) => {
        state.status = "loading";
      })
      .addCase(bootstrapSession.fulfilled, (state, { payload }) => {
        state.status = payload ? "authenticated" : "unauthenticated";
        state.user = payload?.user ?? null;
        state.menus = payload?.menus ?? [];
      })
      .addCase(bootstrapSession.rejected, (state) => {
        state.status = "unauthenticated";
      })
      .addCase(login.pending, (state) => {
        state.loginError = null;
        state.loginFieldErrors = {};
      })
      .addCase(login.fulfilled, (state, { payload }) => {
        state.status = "authenticated";
        state.user = payload.user;
        state.menus = payload.menus;
      })
      .addCase(login.rejected, (state, { payload }) => {
        state.loginError = payload?.message ?? "Unable to sign in. Try again.";
        state.loginFieldErrors = payload?.fieldErrors ?? {};
      })
      .addCase(logout.fulfilled, (state) => {
        state.status = "unauthenticated";
        state.user = null;
        state.menus = [];
      });
  },
});

export const { sessionExpired } = authSlice.actions;
export default authSlice.reducer;
