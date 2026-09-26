import { createAsyncThunk, createSlice } from "@reduxjs/toolkit";
import { ApiError, authApi, authorizationApi } from "@/api";
import type { AuthUser, LoginPayload, MenuItem, MfaChallenge } from "@/types/auth";

export type AuthStatus = "idle" | "loading" | "authenticated" | "unauthenticated";

export interface AuthState {
  status: AuthStatus;
  user: AuthUser | null;
  menus: MenuItem[];
  loginError: string | null;
  loginFieldErrors: Record<string, string>;
  /** The password was right; a two-step code is needed before the session starts. */
  mfa: MfaChallenge | null;
  /** Shown once after two-step login is set up at sign-in, before going on. */
  recoveryCodes: string[] | null;
}

const initialState: AuthState = {
  status: "idle",
  user: null,
  menus: [],
  loginError: null,
  loginFieldErrors: {},
  mfa: null,
  recoveryCodes: null,
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

const rejection = (error: unknown): LoginRejection =>
  error instanceof ApiError ? { message: error.message, fieldErrors: error.formErrors } : { message: "Unable to sign in. Try again.", fieldErrors: {} };

export const login = createAsyncThunk<SessionPayload | { mfa: MfaChallenge }, LoginPayload, { rejectValue: LoginRejection }>(
  "auth/login",
  async (payload, { rejectWithValue }) => {
    try {
      const result = await authApi.login(payload);
      if ("mfaStep" in result) return { mfa: result };
      const menus = await authorizationApi.menus();
      return { user: result, menus };
    } catch (error) {
      return rejectWithValue(rejection(error));
    }
  },
);

/** Second step of sign-in: a code from the authenticator app or a recovery code. */
export const verifyMfa = createAsyncThunk<SessionPayload, string, { rejectValue: LoginRejection }>("auth/verifyMfa", async (code, { rejectWithValue }) => {
  try {
    const user = await authApi.verifyMfa(code);
    return { user, menus: await authorizationApi.menus() };
  } catch (error) {
    return rejectWithValue(rejection(error));
  }
});

/** First sign-in with two-step login required: confirm the app's code; recovery codes are shown next. */
export const setupMfa = createAsyncThunk<SessionPayload & { recoveryCodes: string[] }, string, { rejectValue: LoginRejection }>(
  "auth/setupMfa",
  async (code, { rejectWithValue }) => {
    try {
      const { user, recoveryCodes } = await authApi.setupMfa(code);
      return { user, recoveryCodes, menus: await authorizationApi.menus() };
    } catch (error) {
      return rejectWithValue(rejection(error));
    }
  },
);

/** Till sign-in. Rejects with the server message (e.g. "Wrong PIN. Try again."). */
export const pinLogin = createAsyncThunk<SessionPayload, { userId: number; pin: string }, { rejectValue: string }>(
  "auth/pinLogin",
  async ({ userId, pin }, { rejectWithValue }) => {
    try {
      const user = await authApi.pinLogin(userId, pin);
      const menus = await authorizationApi.menus();
      return { user, menus };
    } catch (error) {
      return rejectWithValue(error instanceof ApiError ? error.message : "Unable to sign in. Try again.");
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
    /**
     * Offline till: the server cannot be reached, so resume the cashier saved on this device.
     * Their session cookie is still used for anything sent once the connection is back.
     */
    restoreOfflineSession(state, { payload }: { payload: AuthUser }) {
      state.status = "authenticated";
      state.user = payload;
    },
    sessionExpired(state) {
      state.status = "unauthenticated";
      state.user = null;
      state.menus = [];
    },
    /** Back to the password step (e.g. the code timed out). */
    cancelMfa(state) {
      state.mfa = null;
      state.loginError = null;
      state.loginFieldErrors = {};
    },
    recoveryCodesSeen(state) {
      state.recoveryCodes = null;
    },
    /** After a password change or two-step change. */
    userUpdated(state, { payload }: { payload: AuthUser }) {
      state.user = payload;
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
        if ("mfa" in payload) {
          state.mfa = payload.mfa;
          return;
        }
        state.status = "authenticated";
        state.user = payload.user;
        state.menus = payload.menus;
      })
      .addCase(login.rejected, (state, { payload }) => {
        state.loginError = payload?.message ?? "Unable to sign in. Try again.";
        state.loginFieldErrors = payload?.fieldErrors ?? {};
      })
      .addCase(verifyMfa.fulfilled, (state, { payload }) => {
        state.mfa = null;
        state.status = "authenticated";
        state.user = payload.user;
        state.menus = payload.menus;
      })
      .addCase(setupMfa.fulfilled, (state, { payload }) => {
        state.mfa = null;
        state.recoveryCodes = payload.recoveryCodes;
        state.status = "authenticated";
        state.user = payload.user;
        state.menus = payload.menus;
      })
      .addCase(pinLogin.fulfilled, (state, { payload }) => {
        state.status = "authenticated";
        state.user = payload.user;
        state.menus = payload.menus;
      })
      .addCase(logout.fulfilled, (state) => {
        state.status = "unauthenticated";
        state.user = null;
        state.menus = [];
        state.mfa = null;
      });
  },
});

export const { restoreOfflineSession, sessionExpired, cancelMfa, recoveryCodesSeen, userUpdated } = authSlice.actions;
export default authSlice.reducer;
