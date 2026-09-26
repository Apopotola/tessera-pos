/** Mirrors Modules\Settings (SettingsSchema / SettingsService). */
export type SettingLevel = "T" | "O" | "B";
export type SettingScope = "business" | "branch" | "till";
export type SettingType =
  | "text"
  | "lines"
  | "boolean"
  | "select"
  | "multiselect"
  | "order"
  | "number"
  | "money"
  | "color"
  | "image"
  | "time"
  | "secret"
  | "categories"
  | "items"
  | "role_percent"
  | "role_tiles";

// Values are JSON: strings, numbers, booleans, lists and maps.
export type SettingValue = string | number | boolean | null | string[] | number[] | Record<string, number> | Record<string, string[]>;

export interface SettingField {
  key: string;
  label: string;
  type: SettingType;
  group: string | null;
  help: string | null;
  options: { value: string; label: string }[];
  default: SettingValue;
  level: SettingLevel;
  scopes: SettingScope[];
  /** false = stored, but the feature it drives is not built yet (see note). */
  available: boolean;
  note: string | null;
  min: number | null;
  max: number | null;
  /** Value set exactly at this scope (null = inherited). Images: URL; secrets: masked. */
  value: SettingValue;
  storedPath: string | null;
  isSet: boolean;
  /** What applies here, and where it comes from. */
  effective: SettingValue;
  source: "default" | SettingScope;
  editable: boolean;
  lockedReason: string | null;
  hasHistory: boolean;
  modelBacked: boolean;
}

export interface SettingsSchema {
  level: SettingLevel;
  scope: SettingScope;
  scopeId: number;
  canEditBusiness: boolean;
  branches: { id: number; code: string; name: string }[];
  tills: { id: number; branchId: number; name: string }[];
  sections: { key: string; title: string; description: string; fields: SettingField[] }[];
  presets: { key: string; title: string; description: string }[];
  currentPreset: string;
  locked: { item: string; why: string; correction: string }[];
  salesStarted: boolean;
  references: {
    categories: { id: number; name: string; parentId: number | null }[];
    roles: string[];
    dashboardTiles: Record<string, string>;
    items: { id: number; label: string }[];
  };
}

export interface SettingChange {
  id: number;
  oldValue: SettingValue;
  newValue: SettingValue;
  source: "edit" | "reset" | "undo" | "preset";
  user: string | null;
  at: string;
}

export interface PresetChange {
  key: string;
  label: string;
  from: SettingValue;
  to: SettingValue;
  yourChange: boolean;
}

/** GET /settings/public — login page branding. */
export interface PublicBranding {
  displayName: string;
  appLogo: string | null;
  favicon: string | null;
  primaryColor: string;
  accentColor: string;
  loginStyle: "split" | "centered";
  loginBackground: "bottles" | "mosaic" | "image";
  loginBackgroundImage: string | null;
  welcomeText: string;
  poweredBy: { text: string; support: string };
}

/** GET /settings/app and /settings/till — effective values for this user / till (no secrets). */
export interface AppSettings {
  values: Record<string, SettingValue>;
  businessName: string;
  myDiscountLimit: number;
  dashboardTiles: string[] | null;
  poweredBy: { text: string; support: string };
}
