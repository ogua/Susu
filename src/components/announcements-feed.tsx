import { useQuery } from '@tanstack/react-query';
import { View, StyleSheet } from 'react-native';

import { getAnnouncements, type AnnouncementLevel } from '@/api/announcements';
import { Notice } from '@/components/ui';
import { Spacing } from '@/constants/theme';

const TONE: Record<AnnouncementLevel, 'info' | 'warning' | 'danger'> = {
  info: 'info',
  warning: 'warning',
  critical: 'danger',
};

/**
 * Platform announcements (maintenance windows, new features) on the staff
 * home screen. Online-only and silent on failure: an offline agent simply
 * sees none until the next refresh.
 */
export function AnnouncementsFeed() {
  const { data } = useQuery({
    queryKey: ['announcements'],
    queryFn: getAnnouncements,
    staleTime: 5 * 60_000,
    retry: false,
  });

  if (!data?.length) {
    return null;
  }

  return (
    <View style={styles.list}>
      {data.map((announcement) => (
        <Notice
          key={announcement.id}
          tone={TONE[announcement.level] ?? 'info'}
          title={announcement.title}
          message={announcement.body}
        />
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  list: { gap: Spacing.two },
});
