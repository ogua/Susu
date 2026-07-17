import { router } from "expo-router";
import { useState } from "react";
import {
  KeyboardAvoidingView,
  Platform,
  Pressable,
  StyleSheet,
  View,
} from "react-native";
import { SafeAreaView } from "react-native-safe-area-context";

import { login } from "@/api/auth";
import { apiErrorMessage } from "@/api/client";
import { ThemedText } from "@/components/themed-text";
import { Button, Card, Input } from "@/components/ui";
import { Palette, Radii } from "@/constants/theme";
import { useTheme } from "@/hooks/use-theme";
import { useAuthStore } from "@/stores/authStore";

export default function LoginScreen() {
  const theme = useTheme();
  const baseUrl = useAuthStore((state) => state.baseUrl);
  const setBaseUrl = useAuthStore((state) => state.setBaseUrl);
  const setSession = useAuthStore((state) => state.setSession);

  const [loginId, setLoginId] = useState("");
  const [password, setPassword] = useState("");
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
      router.replace(
        response.user.role === "customer" ? "/(customer)" : "/(agent)",
      );
    } catch (err) {
      setError(apiErrorMessage(err));
    } finally {
      setBusy(false);
    }
  }

  return (
    <SafeAreaView style={[styles.safeArea, { backgroundColor: theme.background }]}>
      <KeyboardAvoidingView
        behavior={Platform.OS === "ios" ? "padding" : undefined}
        style={styles.container}
      >
        <View style={styles.header}>
          <View style={styles.logoMark}>
            <ThemedText type="title" style={styles.logoText}>
              O
            </ThemedText>
          </View>
          <ThemedText type="title">OguaFinance</ThemedText>
          <ThemedText type="small" themeColor="textSecondary">
            Susu savings, loans &amp; groups
          </ThemedText>
        </View>

        <Card style={styles.form}>
          <Input
            label="Email or phone"
            placeholder="you@example.com"
            autoCapitalize="none"
            autoCorrect={false}
            keyboardType="email-address"
            value={loginId}
            onChangeText={setLoginId}
          />
          <Input
            label="Password"
            placeholder="••••••••"
            secureTextEntry
            value={password}
            onChangeText={setPassword}
            error={error}
          />

          {showServer ? (
            <Input
              label="Server address"
              placeholder="https://susu.example.com"
              autoCapitalize="none"
              autoCorrect={false}
              keyboardType="url"
              value={serverUrl}
              onChangeText={setServerUrl}
            />
          ) : null}

          <Button
            title="Sign in"
            loading={busy}
            disabled={!loginId || !password}
            onPress={handleLogin}
          />

          <Pressable onPress={() => setShowServer((visible) => !visible)}>
            <ThemedText type="small" themeColor="textSecondary" style={styles.serverToggle}>
              {showServer ? "Hide server settings" : "Change server"}
            </ThemedText>
          </Pressable>
        </Card>
      </KeyboardAvoidingView>
    </SafeAreaView>
  );
}

const styles = StyleSheet.create({
  safeArea: { flex: 1 },
  container: { flex: 1, justifyContent: "center", paddingHorizontal: 24 },
  header: { alignItems: "center", marginBottom: 24, gap: 4 },
  logoMark: {
    width: 64,
    height: 64,
    borderRadius: Radii.lg,
    backgroundColor: Palette.primary500,
    alignItems: "center",
    justifyContent: "center",
    marginBottom: 8,
  },
  logoText: { color: "#ffffff" },
  form: { gap: 14 },
  serverToggle: {
    textAlign: "center",
    marginTop: 4,
    textDecorationLine: "underline",
  },
});
