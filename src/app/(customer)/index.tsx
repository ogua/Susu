import { useQuery } from '@tanstack/react-query';
import { router } from 'expo-router';
import { ActivityIndicator, Pressable, RefreshControl, ScrollView, StyleSheet, View } from 'react-native';

import { getCustomerAccounts } from '@/api/accounts';
import { logout } from '@/api/auth';
import { ThemedText } from '@/components/themed-text';
import { useAuthStore } from '@/stores/authStore';
import type { SavingsAccount } from '@/types/api';

export default function CustomerHome() {
  const user = useAuthStore((state) => state.user);
  const clearSession = useAuthStore((state) => state.clearSession);

  const accounts = useQuery({
    queryKey: ['customer', 'accounts'],
    queryFn: getCustomerAccounts,
  });

  async function handleLogout() {
    try {
      await logout();
    } catch {
      // Local logout regardless of server reachability.
    }
    await clearSession();
    router.replace('/(auth)/login');
  }

  function renderAccount(account: SavingsAccount) {
    const isTarget = account.target_amount !== null;
    const progress = isTarget
      ? (account.target_progress_percent ?? 0) / 100
      : account.contribution_amount
        ? account.contributions_this_cycle / (account.product?.cycle_length_days ?? 31)
        : 0;

    return (
      <Pressable
        key={account.id}
        style={styles.accountCard}
        onPress={() =>
          router.push({
            pathname: '/(customer)/account/[accountId]',
            params: {
              accountId: account.id,
              targetAmountFormatted: account.target_amount_formatted ?? '',
              targetProgressPercent: String(account.target_progress_percent ?? ''),
              maturesAt: account.matures_at ?? '',
              maturedAt: account.matured_at ?? '',
            },
          })
        }
      >
        <ThemedText type="smallBold">{account.account_number}</ThemedText>
        <ThemedText type="subtitle">{account.balance_formatted}</ThemedText>
        {isTarget ? (
          <ThemedText type="small">
            {account.target_progress_percent}% of {account.target_amount_formatted} target
            {account.matured_at ? ' · Matured' : account.matures_at ? ` · Matures ${new Date(account.matures_at).toLocaleDateString()}` : ''}
          </ThemedText>
        ) : (
          <ThemedText type="small">
            {account.contributions_this_cycle}/{account.product?.cycle_length_days ?? 31} days this cycle
          </ThemedText>
        )}
        <View style={styles.progressTrack}>
          <View style={[styles.progressFill, { width: `${Math.min(progress * 100, 100)}%` }]} />
        </View>
      </Pressable>
    );
  }

  return (
    <ScrollView
      contentContainerStyle={styles.container}
      refreshControl={<RefreshControl refreshing={accounts.isRefetching} onRefresh={() => accounts.refetch()} />}
    >
      <View style={styles.card}>
        <ThemedText type="subtitle">Welcome, {user?.name}</ThemedText>
      </View>

      <Pressable style={styles.loansButton} onPress={() => router.push('/(customer)/loans')}>
        <ThemedText style={styles.loansButtonText}>My Loans</ThemedText>
      </Pressable>
      <Pressable style={styles.loansButton} onPress={() => router.push('/(customer)/groups/index')}>
        <ThemedText style={styles.loansButtonText}>My Susu Groups</ThemedText>
      </Pressable>

      {accounts.isLoading ? (
        <ActivityIndicator style={{ marginTop: 24 }} />
      ) : accounts.isError ? (
        <ThemedText style={styles.empty}>Could not load your accounts. Pull down to retry.</ThemedText>
      ) : accounts.data && accounts.data.length > 0 ? (
        accounts.data.map(renderAccount)
      ) : (
        <ThemedText style={styles.empty}>No savings accounts yet.</ThemedText>
      )}

      <Pressable style={styles.button} onPress={handleLogout}>
        <ThemedText style={styles.buttonText}>Sign out</ThemedText>
      </Pressable>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { padding: 16, gap: 12 },
  card: {
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#e0e0e5',
    padding: 16,
    gap: 6,
    backgroundColor: '#ffffff',
  },
  accountCard: {
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#e0e0e5',
    padding: 16,
    gap: 4,
    backgroundColor: '#ffffff',
  },
  progressTrack: {
    height: 6,
    borderRadius: 3,
    backgroundColor: '#e5e5ea',
    marginTop: 6,
    overflow: 'hidden',
  },
  progressFill: { height: '100%', backgroundColor: '#208AEF' },
  loansButton: {
    backgroundColor: '#eef4ff',
    borderRadius: 10,
    paddingVertical: 12,
    alignItems: 'center',
  },
  loansButtonText: { color: '#208AEF', fontWeight: '600' },
  button: {
    backgroundColor: '#6b7280',
    borderRadius: 10,
    paddingVertical: 12,
    alignItems: 'center',
    marginTop: 8,
  },
  buttonText: { color: '#ffffff', fontWeight: '600' },
  empty: { textAlign: 'center', marginTop: 24, opacity: 0.6 },
});
