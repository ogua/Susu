import { StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Badge } from '@/components/ui';
import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import type { InstallmentStatus } from '@/types/api';
import { formatDate } from '@/utils/format';
import { displayFormatted } from '@/utils/money';

/** One repayment-schedule line, shared by individual and group loans. */
export function InstallmentRow({
  sequence,
  dueDate,
  status,
  amountDueFormatted,
  remainingFormatted,
  partiallyPaid,
}: {
  sequence: number;
  dueDate: string;
  status: InstallmentStatus;
  amountDueFormatted: string;
  remainingFormatted?: string | null;
  partiallyPaid: boolean;
}) {
  const theme = useTheme();

  return (
    <View
      style={[styles.row, { backgroundColor: theme.surface, borderBottomColor: theme.border }]}
      accessible
      accessibilityLabel={`Instalment ${sequence}, due ${formatDate(dueDate)}, ${displayFormatted(amountDueFormatted)}, ${status.replaceAll('_', ' ')}`}
    >
      <View style={[styles.seq, { backgroundColor: theme.surfaceMuted }]}>
        <ThemedText type="label" themeColor="textSecondary">
          {sequence}
        </ThemedText>
      </View>
      <View style={styles.middle}>
        <ThemedText type="bodyStrong">Due {formatDate(dueDate)}</ThemedText>
        <Badge label={status} />
      </View>
      <View style={styles.right}>
        <ThemedText type="money">{displayFormatted(amountDueFormatted)}</ThemedText>
        {partiallyPaid && remainingFormatted ? (
          <ThemedText type="caption" style={{ color: theme.warning }}>
            {displayFormatted(remainingFormatted)} left
          </ThemedText>
        ) : null}
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    paddingHorizontal: Spacing.three,
    paddingVertical: 12,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  seq: { width: 32, height: 32, borderRadius: 16, alignItems: 'center', justifyContent: 'center' },
  middle: { flex: 1, gap: 4 },
  right: { alignItems: 'flex-end', gap: 2 },
});
