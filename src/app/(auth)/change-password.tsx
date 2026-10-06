import { router } from 'expo-router';
import { useRef, useState } from 'react';
import { KeyboardAvoidingView, Platform, ScrollView, StyleSheet, View, type TextInput } from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { changePassword, logout } from '@/api/auth';
import { apiErrorMessage, apiFieldError } from '@/api/client';
import { ThemedText } from '@/components/themed-text';
import { Button, Input, Notice } from '@/components/ui';
import { Radii, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { useAuthStore } from '@/stores/authStore';

/**
 * Shown while the account is on a temporary password (sent at onboarding or
 * after an admin reset): the server refuses everything else until the user
 * chooses their own password here.
 */
export default function ChangePasswordScreen() {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const user = useAuthStore((state) => state.user);
  const setUser = useAuthStore((state) => state.setUser);
  const clearSession = useAuthStore((state) => state.clearSession);

  const newRef = useRef<TextInput>(null);
  const confirmRef = useRef<TextInput>(null);
  const [current, setCurrent] = useState('');
  const [password, setPassword] = useState('');
  const [confirmation, setConfirmation] = useState('');
  const [busy, setBusy] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [fieldErrors, setFieldErrors] = useState<{ current?: string | null; password?: string | null }>({});

  const mismatch = confirmation.length > 0 && confirmation !== password;
  const canSubmit = current.length > 0 && password.length >= 8 && confirmation === password;

  async function handleSubmit() {
    if (busy || !canSubmit) {
      return;
    }
    setBusy(true);
    setError(null);
    setFieldErrors({});
    try {
      const updated = await changePassword(current, password, confirmation);
      await setUser(updated);
      router.replace(updated.role === 'customer' ? '/(customer)' : '/(agent)');
    } catch (err) {
      const currentError = apiFieldError(err, 'current_password');
      const passwordError = apiFieldError(err, 'password');
      if (currentError || passwordError) {
        setFieldErrors({ current: currentError, password: passwordError });
      } else {
        setError(apiErrorMessage(err));
      }
    } finally {
      setBusy(false);
    }
  }

  async function handleSignOut() {
    try {
      await logout();
    } catch {
      // The local session is cleared either way.
    }
    await clearSession();
    router.replace('/(auth)/login');
  }

  return (
    <View style={[styles.flex, { backgroundColor: theme.background }]}>
      <KeyboardAvoidingView style={styles.flex} behavior={Platform.OS === 'ios' ? 'padding' : undefined}>
        <ScrollView
          contentContainerStyle={[styles.scroll, { paddingTop: insets.top + Spacing.five, paddingBottom: insets.bottom + Spacing.four }]}
          keyboardShouldPersistTaps="handled"
        >
          <View style={[styles.card, { backgroundColor: theme.surface, borderColor: theme.border }]}>
            <View style={styles.header}>
              <ThemedText type="heading" accessibilityRole="header">
                Choose a new password
              </ThemedText>
              <ThemedText type="small" themeColor="textSecondary">
                {user?.name ? `${user.name}, you` : 'You'} signed in with a temporary password. Set your own to continue.
              </ThemedText>
            </View>

            {error ? <Notice tone="danger" message={error} /> : null}

            <Input
              label="Temporary password"
              icon="lock"
              password
              autoComplete="current-password"
              textContentType="password"
              returnKeyType="next"
              value={current}
              onChangeText={setCurrent}
              onSubmitEditing={() => newRef.current?.focus()}
              submitBehavior="submit"
              error={fieldErrors.current}
            />
            <Input
              ref={newRef}
              label="New password"
              icon="lock"
              password
              autoComplete="new-password"
              textContentType="newPassword"
              hint="At least 8 characters."
              returnKeyType="next"
              value={password}
              onChangeText={setPassword}
              onSubmitEditing={() => confirmRef.current?.focus()}
              submitBehavior="submit"
              error={fieldErrors.password}
            />
            <Input
              ref={confirmRef}
              label="Confirm new password"
              icon="lock"
              password
              autoComplete="new-password"
              textContentType="newPassword"
              returnKeyType="go"
              value={confirmation}
              onChangeText={setConfirmation}
              onSubmitEditing={handleSubmit}
              error={mismatch ? 'The passwords do not match.' : null}
            />

            <Button
              title="Save password"
              loadingTitle="Saving…"
              size="lg"
              loading={busy}
              disabled={!canSubmit}
              onPress={handleSubmit}
            />
            <Button title="Sign out" variant="ghost" onPress={handleSignOut} disabled={busy} />
          </View>
        </ScrollView>
      </KeyboardAvoidingView>
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  scroll: { flexGrow: 1, paddingHorizontal: Spacing.three },
  card: {
    borderRadius: Radii.xl,
    borderWidth: StyleSheet.hairlineWidth,
    padding: Spacing.four,
    gap: Spacing.three,
    maxWidth: 480,
    width: '100%',
    alignSelf: 'center',
  },
  header: { gap: 2 },
});
