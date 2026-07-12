import { router } from 'expo-router';
import { useState } from 'react';
import {
  ActivityIndicator,
  KeyboardAvoidingView,
  Platform,
  Pressable,
  StyleSheet,
  TextInput,
  View,
} from 'react-native';
import { SafeAreaView } from 'react-native-safe-area-context';

import { apiErrorMessage } from '@/api/client';
import { login } from '@/api/auth';
import { ThemedText } from '@/components/themed-text';
import { useAuthStore } from '@/stores/authStore';

export default function LoginScreen() {
  const baseUrl = useAuthStore((state) => state.baseUrl);
  const setBaseUrl = useAuthStore((state) => state.setBaseUrl);
  const setSession = useAuthStore((state) => state.setSession);

  const [loginId, setLoginId] = useState('');
  const [password, setPassword] = useState('');
  const [serverUrl, setServerUrl] = useState(baseUrl);
  const [showServer, setShowServer] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);

  async function handleLogin() {
    setBusy(true);
    setError(null);
    try {
      if (serverUrl !== baseUrl) {
        await setBaseUrl(serverUrl);
      }
      const response = await login(loginId.trim(), password);
      await setSession(response.token, response.user);
      router.replace(response.user.role === 'customer' ? '/(customer)' : '/(agent)');
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView style={styles.safeArea}>
      <KeyboardAvoidingView
        behavior={Platform.OS === 'ios' ? 'padding' : undefined}
        style={styles.container}
      >
        <View style={styles.header}>
          <ThemedText type="title">SusuApp</ThemedText>
          <ThemedText type="small">Sign in to continue</ThemedText>
        </View>

        <View style={styles.form}>
          <TextInput
            style={styles.input}
            placeholder="Email or phone"
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="email-address"
            value={loginId}
            onChangeText={setLoginId}
          />
          <TextInput
            style={styles.input}
            placeholder="Password"
            secureTextEntry
            value={password}
            onChangeText={setPassword}
          />

          {showServer ? (
            <TextInput
              style={styles.input}
              placeholder="Server address (e.g. https://susu.example.com)"
              autoCapitalize="none"
              autoCorrect={false}
              keyboardType="url"
              value={serverUrl}
              onChangeText={setServerUrl}
            />
          ) : null}

          {error ? <ThemedText style={styles.error}>{error}</ThemedText> : null}

          <Pressable
            style={[styles.button, busy && styles.buttonDisabled]}
            disabled={busy || !loginId || !password}
            onPress={handleLogin}
          >
            {busy ? (
              <ActivityIndicator color="#fff" />
            ) : (
              <ThemedText style={styles.buttonText}>Sign in</ThemedText>
            )}
          </Pressable>

          <Pressable onPress={() => setShowServer((visible) => !visible)}>
            <ThemedText type="small" style={styles.serverToggle}>
              {showServer ? 'Hide server settings' : 'Change server'}
            </ThemedText>
          </Pressable>
        </View>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1 },
  container: { flex: 1, justifyContent: 'center', paddingHorizontal: 24 },
  header: { alignItems: 'center', marginBottom: 32, gap: 4 },
  form: { gap: 12 },
  input: {
    borderWidth: 1,
    borderColor: '#c7c7cc',
    borderRadius: 10,
    paddingHorizontal: 14,
    paddingVertical: 12,
    fontSize: 16,
    backgroundColor: '#ffffff',
    color: '#111111',
  },
  button: {
    backgroundColor: '#208AEF',
    borderRadius: 10,
    paddingVertical: 14,
    alignItems: 'center',
    marginTop: 4,
  },
  buttonDisabled: { opacity: 0.6 },
  buttonText: { color: '#ffffff', fontWeight: '600' },
  error: { color: '#d11a2a' },
  serverToggle: { textAlign: 'center', marginTop: 8, textDecorationLine: 'underline' },
});
