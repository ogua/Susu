import { api } from '@/api/client';

export type AnnouncementLevel = 'info' | 'warning' | 'critical';

export interface Announcement {
  id: string;
  title: string;
  body: string;
  level: AnnouncementLevel;
  starts_at: string;
  ends_at: string | null;
}

/** Notices from the SusuApp team running now for the signed-in staff member. */
export async function getAnnouncements(): Promise<Announcement[]> {
  const { data } = await api.get<{ data: Announcement[] }>('/announcements');

  return data.data;
}
