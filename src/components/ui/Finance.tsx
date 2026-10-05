import { LinearGradient } from 'expo-linear-gradient';
import { Alert, StyleSheet, View, type StyleProp, type ViewStyle } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Icon, type IconName } from '@/components/ui/Icon';
import { Radii, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import type { Transaction, TransactionType } from '@/types/api';
import { formatDateTime, humanize } from '@/utils/format';
import { displayFormatted, formatMoney } from '@/utils/money';

/**
 * Gradient hero for the single most important number on a screen (total
 * savings, account balance, today's collections). Always explicitly labelled
 * — never just "Balance".
 */
export function HeroCard({
  label,
  amount,
  caption,
  children,
  style,
}: {
  label: string;
  amount: string;
  caption?: string;
  children?: React.ReactNode;
  style?: StyleProp<ViewStyle>;
}) {
  const theme = useTheme();

  return (
    <LinearGradient
      colors={[theme.heroStart, theme.heroEnd]}
      start={{ x: 0, y: 0 }}
      end={{ x: 1, y: 1 }}
      style={[styles.hero, style]}
    >
      <View accessible accessibilityLabel={`${label}: ${amount}${caption ? `. ${caption}` : ''}`}>
        <ThemedText type="label" style={styles.heroLabel}>
          {label}
        </ThemedText>
        <ThemedText type="moneyHero" style={styles.heroAmount} adjustsFontSizeToFit numberOfLines={1}>
          {amount}
        </ThemedText>
        {caption ? (
          <ThemedText type="small" style={styles.heroCaption}>
            {caption}
          </ThemedText>
        ) : null}
      </View>
      {children}
    </LinearGradient>
  );
}

/** Translucent stat inside a HeroCard. */
export function HeroStat({ label, value }: { label: string; value: string }) {
  return (
    <View style={styles.heroStat} accessible accessibilityLabel={`${label}: ${value}`}>
      <ThemedText type="caption" style={styles.heroCaption}>
        {label}
      </ThemedText>
      <ThemedText type="money" style={styles.heroAmount} numberOfLines={1} adjustsFontSizeToFit>
        {value}
      </ThemedText>
    </View>
  );
}

type Direction = 'credit' | 'debit' | 'neutral';

/**
 * Transaction amounts arrive unsigned. The server's `direction` (derived from
 * the entry's lines on this savings account) is authoritative; the per-type
 * default below is only a fallback for older servers. Types whose effect
 * can't be known from the type alone stay unsigned rather than guessed.
 */
const TRANSACTION_META: Record<TransactionType, { label: string; icon: IconName; direction: Direction }> = {
  collection: { label: 'Deposit', icon: 'arrowDown', direction: 'credit' },
  withdrawal: { label: 'Withdrawal', icon: 'arrowUp', direction: 'debit' },
  commission: { label: 'Commission charged', icon: 'percent', direction: 'debit' },
  remittance: { label: 'Remittance', icon: 'bank', direction: 'neutral' },
  reversal: { label: 'Correction (reversal)', icon: 'reverse', direction: 'neutral' },
  adjustment: { label: 'Adjustment', icon: 'adjust', direction: 'neutral' },
  penalty: { label: 'Penalty charged', icon: 'warning', direction: 'debit' },
  savings_interest: { label: 'Interest earned', icon: 'savings', direction: 'credit' },
  shares_purchase: { label: 'Shares purchased', icon: 'chart', direction: 'credit' },
  group_loan_deposit_held: { label: 'Loan security deposit', icon: 'lock', direction: 'credit' },
  group_loan_deposit_refunded: { label: 'Security deposit refunded', icon: 'reverse', direction: 'neutral' },
  group_loan_deposit_applied: { label: 'Deposit applied to loan', icon: 'loan', direction: 'debit' },
  savings_applied_to_loan_write_off: { label: 'Savings applied to loan', icon: 'loan', direction: 'debit' },
  savings_applied_to_group_loan_write_off: { label: 'Savings applied to group loan', icon: 'loan', direction: 'debit' },
};

const METHOD_LABELS: Record<Transaction['payment_method'], string> = {
  cash: 'Cash',
  mobile_money: 'Mobile Money',
  internal: 'Internal',
};

export function TransactionRow({ transaction }: { transaction: Transaction }) {
  const theme = useTheme();
  const known = TRANSACTION_META[transaction.type as TransactionType];
  const meta = {
    label: known?.label ?? humanize(transaction.type),
    icon: known?.icon ?? ('info' as IconName),
    direction: (transaction.direction ?? known?.direction ?? 'neutral') as Direction,
  };
  const sign = meta.direction === 'credit' ? '+' : meta.direction === 'debit' ? '−' : '';
  const amountColor =
    transaction.status === 'reversed' || transaction.status === 'failed'
      ? theme.textMuted
      : meta.direction === 'credit'
        ? theme.success
        : meta.direction === 'debit'
          ? theme.danger
          : theme.text;
  const iconBg =
    meta.direction === 'credit' ? theme.successSoft : meta.direction === 'debit' ? theme.dangerSoft : theme.surfaceMuted;
  const amountText = `${sign}${displayFormatted(transaction.amount_formatted)}`;
  const notPosted = transaction.status !== 'completed';

  return (
    <View
      style={[styles.txRow, { borderBottomColor: theme.border }]}
      accessible
      accessibilityLabel={`${meta.label}, ${meta.direction === 'credit' ? 'money in' : meta.direction === 'debit' ? 'money out' : ''} ${displayFormatted(transaction.amount_formatted)}, ${formatDateTime(transaction.recorded_at)}${notPosted ? `, ${transaction.status}` : ''}`}
    >
      <View style={[styles.txIcon, { backgroundColor: iconBg }]}>
        <Icon name={meta.icon} size={18} color={amountColor} />
      </View>
      <View style={styles.txMiddle}>
        <ThemedText type="bodyStrong" numberOfLines={1}>
          {meta.label}
        </ThemedText>
        <ThemedText type="caption" themeColor="textMuted" numberOfLines={1}>
          {formatDateTime(transaction.recorded_at)} · {METHOD_LABELS[transaction.payment_method] ?? transaction.payment_method}
        </ThemedText>
        <ThemedText type="caption" themeColor="textMuted" numberOfLines={1}>
          Ref {transaction.reference}
        </ThemedText>
      </View>
      <View style={styles.txRight}>
        <ThemedText
          type="money"
          style={[{ color: amountColor }, transaction.status === 'reversed' && styles.struck]}
        >
          {amountText}
        </ThemedText>
        {notPosted ? (
          <ThemedText type="caption" style={{ color: theme.warning, fontWeight: '700' }}>
            {transaction.status === 'reversed' ? 'Reversed' : transaction.status === 'failed' ? 'Failed' : 'Pending'}
          </ThemedText>
        ) : transaction.balance_after !== null ? (
          <ThemedText type="caption" themeColor="textMuted">
            Bal. {formatMoney(transaction.balance_after)}
          </ThemedText>
        ) : null}
      </View>
    </View>
  );
}

type ConfirmOptions = {
  title: string;
  message: string;
  /** Specific action label, e.g. "Write off GH₵ 1,200.00" — never "Continue". */
  confirmLabel: string;
  destructive?: boolean;
  onConfirm: () => void;
};

/** Native confirmation for money-moving or irreversible actions. */
export function confirmAction({ title, message, confirmLabel, destructive, onConfirm }: ConfirmOptions) {
  Alert.alert(title, message, [
    { text: 'Cancel', style: 'cancel' },
    { text: confirmLabel, style: destructive ? 'destructive' : 'default', onPress: onConfirm },
  ]);
}

const styles = StyleSheet.create({
  hero: { borderRadius: Radii.xl, padding: Spacing.four, gap: Spacing.three, overflow: 'hidden' },
  heroLabel: { color: 'rgba(255,255,255,0.8)' },
  heroAmount: { color: '#FFFFFF' },
  heroCaption: { color: 'rgba(255,255,255,0.78)' },
  heroStat: {
    flex: 1,
    backgroundColor: 'rgba(255,255,255,0.12)',
    borderRadius: Radii.md,
    paddingVertical: 10,
    paddingHorizontal: 12,
    gap: 2,
  },
  txRow: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    paddingVertical: 12,
    paddingHorizontal: Spacing.three,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  txIcon: { width: 40, height: 40, borderRadius: Radii.pill, alignItems: 'center', justifyContent: 'center' },
  txMiddle: { flex: 1, gap: 1 },
  txRight: { alignItems: 'flex-end', gap: 2 },
  struck: { textDecorationLine: 'line-through' },
});
