import { Pressable, StyleSheet, View, type StyleProp, type ViewProps, type ViewStyle } from 'react-native';

import { Radii, Shadow, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type CardProps = ViewProps & {
  /** `muted` = flat tinted surface for secondary groupings. */
  tone?: 'default' | 'muted';
  padded?: boolean;
};

/** Elevated surface — the app's standard content container. */
export function Card({ style, tone = 'default', padded = true, ...rest }: CardProps) {
  const theme = useTheme();

  return (
    <View
      style={[
        styles.card,
        tone === 'default'
          ? [{ backgroundColor: theme.surface, borderColor: theme.border }, Shadow]
          : { backgroundColor: theme.surfaceMuted, borderColor: 'transparent' },
        !padded && styles.unpadded,
        style,
      ]}
      {...rest}
    />
  );
}

/** A Card that is itself a button (account cards, nav tiles). */
export function PressableCard({
  style,
  onPress,
  accessibilityLabel,
  accessibilityHint,
  children,
}: {
  style?: StyleProp<ViewStyle>;
  onPress: () => void;
  accessibilityLabel?: string;
  accessibilityHint?: string;
  children: React.ReactNode;
}) {
  const theme = useTheme();

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel}
      accessibilityHint={accessibilityHint}
      onPress={onPress}
      style={({ pressed }) => [
        styles.card,
        Shadow,
        { backgroundColor: pressed ? theme.surfaceMuted : theme.surface, borderColor: theme.border },
        style,
      ]}
    >
      {children}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  card: {
    borderRadius: Radii.lg,
    borderWidth: StyleSheet.hairlineWidth,
    padding: Spacing.three,
    gap: Spacing.two,
  },
  unpadded: { padding: 0 },
});
