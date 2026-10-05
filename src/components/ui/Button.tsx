import {
  ActivityIndicator,
  Pressable,
  StyleSheet,
  View,
  type PressableProps,
  type StyleProp,
  type ViewStyle,
} from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Icon, type IconName } from '@/components/ui/Icon';
import { MinTouch, Radii, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type Variant = 'primary' | 'secondary' | 'outline' | 'destructive' | 'ghost' | 'success';
type Size = 'md' | 'lg';

type ButtonProps = Omit<PressableProps, 'children' | 'style'> & {
  title: string;
  variant?: Variant;
  size?: Size;
  loading?: boolean;
  /** Shown instead of `title` while `loading` (e.g. "Saving…"). */
  loadingTitle?: string;
  icon?: IconName;
  style?: StyleProp<ViewStyle>;
};

/**
 * The app's only button. `loading` disables presses, so financial submits
 * can't be double-tapped while in flight.
 */
export function Button({
  title,
  variant = 'primary',
  size = 'md',
  loading,
  loadingTitle,
  icon,
  disabled,
  style,
  accessibilityLabel,
  ...rest
}: ButtonProps) {
  const theme = useTheme();
  const inactive = disabled || loading;

  const palette = {
    primary: { bg: theme.primary, pressed: theme.primaryPressed, fg: theme.onPrimary, border: 'transparent' },
    secondary: { bg: theme.primarySoft, pressed: theme.backgroundSelected, fg: theme.primaryText, border: 'transparent' },
    outline: { bg: 'transparent', pressed: theme.surfaceMuted, fg: theme.text, border: theme.borderStrong },
    destructive: { bg: theme.dangerStrong, pressed: theme.dangerStrong, fg: '#FFFFFF', border: 'transparent' },
    success: { bg: theme.success, pressed: theme.success, fg: '#FFFFFF', border: 'transparent' },
    ghost: { bg: 'transparent', pressed: theme.surfaceMuted, fg: theme.textSecondary, border: 'transparent' },
  }[variant];

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel ?? title}
      accessibilityState={{ disabled: !!inactive, busy: !!loading }}
      disabled={inactive}
      style={({ pressed }) => [
        styles.button,
        size === 'lg' && styles.large,
        { backgroundColor: pressed ? palette.pressed : palette.bg, borderColor: palette.border },
        variant === 'outline' && styles.outlined,
        inactive && styles.dimmed,
        pressed && styles.pressed,
        style,
      ]}
      {...rest}
    >
      <View style={styles.content}>
        {loading ? (
          <ActivityIndicator color={palette.fg} />
        ) : icon ? (
          <Icon name={icon} size={size === 'lg' ? 20 : 18} color={palette.fg} />
        ) : null}
        <ThemedText type="label" style={[styles.label, size === 'lg' && styles.labelLarge, { color: palette.fg }]}>
          {loading && loadingTitle ? loadingTitle : title}
        </ThemedText>
      </View>
    </Pressable>
  );
}

/** Small round icon-only button (header actions). Always labelled for screen readers. */
export function IconButton({
  icon,
  label,
  onPress,
  color,
  size = 22,
}: {
  icon: IconName;
  label: string;
  onPress: () => void;
  color?: string;
  size?: number;
}) {
  const theme = useTheme();

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={label}
      hitSlop={8}
      onPress={onPress}
      style={({ pressed }) => [styles.iconButton, pressed && { backgroundColor: theme.surfaceMuted }]}
    >
      <Icon name={icon} size={size} color={color ?? theme.text} />
    </Pressable>
  );
}

const styles = StyleSheet.create({
  button: {
    borderRadius: Radii.md,
    paddingVertical: 12,
    paddingHorizontal: Spacing.three,
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: MinTouch,
    borderWidth: 0,
  },
  large: { minHeight: 56, borderRadius: Radii.lg },
  outlined: { borderWidth: 1 },
  content: { flexDirection: 'row', alignItems: 'center', gap: Spacing.two },
  dimmed: { opacity: 0.55 },
  pressed: { transform: [{ scale: 0.99 }] },
  label: { textAlign: 'center' },
  labelLarge: { fontSize: 16 },
  iconButton: {
    width: 40,
    height: 40,
    borderRadius: Radii.pill,
    alignItems: 'center',
    justifyContent: 'center',
  },
});
