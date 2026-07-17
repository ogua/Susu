import { StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';

type EmptyStateProps = {
  title: string;
  hint?: string;
};

/** Friendly empty/error placeholder instead of a bare grey sentence. */
export function EmptyState({ title, hint }: EmptyStateProps) {
  return (
    <View style={styles.wrapper}>
      <ThemedText type="smallBold" themeColor="textSecondary" style={styles.center}>
        {title}
      </ThemedText>
      {hint ? (
        <ThemedText type="small" themeColor="textSecondary" style={styles.center}>
          {hint}
        </ThemedText>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrapper: { alignItems: 'center', paddingVertical: 32, gap: 4 },
  center: { textAlign: 'center' },
});
