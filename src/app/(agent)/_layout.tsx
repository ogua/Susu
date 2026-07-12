import { Redirect, Stack } from 'expo-router';

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
    <Stack>
      <Stack.Screen name="index" options={{ title: 'Agent Dashboard' }} />
    </Stack>
  );
}
