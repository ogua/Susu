import * as SecureStore from "expo-secure-store";
import { create } from "zustand";

import type { User } from "@/types/api";

const TOKEN_KEY = "susu.auth.token";
const USER_KEY = "susu.auth.user";
const BASE_URL_KEY = "susu.api.baseUrl";

/** Default points at the local dev server; changeable on the login screen
 *  so the app can target cloud or on-prem/LAN backends at runtime. */
export const DEFAULT_BASE_URL = "http://172.20.10.3:7000";

interface AuthState {
  hydrated: boolean;
  token: string | null;
  user: User | null;
  baseUrl: string;
  hydrate: () => Promise<void>;
  setSession: (token: string, user: User) => Promise<void>;
  setUser: (user: User) => Promise<void>;
  setBaseUrl: (url: string) => Promise<void>;
  clearSession: () => Promise<void>;
}

export const useAuthStore = create<AuthState>((set) => ({
  hydrated: false,
  token: null,
  user: null,
  baseUrl: DEFAULT_BASE_URL,

  hydrate: async () => {
    const [token, rawUser, baseUrl] = await Promise.all([
      SecureStore.getItemAsync(TOKEN_KEY),
      SecureStore.getItemAsync(USER_KEY),
      SecureStore.getItemAsync(BASE_URL_KEY),
    ]);

    set({
      hydrated: true,
      token,
      user: rawUser ? (JSON.parse(rawUser) as User) : null,
      baseUrl: baseUrl ?? DEFAULT_BASE_URL,
    });
  },

  setSession: async (token, user) => {
    await Promise.all([
      SecureStore.setItemAsync(TOKEN_KEY, token),
      SecureStore.setItemAsync(USER_KEY, JSON.stringify(user)),
    ]);
    set({ token, user });
  },

  setUser: async (user) => {
    await SecureStore.setItemAsync(USER_KEY, JSON.stringify(user));
    set({ user });
  },

  setBaseUrl: async (url) => {
    const trimmed = url.replace(/\/+$/, "");
    await SecureStore.setItemAsync(BASE_URL_KEY, trimmed);
    set({ baseUrl: trimmed });
  },

  clearSession: async () => {
    await Promise.all([
      SecureStore.deleteItemAsync(TOKEN_KEY),
      SecureStore.deleteItemAsync(USER_KEY),
    ]);
    set({ token: null, user: null });
  },
}));
