import { useQuery } from '@tanstack/react-query';
import { ActivityIndicator, Pressable, StyleSheet, View } from 'react-native';

import { getCustomer } from '@/api/customers';
import { ThemedText } from '@/components/themed-text';
import { Palette } from '@/constants/theme';

/**
 * Lets the agent pick which of a customer's savings accounts a deposit or
 * write-off applies to — the backend now requires an explicit
 * savings_account_id instead of inferring or escrowing one.
 */
export function SavingsAccountPicker({
  customerId,
  value,
  onChange,
}: {
  customerId: string;
  value: string | null;
  onChange: (accountId: string) => void;
}) {
  const customer = useQuery({
    queryKey: ['customer', customerId],
    queryFn: () => getCustomer(customerId),
    enabled: !!customerId,
  });

  if (customer.isLoading) {
    return <ActivityIndicator />;
  }

  const accounts = customer.data?.savings_accounts ?? [];

  if (accounts.length === 0) {
    return (
      <ThemedText type="small" style={styles.empty}>
        This customer has no savings accounts — open one first.
      </ThemedText>
    );
  }

  return (
    <View style={styles.chipRow}>
      {accounts.map((account) => (
        <Pressable
          key={account.id}
          style={[styles.chip, value === account.id && styles.chipActive]}
          onPress={() => onChange(account.id)}
        >
          <ThemedText style={value === account.id ? styles.chipTextActive : undefined}>
            {account.account_number} · {account.balance_formatted}
          </ThemedText>
        </Pressable>
      ))}
    </View>
  );
}

const styles = StyleSheet.create({
  chipRow: { flexDirection: 'row', flexWrap: 'wrap', gap: 8 },
  chip: {
    borderWidth: 1,
    borderColor: Palette.border,
    borderRadius: 10,
    paddingVertical: 10,
    paddingHorizontal: 14,
  },
  chipActive: { backgroundColor: Palette.primary500, borderColor: Palette.primary500 },
  chipTextActive: { color: '#ffffff', fontWeight: '700' },
  empty: { opacity: 0.6 },
});
