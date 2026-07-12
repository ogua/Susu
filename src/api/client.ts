import axios, { AxiosError } from 'axios';

import { useAuthStore } from '@/stores/authStore';

/**
 * Shared axios instance. The base URL and bearer token are resolved per
 * request from the auth store so they can change at runtime (cloud vs
 * on-prem/LAN backends, login/logout) without rebuilding the client.
 */
export const api = axios.create({
  timeout: 20_000,
  headers: { Accept: 'application/json' },
});

api.interceptors.request.use((config) => {
  const { baseUrl, token } = useAuthStore.getState();

  config.baseURL = `${baseUrl}/api/v1`;
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }

  return config;
});

api.interceptors.response.use(
  (response) => response,
  async (error: AxiosError) => {
    if (error.response?.status === 401 && useAuthStore.getState().token) {
      // Token revoked or expired server-side: drop the local session.
      await useAuthStore.getState().clearSession();
    }

    return Promise.reject(error);
  },
);

/** Extract a human-readable message from an API error. */
export function apiErrorMessage(error: unknown): string {
  if (axios.isAxiosError(error)) {
    const data = error.response?.data as
      | { message?: string; errors?: Record<string, string[]> }
      | undefined;

    if (data?.errors) {
      const first = Object.values(data.errors)[0];
      if (first?.length) return first[0];
    }
    if (data?.message) return data.message;
    if (error.code === 'ECONNABORTED') return 'The server took too long to respond.';
    if (!error.response) return 'Could not reach the server. Check the server address and your connection.';
  }

  return 'Something went wrong. Please try again.';
}
