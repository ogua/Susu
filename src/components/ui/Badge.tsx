import { StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Icon, type IconName } from '@/components/ui/Icon';
import { Radii } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { humanize } from '@/utils/format';

export type Tone = 'success' | 'danger' | 'warning' | 'info' | 'neutral';

/** Domain statuses → visual tone, shared across accounts/loans/withdrawals/sync. */
const STATUS_TONES: Record<string, Tone> = {
  active: 'success',
  applied: 'info',
  approved: 'info',
  disbursed: 'info',
  closed: 'neutral',
  completed: 'success',
  paid: 'success',
  synced: 'success',
  success: 'success',
  held: 'success',
  pending: 'warning',
  partially_paid: 'warning',
  open: 'warning',
  submitted: 'info',
  reconciled: 'success',
  collecting: 'info',
  dormant: 'warning',
  draft: 'neutral',
  left: 'neutral',
  rejected: 'danger',
  written_off: 'danger',
  cancelled: 'neutral',
  overdue: 'danger',
  failed: 'danger',
  flagged: 'danger',
  reversed: 'danger',
  abandoned: 'danger',
};

/** Every tone carries an icon so status never relies on colour alone. */
const TONE_ICONS: Record<Tone, IconName> = {
  success: 'checkCircle',
  danger: 'error',
  warning: 'pending',
  info: 'info',
  neutral: 'info',
};

export function toneFor(status: string): Tone {
  return STATUS_TONES[status.toLowerCase()] ?? 'neutral';
}

export function useToneColors(tone: Tone): { bg: string; fg: string } {
  const theme = useTheme();

  return {
    success: { bg: theme.successSoft, fg: theme.success },
    danger: { bg: theme.dangerSoft, fg: theme.danger },
    warning: { bg: theme.warningSoft, fg: theme.warning },
    info: { bg: theme.infoSoft, fg: theme.info },
    neutral: { bg: theme.neutralSoft, fg: theme.neutral },
  }[tone];
}

type BadgeProps = {
  /** Raw status ("written_off") or display text; humanized either way. */
  label: string;
  tone?: Tone;
  icon?: IconName | false;
};

export function Badge({ label, tone, icon }: BadgeProps) {
  const resolvedTone = tone ?? toneFor(label);
  const colors = useToneColors(resolvedTone);
  const iconName = icon === false ? null : (icon ?? TONE_ICONS[resolvedTone]);

  return (
    <View
      style={[styles.badge, { backgroundColor: colors.bg }]}
      accessible
      accessibilityLabel={`Status: ${humanize(label)}`}
    >
      {iconName ? <Icon name={iconName} size={12} color={colors.fg} /> : null}
      <ThemedText type="caption" style={[styles.text, { color: colors.fg }]}>
        {humanize(label)}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  badge: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 4,
    borderRadius: Radii.pill,
    paddingHorizontal: 8,
    paddingVertical: 3,
    alignSelf: 'flex-start',
  },
  text: { fontWeight: '700' },
});
