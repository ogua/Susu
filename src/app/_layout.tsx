import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { DarkTheme, DefaultTheme, Stack, ThemeProvider } from 'expo-router';
import * as SplashScreen from 'expo-splash-screen';
import { StatusBar } from 'expo-status-bar';
import { useEffect } from 'react';
import { useColorScheme } from 'react-native';

import '@/location/backgroundTracking';

import { SyncToast } from '@/components/sync-toast';
import { Colors } from '@/constants/theme';
import { useAuthStore } from '@/stores/authStore';
import { useOutboxStatus } from '@/stores/outboxStatusStore';
import { watchConnectivity } from '@/sync/engine';

SplashScreen.preventAutoHideAsync();

const queryClient = new QueryClient({
  defaultOptions: {
    queries: { retry: 1, staleTime: 30_000 },
  },
});

export default function RootLayout() {
  const colorScheme = useColorScheme();
  const hydrated = useAuthStore((state) => state.hydrated);
  const hydrate = useAuthStore((state) => state.hydrate);

  useEffect(() => {
    void hydrate();
    void useOutboxStatus.getState().refresh();

    return watchConnectivity();
  }, [hydrate]);

  useEffect(() => {
    if (hydrated) {
      void SplashScreen.hideAsync();
    }
  }, [hydrated]);

  if (!hydrated) {
    return null;
  }

  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider value={colorScheme === 'dark' ? navDark : navLight}>
        <StatusBar style="auto" />
        <Stack screenOptions={{ headerShown: false }} />
        <SyncToast />
      </ThemeProvider>
    </QueryClientProvider>
  );
}

// Navigation themes seeded from our tokens so screen transitions and native
// headers never flash the default white/black behind the app background.
const navLight = {
  ...DefaultTheme,
  colors: {
    ...DefaultTheme.colors,
    primary: Colors.light.primary,
    background: Colors.light.background,
    card: Colors.light.background,
    text: Colors.light.text,
    border: Colors.light.border,
  },
};

const navDark = {
  ...DarkTheme,
  colors: {
    ...DarkTheme.colors,
    primary: Colors.dark.primaryText,
    background: Colors.dark.background,
    card: Colors.dark.background,
    text: Colors.dark.text,
    border: Colors.dark.border,
  },
};
