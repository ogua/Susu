import * as Device from 'expo-device';

import { api } from '@/api/client';
import type { LoginResponse, User } from '@/types/api';

export async function login(loginId: string, password: string): Promise<LoginResponse> {
  const { data } = await api.post<LoginResponse>('/auth/login', {
    login: loginId,
    password,
    device_name: Device.deviceName ?? Device.modelName ?? 'mobile',
  });

  return data;
}

export async function logout(): Promise<void> {
  await api.post('/auth/logout');
}

export async function me(): Promise<User> {
  const { data } = await api.get<{ user: User }>('/auth/me');

  return data.user;
}
