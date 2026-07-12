import { router } from 'expo-router';
import { Pressable, ScrollView, StyleSheet, View } from 'react-native';

import { logout } from '@/api/auth';
import { ThemedText } from '@/components/themed-text';
import { useAuthStore } from '@/stores/authStore';

/**
 * Phase 0 shell: session info only.
 * Phase 1 adds savings accounts, balances, transaction history, and
 * withdrawal requests.
 */
export default function CustomerHome() {
  const user = useAuthStore((state) => state.user);
  const clearSession = useAuthStore((state) => state.clearSession);

  async function handleLogout() {
    try {
      await logout();
    } catch {
      // Local logout regardless of server reachability.
    }
    await clearSession();
    router.replace('/(auth)/login');
  }

  return (
    <ScrollView contentContainerStyle={styles.container}>
      <View style={styles.card}>
        <ThemedText type="subtitle">Welcome, {user?.name}</ThemedText>
        <ThemedText type="small">
          Your savings accounts will appear here once the first release ships.
        </ThemedText>
      </View>

      <Pressable style={styles.button} onPress={handleLogout}>
        <ThemedText style={styles.buttonText}>Sign out</ThemedText>
      </Pressable>
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  container: { padding: 16, gap: 16 },
  card: {
    borderRadius: 12,
    borderWidth: 1,
    borderColor: '#e0e0e5',
    padding: 16,
    gap: 6,
    backgroundColor: '#ffffff',
  },
  button: {
    backgroundColor: '#6b7280',
    borderRadius: 10,
    paddingVertical: 12,
    alignItems: 'center',
  },
  buttonText: { color: '#ffffff', fontWeight: '600' },
});
