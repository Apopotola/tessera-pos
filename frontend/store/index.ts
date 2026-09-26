import { combineReducers, configureStore } from "@reduxjs/toolkit";
import authReducer from "@/store/slices/authSlice";
import settingsReducer from "@/store/slices/settingsSlice";
import tabsReducer from "@/store/slices/tabsSlice";

const rootReducer = combineReducers({
  auth: authReducer,
  tabs: tabsReducer,
  settings: settingsReducer,
});

export function makeStore() {
  return configureStore({ reducer: rootReducer });
}

export type AppStore = ReturnType<typeof makeStore>;
export type RootState = ReturnType<typeof rootReducer>;
export type AppDispatch = AppStore["dispatch"];
