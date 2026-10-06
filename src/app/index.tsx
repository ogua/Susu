import { Redirect } from 'expo-router';

import { useAuthStore } from '@/stores/authStore';

/** Entry point: route to the experience matching the session's role. */
export default function Index() {
  const token = useAuthStore((state) => state.token);
  const user = useAuthStore((state) => state.user);

  if (!token || !user) {
    return <Redirect href="/(auth)/login" />;
  }

  if (user.must_change_password) {
    return <Redirect href="/(auth)/change-password" />;
  }

  if (user.role === 'customer') {
    return <Redirect href="/(customer)" />;
  }

  return <Redirect href="/(agent)" />;
}
