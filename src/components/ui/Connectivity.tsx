import { router } from 'expo-router';
import { Pressable, StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Icon } from '@/components/ui/Icon';
import { Radii, Spacing } from '@/constants/theme';
import { useOnline } from '@/hooks/use-network';
import { useTheme } from '@/hooks/use-theme';
import { useOutboxStatus } from '@/stores/outboxStatusStore';
import { formatRelative } from '@/utils/format';

/**
 * Offline notice. `mode="agent"` reassures that collections still save on
 * the device; `mode="customer"` explains why actions are paused.
 */
export function OfflineBanner({ mode }: { mode: 'agent' | 'customer' }) {
  const online = useOnline();
  const theme = useTheme();

  if (online) {
    return null;
  }

  return (
    <View
      style={[styles.banner, { backgroundColor: theme.warningSoft }]}
      accessibilityRole="alert"
      accessibilityLiveRegion="polite"
    >
      <Icon name="offline" size={18} color={theme.warning} />
      <View style={styles.bannerText}>
        <ThemedText type="label" style={{ color: theme.warning }}>
          You&apos;re offline
        </ThemedText>
        <ThemedText type="small">
          {mode === 'agent'
            ? 'Cash collections, registrations and day close still save securely on this phone and sync when you reconnect.'
            : 'Showing your last saved figures. Payments and requests need a connection.'}
        </ThemedText>
      </View>
    </View>
  );
}

/**
 * Compact sync status: "All synced" / "3 waiting to sync" / "1 needs
 * attention". Tapping opens the Sync screen with details.
 */
export function SyncStatusPill() {
  const theme = useTheme();
  const online = useOnline();
  const pending = useOutboxStatus((state) => state.pending);
  const rejected = useOutboxStatus((state) => state.rejected);
  const syncing = useOutboxStatus((state) => state.syncing);
  const lastSyncedAt = useOutboxStatus((state) => state.lastSyncedAt);

  const state =
    rejected > 0
      ? {
          icon: 'warning' as const,
          fg: theme.danger,
          bg: theme.dangerSoft,
          text: `${rejected} need${rejected === 1 ? 's' : ''} attention`,
        }
      : syncing
        ? { icon: 'sync' as const, fg: theme.info, bg: theme.infoSoft, text: 'Syncing…' }
        : pending > 0
          ? {
              icon: online ? ('cloudUpload' as const) : ('offline' as const),
              fg: theme.warning,
              bg: theme.warningSoft,
              text: `${pending} saved on phone · waiting to sync`,
            }
          : {
              icon: 'cloudDone' as const,
              fg: theme.success,
              bg: theme.successSoft,
              text: lastSyncedAt ? `All synced · ${formatRelative(lastSyncedAt)}` : 'All records synced',
            };

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={`Sync status: ${state.text}. Open sync details.`}
      onPress={() => router.push('/(agent)/sync')}
      style={({ pressed }) => [styles.pill, { backgroundColor: state.bg }, pressed && { opacity: 0.8 }]}
    >
      <Icon name={state.icon} size={16} color={state.fg} />
      <ThemedText type="label" style={[styles.pillText, { color: state.fg }]} numberOfLines={1}>
        {state.text}
      </ThemedText>
      <Icon name="chevronRight" size={16} color={state.fg} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  banner: {
    flexDirection: 'row',
    gap: Spacing.two,
    padding: 12,
    borderRadius: Radii.md,
    alignItems: 'flex-start',
  },
  bannerText: { flex: 1, gap: 2 },
  pill: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 8,
    borderRadius: Radii.md,
    paddingHorizontal: 12,
    minHeight: 44,
  },
  pillText: { flex: 1 },
});
