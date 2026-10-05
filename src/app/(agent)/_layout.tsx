import { Redirect, Stack } from 'expo-router';

import { useTheme } from '@/hooks/use-theme';
import { useAuthStore } from '@/stores/authStore';

/** Staff experience (field agents; managers/admins reviewing on the go). */
export default function AgentLayout() {
  const theme = useTheme();
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
        headerStyle: { backgroundColor: theme.background },
        headerTintColor: theme.text,
        headerTitleStyle: { fontWeight: '600', color: theme.text },
        headerShadowVisible: false,
        headerBackButtonDisplayMode: 'minimal',
        contentStyle: { backgroundColor: theme.background },
      }}
    >
      <Stack.Screen name="index" options={{ title: 'Home', headerShown: false }} />
      <Stack.Screen name="accounts" options={{ title: 'Collect' }} />
      <Stack.Screen name="collect/[accountId]" options={{ title: 'Record Collection', gestureEnabled: true }} />
      <Stack.Screen name="register-customer" options={{ title: 'Register Customer' }} />
      <Stack.Screen name="open-account" options={{ title: 'Open Savings Account' }} />
      <Stack.Screen name="day-close" options={{ title: 'Close My Day' }} />
      <Stack.Screen name="sync" options={{ title: 'Sync Status' }} />
      <Stack.Screen name="profile" options={{ title: 'My Profile' }} />
      <Stack.Screen name="tracking/index" options={{ title: 'Agent Tracking' }} />
      <Stack.Screen name="tracking/[agentId]" options={{ title: 'Track Agent' }} />
      <Stack.Screen name="payment-verify" options={{ title: 'Mobile Money Payment' }} />
      <Stack.Screen name="loans/index" options={{ title: 'Loans' }} />
      <Stack.Screen name="loans/[loanId]" options={{ title: 'Loan' }} />
      <Stack.Screen name="loans/apply/[accountId]" options={{ title: 'Apply for Loan' }} />
      <Stack.Screen name="groups/index" options={{ title: 'Susu Groups' }} />
      <Stack.Screen name="groups/[groupId]" options={{ title: 'Group' }} />
      <Stack.Screen name="group-loans/index" options={{ title: 'Group Loans' }} />
      <Stack.Screen name="group-loans/[groupLoanId]" options={{ title: 'Group Loan' }} />
      <Stack.Screen name="group-loans/apply" options={{ title: 'Apply for Group Loan' }} />
      <Stack.Screen name="collection-sheet" options={{ title: 'Collection Sheet' }} />
      <Stack.Screen name="loan-groups/index" options={{ title: 'Customer Groups' }} />
      <Stack.Screen name="loan-groups/[loanGroupId]" options={{ title: 'Customer Group' }} />
    </Stack>
  );
}
