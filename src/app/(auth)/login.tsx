import { LinearGradient } from 'expo-linear-gradient';
import { router } from 'expo-router';
import { useRef, useState } from 'react';
import {
  KeyboardAvoidingView,
  Platform,
  Pressable,
  ScrollView,
  StyleSheet,
  View,
  type TextInput,
} from 'react-native';
import Animated, { FadeInDown } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { login } from '@/api/auth';
import { apiErrorMessage, apiFieldError } from '@/api/client';
import { ThemedText } from '@/components/themed-text';
import { Button, Icon, Input, Notice } from '@/components/ui';
import { Radii, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { useAuthStore } from '@/stores/authStore';
import { useOutboxStatus } from '@/stores/outboxStatusStore';
import { drainOutbox } from '@/sync/engine';

export default function LoginScreen() {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const baseUrl = useAuthStore((state) => state.baseUrl);
  const setBaseUrl = useAuthStore((state) => state.setBaseUrl);
  const setSession = useAuthStore((state) => state.setSession);

  const passwordRef = useRef<TextInput>(null);
  const [loginId, setLoginId] = useState('');
  const [password, setPassword] = useState('');
  const [serverUrl, setServerUrl] = useState(baseUrl);
  const [showServer, setShowServer] = useState(false);
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [loginError, setLoginError] = useState<string | null>(null);

  async function handleLogin() {
    if (busy || !loginId.trim() || !password) {
      return;
    }
    setBusy(true);
    setError(null);
    setLoginError(null);
    try {
      if (serverUrl.trim() !== baseUrl) {
        await setBaseUrl(serverUrl.trim());
      }
      const response = await login(loginId.trim(), password);
      await setSession(response.token, response.user);
      // Outbox is scoped per user: show this user's counts and send any
      // records they left unsynced last time.
      void useOutboxStatus.getState().refresh();
      void drainOutbox();
      router.replace(response.user.role === 'customer' ? '/(customer)' : '/(agent)');
    } catch (err) {
      const fieldError = apiFieldError(err, 'login');
      if (fieldError) {
        setLoginError(fieldError);
      } else {
        setError(apiErrorMessage(err));
      }
    } finally {
      setBusy(false);
    }
  }

  return (
    <View style={[styles.flex, { backgroundColor: theme.background }]}>
      <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView
          contentContainerStyle={[styles.scroll, { paddingBottom: insets.bottom + Spacing.four }]}
          keyboardShouldPersistTaps="handled"
          showsVerticalScrollIndicator={false}
        >
          <LinearGradient
            colors={[theme.heroStart, theme.heroEnd]}
            start={{ x: 0, y: 0 }}
            end={{ x: 1, y: 1 }}
            style={[styles.brand, { paddingTop: insets.top + Spacing.five }]}
          >
            <Animated.View entering={FadeInDown.duration(300)} style={styles.brandInner}>
              <View style={styles.logoMark}>
                <Icon name="bank" size={30} color="#FFFFFF" />
              </View>
              <ThemedText type="title" style={styles.brandTitle}>
                OguaFinance
              </ThemedText>
              <ThemedText type="small" style={styles.brandSubtitle}>
                Susu savings, loans and groups — recorded securely.
              </ThemedText>
            </Animated.View>
          </LinearGradient>

          <Animated.View
            entering={FadeInDown.duration(320).delay(80)}
            style={[styles.formCard, { backgroundColor: theme.surface, borderColor: theme.border }]}
          >
            <View style={styles.formHeader}>
              <ThemedText type="heading" accessibilityRole="header">
                Sign in
              </ThemedText>
              <ThemedText type="small" themeColor="textSecondary">
                Use the email or phone number your branch registered.
              </ThemedText>
            </View>

            {error ? <Notice tone="danger" message={error} /> : null}

            <Input
              label="Email or phone number"
              icon="person"
              placeholder="you@example.com or 024…"
              autoCapitalize="none"
              autoCorrect={false}
              autoComplete="username"
              textContentType="username"
              keyboardType="email-address"
              returnKeyType="next"
              value={loginId}
              onChangeText={(text) => {
                setLoginId(text);
                setLoginError(null);
              }}
              onSubmitEditing={() => passwordRef.current?.focus()}
              submitBehavior="submit"
              error={loginError}
            />
            <Input
              ref={passwordRef}
              label="Password"
              icon="lock"
              placeholder="Your password"
              password
              autoComplete="current-password"
              textContentType="password"
              returnKeyType="go"
              value={password}
              onChangeText={setPassword}
              onSubmitEditing={handleLogin}
            />

            {showServer ? (
              <Input
                label="Server address"
                icon="server"
                placeholder="https://susu.example.com"
                hint="Only change this if your branch gave you a different address."
                autoCapitalize="none"
                autoCorrect={false}
                keyboardType="url"
                value={serverUrl}
                onChangeText={setServerUrl}
              />
            ) : null}

            <Button
              title="Sign in"
              loadingTitle="Signing in…"
              size="lg"
              loading={busy}
              disabled={!loginId.trim() || !password}
              onPress={handleLogin}
            />

            <View style={styles.trustRow}>
              <Icon name="shield" size={16} color={theme.success} />
              <ThemedText type="caption" themeColor="textSecondary" style={styles.flex}>
                Your session is encrypted and stored only on this device.
              </ThemedText>
            </View>
          </Animated.View>

          <Pressable
            accessibilityRole="button"
            accessibilityState={{ expanded: showServer }}
            onPress={() => setShowServer((visible) => !visible)}
            style={styles.serverToggle}
            hitSlop={8}
          >
            <Icon name="server" size={14} color={theme.textMuted} />
            <ThemedText type="caption" themeColor="textMuted">
              {showServer ? 'Hide server settings' : `Server: ${baseUrl.replace(/^https?:\/\//, '')}`}
            </ThemedText>
          </Pressable>
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  scroll: { flexGrow: 1 },
  brand: {
    paddingHorizontal: Spacing.four,
    paddingBottom: Spacing.six,
    borderBottomLeftRadius: Radii.xl,
    borderBottomRightRadius: Radii.xl,
  },
  brandInner: { alignItems: 'center', gap: Spacing.two },
  logoMark: {
    width: 64,
    height: 64,
    borderRadius: Radii.lg,
    backgroundColor: 'rgba(255,255,255,0.16)',
    borderWidth: 1,
    borderColor: 'rgba(255,255,255,0.28)',
    alignItems: 'center',
    justifyContent: 'center',
    marginBottom: Spacing.one,
  },
  brandTitle: { color: '#FFFFFF' },
  brandSubtitle: { color: 'rgba(255,255,255,0.82)', textAlign: 'center' },
  formCard: {
    marginTop: -Spacing.five,
    marginHorizontal: Spacing.three,
    borderRadius: Radii.xl,
    borderWidth: StyleSheet.hairlineWidth,
    padding: Spacing.four,
    gap: Spacing.three,
    maxWidth: 480,
    width: '100%',
    alignSelf: 'center',
    shadowColor: '#0f172a',
    shadowOpacity: 0.12,
    shadowRadius: 20,
    shadowOffset: { width: 0, height: 8 },
    elevation: 4,
  },
  formHeader: { gap: 2 },
  trustRow: { flexDirection: 'row', alignItems: 'center', gap: Spacing.two },
  serverToggle: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'center',
    gap: 6,
    paddingVertical: Spacing.three,
    marginTop: Spacing.two,
  },
});
