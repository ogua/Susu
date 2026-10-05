import { useEffect } from 'react';
import { Pressable, StyleSheet } from 'react-native';
import Animated, { FadeInDown, FadeOutDown } from 'react-native-reanimated';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ThemedText } from './themed-text';

import { Icon } from '@/components/ui/Icon';
import { Radii, ShadowFloating, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { useSyncToastStore } from '@/stores/syncToastStore';

const AUTO_DISMISS_MS = 4000;

/** App-wide banner surfacing the outcome of every outbox drain, including the
 * silent background one triggered by reconnect — so an agent knows whether a
 * sync actually completed without having to open the Sync Queue screen. */
export function SyncToast() {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const message = useSyncToastStore((state) => state.message);
  const tone = useSyncToastStore((state) => state.tone);
  const dismiss = useSyncToastStore((state) => state.dismiss);

  useEffect(() => {
    if (!message) {
      return;
    }

    const timer = setTimeout(dismiss, AUTO_DISMISS_MS);

    return () => clearTimeout(timer);
  }, [message, dismiss]);

  if (!message) {
    return null;
  }

  const warning = tone === 'warning';

  return (
    <Animated.View
      entering={FadeInDown.duration(200)}
      exiting={FadeOutDown.duration(150)}
      style={[styles.wrapper, { bottom: insets.bottom + Spacing.three }]}
      pointerEvents="box-none"
    >
      <Pressable
        accessibilityRole="alert"
        accessibilityLabel={`${message} Tap to dismiss.`}
        style={[styles.toast, ShadowFloating, { backgroundColor: theme.text }]}
        onPress={dismiss}
      >
        <Icon
          name={warning ? 'warning' : 'cloudDone'}
          size={20}
          color={warning ? theme.warning : theme.success}
        />
        <ThemedText type="label" style={[styles.text, { color: theme.background }]}>
          {message}
        </ThemedText>
      </Pressable>
    </Animated.View>
  );
}

const styles = StyleSheet.create({
  wrapper: { position: 'absolute', left: Spacing.three, right: Spacing.three },
  toast: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 10,
    borderRadius: Radii.md,
    paddingVertical: 14,
    paddingHorizontal: Spacing.three,
  },
  text: { flex: 1 },
});
