import { Redirect, Stack } from 'expo-router';

import { useTheme } from '@/hooks/use-theme';
import { useAuthStore } from '@/stores/authStore';

/** Customer experience (online-first: balances, history, requests). */
export default function CustomerLayout() {
  const theme = useTheme();
  const token = useAuthStore((state) => state.token);
  const user = useAuthStore((state) => state.user);

  if (!token || !user) {
    return <Redirect href="/(auth)/login" />;
  }

  if (user.role !== 'customer') {
    return <Redirect href="/(agent)" />;
  }

  return (
    <Stack
      screenOptions={{
        headerStyle: { backgroundColor: theme.background },
        headerTintColor: theme.text,
        headerTitleStyle: { fontWeight: '600', color: theme.text },
        headerShadowVisible: false,
        headerBackButtonDisplayMode: 'minimal',
        contentStyle: { backgroundColor: theme.background },
      }}
    >
      <Stack.Screen name="index" options={{ title: 'Home', headerShown: false }} />
      <Stack.Screen name="account/[accountId]" options={{ title: 'Account' }} />
      <Stack.Screen name="withdraw" options={{ title: 'Request Withdrawal' }} />
      <Stack.Screen name="deposit" options={{ title: 'Deposit' }} />
      <Stack.Screen name="buy-shares" options={{ title: 'Buy Shares' }} />
      <Stack.Screen name="payment-verify" options={{ title: 'Mobile Money Payment' }} />
      <Stack.Screen name="loans/index" options={{ title: 'My Loans' }} />
      <Stack.Screen name="loans/[loanId]" options={{ title: 'Loan' }} />
      <Stack.Screen name="loans/apply" options={{ title: 'Apply for Loan' }} />
      <Stack.Screen name="groups/index" options={{ title: 'My Groups' }} />
      <Stack.Screen name="groups/[groupId]" options={{ title: 'Group' }} />
    </Stack>
  );
}
