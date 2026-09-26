import { createAsyncThunk, createSlice } from "@reduxjs/toolkit";
import { settingsApi } from "@/api";
import type { AppSettings, PublicBranding, SettingValue } from "@/types/settings";

export interface SettingsState {
  /** Login-page branding (public, loaded on every page). */
  branding: PublicBranding | null;
  /** Effective settings for the signed-in back-office user. */
  app: AppSettings | null;
}

const initialState: SettingsState = { branding: null, app: null };

export const loadBranding = createAsyncThunk("settings/branding", () => settingsApi.publicBranding());
export const loadAppSettings = createAsyncThunk("settings/app", () => settingsApi.app());

const settingsSlice = createSlice({
  name: "settings",
  initialState,
  reducers: {
    clearAppSettings(state) {
      state.app = null;
    },
  },
  extraReducers: (builder) => {
    builder
      .addCase(loadBranding.fulfilled, (state, { payload }) => {
        state.branding = payload;
      })
      .addCase(loadAppSettings.fulfilled, (state, { payload }) => {
        state.app = payload;
      });
  },
});

export const { clearAppSettings } = settingsSlice.actions;
export default settingsSlice.reducer;

/** Read one effective setting with a fallback (settings may not have loaded yet). */
export function settingValue<T extends SettingValue>(state: { settings: SettingsState }, key: string, fallback: T): T {
  const value = state.settings.app?.values[key];
  return (value === undefined || value === null ? fallback : value) as T;
}
