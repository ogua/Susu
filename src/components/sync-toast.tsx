import { useEffect } from 'react';
import { Pressable, StyleSheet } from 'react-native';

import { ThemedText } from './themed-text';

import { Spacing } from '@/constants/theme';
import { useSyncToastStore } from '@/stores/syncToastStore';

const AUTO_DISMISS_MS = 4000;

/** App-wide banner surfacing the outcome of every outbox drain, including the
 * silent background one triggered by reconnect — so an agent knows whether a
 * sync actually completed without having to open the Sync Queue screen. */
export function SyncToast() {
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

  return (
    <Pressable
      style={[styles.toast, tone === 'warning' ? styles.warning : styles.success]}
      onPress={dismiss}
    >
      <ThemedText type="smallBold" style={styles.text}>
        {message}
      </ThemedText>
    </Pressable>
  );
}

const styles = StyleSheet.create({
  toast: {
    position: 'absolute',
    left: Spacing.three,
    right: Spacing.three,
    bottom: Spacing.five,
    borderRadius: Spacing.two,
    paddingVertical: Spacing.three,
    paddingHorizontal: Spacing.three,
    elevation: 4,
    shadowColor: '#000000',
    shadowOpacity: 0.2,
    shadowRadius: 6,
    shadowOffset: { width: 0, height: 2 },
  },
  success: {
    backgroundColor: '#1a8a3d',
  },
  warning: {
    backgroundColor: '#b45309',
  },
  text: {
    color: '#ffffff',
  },
});
