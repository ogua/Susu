import { ActivityIndicator, StyleSheet, View } from 'react-native';
import Animated, { FadeIn } from 'react-native-reanimated';

import { ThemedText } from '@/components/themed-text';
import { Button } from '@/components/ui/Button';
import { Icon, type IconName } from '@/components/ui/Icon';
import { Radii, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type EmptyStateProps = {
  title: string;
  /** Why it's empty / what to do next. */
  hint?: string;
  icon?: IconName;
  actionLabel?: string;
  onAction?: () => void;
};

/** Explains what is empty, why, and offers the next step. */
export function EmptyState({ title, hint, icon = 'info', actionLabel, onAction }: EmptyStateProps) {
  const theme = useTheme();

  return (
    <Animated.View entering={FadeIn.duration(200)} style={styles.wrapper}>
      <View style={[styles.iconCircle, { backgroundColor: theme.surfaceMuted }]}>
        <Icon name={icon} size={28} color={theme.textMuted} />
      </View>
      <ThemedText type="bodyStrong" style={styles.center}>
        {title}
      </ThemedText>
      {hint ? (
        <ThemedText type="small" themeColor="textSecondary" style={[styles.center, styles.hint]}>
          {hint}
        </ThemedText>
      ) : null}
      {actionLabel && onAction ? (
        <Button title={actionLabel} variant="secondary" onPress={onAction} style={styles.action} />
      ) : null}
    </Animated.View>
  );
}

/** Load failure with a retry — never shows the raw technical error. */
export function ErrorState({
  title = "Couldn't load this",
  hint = 'Check your connection, then try again.',
  onRetry,
}: {
  title?: string;
  hint?: string;
  onRetry?: () => void;
}) {
  const theme = useTheme();

  return (
    <View style={styles.wrapper}>
      <View style={[styles.iconCircle, { backgroundColor: theme.dangerSoft }]}>
        <Icon name="offline" size={28} color={theme.danger} />
      </View>
      <ThemedText type="bodyStrong" style={styles.center}>
        {title}
      </ThemedText>
      <ThemedText type="small" themeColor="textSecondary" style={[styles.center, styles.hint]}>
        {hint}
      </ThemedText>
      {onRetry ? <Button title="Try again" variant="outline" icon="sync" onPress={onRetry} style={styles.action} /> : null}
    </View>
  );
}

export function LoadingState({ label = 'Loading…' }: { label?: string }) {
  const theme = useTheme();

  return (
    <View style={styles.wrapper} accessibilityRole="progressbar" accessibilityLabel={label}>
      <ActivityIndicator color={theme.primary} />
      <ThemedText type="small" themeColor="textMuted">
        {label}
      </ThemedText>
    </View>
  );
}

type NoticeTone = 'info' | 'success' | 'warning' | 'danger';

/** Inline banner for contextual information inside a screen. */
export function Notice({
  tone = 'info',
  title,
  message,
  icon,
}: {
  tone?: NoticeTone;
  title?: string;
  message: string;
  icon?: IconName;
}) {
  const theme = useTheme();
  const colors = {
    info: { bg: theme.infoSoft, fg: theme.info, icon: 'info' as IconName },
    success: { bg: theme.successSoft, fg: theme.success, icon: 'checkCircle' as IconName },
    warning: { bg: theme.warningSoft, fg: theme.warning, icon: 'warning' as IconName },
    danger: { bg: theme.dangerSoft, fg: theme.danger, icon: 'error' as IconName },
  }[tone];

  return (
    <View
      style={[styles.notice, { backgroundColor: colors.bg }]}
      accessibilityRole={tone === 'danger' ? 'alert' : undefined}
      accessibilityLiveRegion="polite"
    >
      <Icon name={icon ?? colors.icon} size={18} color={colors.fg} />
      <View style={styles.noticeText}>
        {title ? (
          <ThemedText type="label" style={{ color: colors.fg }}>
            {title}
          </ThemedText>
        ) : null}
        <ThemedText type="small" themeColor="text">
          {message}
        </ThemedText>
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  wrapper: { alignItems: 'center', paddingVertical: Spacing.five, paddingHorizontal: Spacing.four, gap: Spacing.two },
  iconCircle: {
    width: 56,
    height: 56,
    borderRadius: Radii.pill,
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: Spacing.one,
  },
  center: { textAlign: 'center' },
  hint: { maxWidth: 320 },
  action: { marginTop: Spacing.two, alignSelf: 'center' },
  notice: {
    flexDirection: 'row',
    gap: Spacing.two,
    borderRadius: Radii.md,
    padding: 12,
    alignItems: 'flex-start',
  },
  noticeText: { flex: 1, gap: 2 },
});
