import { AxiosError, create, isAxiosError, type InternalAxiosRequestConfig } from 'axios';
import Constants from 'expo-constants';
import { router } from 'expo-router';

import { useAuthStore } from '@/stores/authStore';
import { debugLog, errorLog } from '@/utils/debugLog';

function requestLabel(config: InternalAxiosRequestConfig): string {
  return `${(config.method ?? 'get').toUpperCase()} ${config.baseURL ?? ''}${config.url ?? ''}`;
}

/**
 * Shared axios instance. The base URL and bearer token are resolved per
 * request from the auth store so they can change at runtime (cloud vs
 * on-prem/LAN backends, login/logout) without rebuilding the client.
 */
/** Lets the server show which app version each signed-in device runs. */
const APP_VERSION = Constants.expoConfig?.version ?? 'unknown';

export const api = create({
  timeout: 20_000,
  headers: {
    Accept: 'application/json',
    'X-Client-Platform': 'mobile',
    'X-App-Version': APP_VERSION,
  },
});

api.interceptors.request.use((config) => {
  const { baseUrl, token } = useAuthStore.getState();

  config.baseURL = `${baseUrl}/api/v1`;
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  debugLog('API', `→ ${requestLabel(config)}${token ? '' : ' (no token)'}`, config.params ?? config.data);

  return config;
});

api.interceptors.response.use(
  (response) => {
    debugLog('API', `← ${response.status} ${requestLabel(response.config)}`, response.data);

    return response;
  },
  async (error: AxiosError) => {
    errorLog(
      'API',
      `✗ ${error.response?.status ?? error.code ?? 'NETWORK'} ${error.config ? requestLabel(error.config) : ''} — ${error.message}`,
      error.response?.data,
    );

    if (error.response?.status === 401 && useAuthStore.getState().token) {
      // Token revoked or expired server-side: drop the local session.
      await useAuthStore.getState().clearSession();
    }

    const code = (error.response?.data as { code?: string } | undefined)?.code;
    const { user } = useAuthStore.getState();
    if (error.response?.status === 403 && code === 'password_change_required' && user) {
      // An admin reset the password while this session was open: the server
      // answers nothing else until a new one is chosen.
      await useAuthStore.getState().setUser({ ...user, must_change_password: true });
      router.replace('/(auth)/change-password');
    }

    return Promise.reject(error);
  },
);

/**
 * Turn an API error into a message safe to show a customer or agent.
 * 4xx validation/business messages are written for users by the backend and
 * are passed through; 5xx bodies, transport errors and stack traces never are.
 */
export function apiErrorMessage(error: unknown): string {
  if (isAxiosError(error)) {
    const status = error.response?.status;
    const data = error.response?.data as
      | { message?: string; errors?: Record<string, string[]> }
      | undefined;

    if (!error.response) {
      if (error.code === 'ECONNABORTED') {
        return 'The server took too long to respond. Check whether it went through before trying again.';
      }

      return 'No connection to the server. Check your internet or the server address and try again.';
    }

    if (status === 401) return 'Your session has ended. Please sign in again.';
    if (status === 403) return "You don't have permission to do this.";
    if (status === 404) return "We couldn't find that record. It may have been removed.";
    if (status === 429) return 'Too many attempts. Please wait a moment and try again.';
    if (status !== undefined && status >= 500) {
      return "The server couldn't complete this request. Please try again shortly.";
    }

    if (data?.errors) {
      const first = Object.values(data.errors)[0];
      if (first?.length) return first[0];
    }
    if (data?.message && data.message.length < 200) return data.message;
  }

  return 'Something went wrong. Please try again.';
}

/** First validation message for a given field, for inline field errors. */
export function apiFieldError(error: unknown, field: string): string | null {
  if (isAxiosError(error) && error.response?.status === 422) {
    const data = error.response.data as { errors?: Record<string, string[]> } | undefined;

    return data?.errors?.[field]?.[0] ?? null;
  }

  return null;
}
