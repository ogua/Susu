import { Redirect, Stack } from 'expo-router';

import { Palette } from '@/constants/theme';
import { useAuthStore } from '@/stores/authStore';

/** Staff experience (field agents; managers/admins reviewing on the go). */
export default function AgentLayout() {
  const token = useAuthStore((state) => state.token);
  const user = useAuthStore((state) => state.user);

  if (!token || !user) {
    return <Redirect href="/(auth)/login" />;
  }

  if (user.role === 'customer') {
    return <Redirect href="/(customer)" />;
  }

  return (
    <Stack
      screenOptions={{
        headerStyle: { backgroundColor: Palette.primary500 },
        headerTintColor: '#ffffff',
        headerTitleStyle: { fontWeight: '600' },
      }}
    >
      <Stack.Screen name="index" options={{ title: 'OguaFinance' }} />
      <Stack.Screen name="accounts" options={{ title: 'My Accounts' }} />
      <Stack.Screen name="collect/[accountId]" options={{ title: 'Record Collection' }} />
      <Stack.Screen name="register-customer" options={{ title: 'Register Customer' }} />
      <Stack.Screen name="open-account" options={{ title: 'Open Savings Account' }} />
      <Stack.Screen name="day-close" options={{ title: 'Day Summary' }} />
      <Stack.Screen name="sync" options={{ title: 'Sync Queue' }} />
      <Stack.Screen name="payment-verify" options={{ title: 'Mobile Money Payment' }} />
      <Stack.Screen name="loans/index" options={{ title: 'Loans' }} />
      <Stack.Screen name="loans/[loanId]" options={{ title: 'Loan' }} />
      <Stack.Screen name="loans/apply/[accountId]" options={{ title: 'Apply for Loan' }} />
      <Stack.Screen name="groups/index" options={{ title: 'Susu Groups' }} />
      <Stack.Screen name="groups/[groupId]" options={{ title: 'Group' }} />
      <Stack.Screen name="group-loans/index" options={{ title: 'Group Loans' }} />
      <Stack.Screen name="group-loans/[groupLoanId]" options={{ title: 'Group Loan' }} />
      <Stack.Screen name="group-loans/apply" options={{ title: 'Apply for Group Loan' }} />
    </Stack>
  );
}
