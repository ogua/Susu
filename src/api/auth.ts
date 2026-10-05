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

export interface PickedPhoto {
  uri: string;
  fileName?: string | null;
  mimeType?: string | null;
}

/** Uploads the signed-in user's profile photo (shown on the branch tracking map). */
export async function uploadProfilePhoto(photo: PickedPhoto): Promise<User> {
  const mimeType = photo.mimeType ?? 'image/jpeg';
  const form = new FormData();
  form.append('photo', {
    uri: photo.uri,
    name: photo.fileName ?? `profile.${mimeType.split('/')[1] ?? 'jpg'}`,
    type: mimeType,
  } as unknown as Blob);

  const { data } = await api.post<{ user: User }>('/auth/me/photo', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
    timeout: 60_000,
  });

  return data.user;
}

export async function removeProfilePhoto(): Promise<User> {
  const { data } = await api.delete<{ user: User }>('/auth/me/photo');

  return data.user;
}

export async function me(): Promise<User> {
  const { data } = await api.get<{ user: User }>('/auth/me');

  return data.user;
}
