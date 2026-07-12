import { Redirect, Stack } from 'expo-router';

import { useAuthStore } from '@/stores/authStore';

/** Customer experience (online-first: balances, history, requests). */
export default function CustomerLayout() {
  const token = useAuthStore((state) => state.token);
  const user = useAuthStore((state) => state.user);

  if (!token || !user) {
    return <Redirect href="/(auth)/login" />;
  }

  if (user.role !== 'customer') {
    return <Redirect href="/(agent)" />;
  }

  return (
    <Stack>
      <Stack.Screen name="index" options={{ title: 'My Susu' }} />
    </Stack>
  );
}
