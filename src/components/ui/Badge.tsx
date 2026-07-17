import { StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Palette, Radii } from '@/constants/theme';

type Tone = 'success' | 'danger' | 'warning' | 'info' | 'neutral';

/** Domain statuses → visual tone, shared across accounts/loans/withdrawals/sync. */
const STATUS_TONES: Record<string, Tone> = {
  active: 'success',
  applied: 'info',
  approved: 'info',
  disbursed: 'warning',
  closed: 'neutral',
  completed: 'success',
  paid: 'success',
  synced: 'success',
  pending: 'warning',
  open: 'warning',
  submitted: 'info',
  reconciled: 'success',
  collecting: 'info',
  dormant: 'warning',
  draft: 'neutral',
  rejected: 'danger',
  written_off: 'danger',
  overdue: 'danger',
  failed: 'danger',
  reversed: 'danger',
};

const TONE_STYLES: Record<Tone, { bg: string; fg: string }> = {
  success: { bg: Palette.successSoft, fg: Palette.success },
  danger: { bg: Palette.dangerSoft, fg: Palette.danger },
  warning: { bg: Palette.warningSoft, fg: Palette.warning },
  info: { bg: Palette.primary100, fg: Palette.primary600 },
  neutral: { bg: Palette.neutralSoft, fg: Palette.neutral },
};

type BadgeProps = {
  label: string;
  tone?: Tone;
};

export function Badge({ label, tone }: BadgeProps) {
  const resolved = TONE_STYLES[tone ?? STATUS_TONES[label.toLowerCase()] ?? 'neutral'];

  return (
    <View style={[styles.badge, { backgroundColor: resolved.bg }]}>
      <ThemedText type="smallBold" style={{ color: resolved.fg }}>
        {label.replaceAll('_', ' ')}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  badge: {
    borderRadius: Radii.pill,
    paddingHorizontal: 10,
    paddingVertical: 3,
    alignSelf: 'flex-start',
  },
});
