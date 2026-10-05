import { forwardRef, useState } from 'react';
import { Pressable, StyleSheet, TextInput, View, type TextInputProps } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Icon, type IconName } from '@/components/ui/Icon';
import { MinTouch, Radii, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

export type InputProps = TextInputProps & {
  label?: string;
  hint?: string;
  error?: string | null;
  icon?: IconName;
  /** Adds a show/hide toggle (implies secureTextEntry). */
  password?: boolean;
  optional?: boolean;
};

/** Labeled text input with hint, inline error, leading icon and focus ring. */
export const Input = forwardRef<TextInput, InputProps>(function Input(
  { label, hint, error, icon, password, optional, style, multiline, onFocus, onBlur, ...rest },
  ref,
) {
  const theme = useTheme();
  const [focused, setFocused] = useState(false);
  const [revealed, setRevealed] = useState(false);

  const borderColor = error ? theme.danger : focused ? theme.primary : theme.borderStrong;

  return (
    <View style={styles.wrapper}>
      {label ? (
        <ThemedText type="label" themeColor="textSecondary">
          {label}
          {optional ? (
            <ThemedText type="caption" themeColor="textMuted">
              {'  '}Optional
            </ThemedText>
          ) : null}
        </ThemedText>
      ) : null}
      <View
        style={[
          styles.field,
          multiline && styles.multilineField,
          { borderColor, backgroundColor: theme.surface },
          focused && styles.focused,
        ]}
      >
        {icon ? <Icon name={icon} size={18} color={theme.textMuted} /> : null}
        <TextInput
          ref={ref}
          placeholderTextColor={theme.textMuted}
          accessibilityLabel={label ?? rest.placeholder}
          accessibilityHint={error ?? hint}
          secureTextEntry={password ? !revealed : rest.secureTextEntry}
          multiline={multiline}
          onFocus={(event) => {
            setFocused(true);
            onFocus?.(event);
          }}
          onBlur={(event) => {
            setFocused(false);
            onBlur?.(event);
          }}
          style={[styles.input, multiline && styles.multiline, { color: theme.text }, style]}
          {...rest}
        />
        {password ? (
          <Pressable
            accessibilityRole="button"
            accessibilityLabel={revealed ? 'Hide password' : 'Show password'}
            hitSlop={10}
            onPress={() => setRevealed((value) => !value)}
          >
            <Icon name={revealed ? 'eyeOff' : 'eye'} size={20} color={theme.textMuted} />
          </Pressable>
        ) : null}
      </View>
      {error ? (
        <View style={styles.messageRow} accessibilityLiveRegion="polite">
          <Icon name="error" size={14} color={theme.danger} />
          <ThemedText type="small" style={[styles.message, { color: theme.danger }]}>
            {error}
          </ThemedText>
        </View>
      ) : hint ? (
        <ThemedText type="caption" themeColor="textMuted">
          {hint}
        </ThemedText>
      ) : null}
    </View>
  );
});

const styles = StyleSheet.create({
  wrapper: { gap: 6 },
  field: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
    borderWidth: 1,
    borderRadius: Radii.md,
    paddingHorizontal: 14,
    minHeight: MinTouch + 2,
  },
  multilineField: { alignItems: 'flex-start', paddingVertical: 10 },
  focused: { borderWidth: 1.5 },
  input: { flex: 1, fontSize: 16, paddingVertical: 12 },
  multiline: { minHeight: 84, textAlignVertical: 'top', paddingVertical: 2 },
  messageRow: { flexDirection: 'row', alignItems: 'center', gap: 4 },
  message: { flex: 1 },
});
