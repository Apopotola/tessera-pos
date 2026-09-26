import { api, uploadFile } from "@/api/client";
import { SETTINGS_URLS as U } from "@/api/urls";
import type { AppSettings, PresetChange, PublicBranding, SettingChange, SettingField, SettingScope, SettingsSchema, SettingValue } from "@/types/settings";

export const settingsApi = {
  publicBranding: () => api.get<PublicBranding>(U.public),
  app: () => api.get<AppSettings>(U.app),
  till: () => api.get<AppSettings>(U.till),

  schema: (scope: SettingScope, scopeId: number) => api.get<SettingsSchema>(U.schema, { scope, scopeId }),
  save: (key: string, scope: SettingScope, scopeId: number, value: SettingValue) => api.put<SettingField>(U.value(key), { scope, scopeId, value }),
  reset: (key: string, scope: SettingScope, scopeId: number) => api.delete<SettingField>(`${U.value(key)}?scope=${scope}&scopeId=${scopeId}`),
  undo: (key: string, scope: SettingScope, scopeId: number) => api.post<SettingField>(U.undo(key), { scope, scopeId }),
  history: (key: string, scope: SettingScope, scopeId: number) => api.get<SettingChange[]>(U.history(key), { scope, scopeId }),

  presetPreview: (preset: string) => api.get<PresetChange[]>(U.presetPreview(preset)),
  applyPreset: (preset: string, overwriteYourChanges: boolean) => api.post<{ written: number }>(U.presetApply(preset), { overwriteYourChanges }),

  /** Branding image; save the returned path into the setting. */
  upload: (file: File) => uploadFile<{ path: string; url: string }>(U.uploads, file),
};
