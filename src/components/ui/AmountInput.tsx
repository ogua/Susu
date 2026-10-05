import { forwardRef, useState } from 'react';
import { Pressable, StyleSheet, TextInput, View, type TextInputProps } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Icon } from '@/components/ui/Icon';
import { Radii, Spacing, Type } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { formatMoney, minorToInput, sanitizeAmountInput } from '@/utils/money';

type AmountInputProps = Omit<TextInputProps, 'value' | 'onChangeText' | 'keyboardType'> & {
  label?: string;
  /** Raw decimal string in cedis ("12.50"); parse with parseAmountToMinor. */
  value: string;
  onChangeText: (value: string) => void;
  error?: string | null;
  hint?: string;
  /** One-tap amounts in pesewas, e.g. the account's agreed contribution. */
  quickAmounts?: number[];
  size?: 'md' | 'lg';
};

/**
 * Money entry: fixed GH₵ prefix, decimal pad, input sanitised on every
 * keystroke (one dot, two decimals, no leading zeros, no negatives), large
 * tabular digits so the agent and customer can both read the figure.
 */
export const AmountInput = forwardRef<TextInput, AmountInputProps>(function AmountInput(
  { label = 'Amount', value, onChangeText, error, hint, quickAmounts, size = 'lg', style, ...rest },
  ref,
) {
  const theme = useTheme();
  const [focused, setFocused] = useState(false);
  const large = size === 'lg';

  return (
    <View style={styles.wrapper}>
      <ThemedText type="label" themeColor="textSecondary">
        {label}
      </ThemedText>
      <View
        style={[
          styles.field,
          large && styles.fieldLarge,
          {
            backgroundColor: theme.surface,
            borderColor: error ? theme.danger : focused ? theme.primary : theme.borderStrong,
            borderWidth: focused || error ? 1.5 : 1,
          },
        ]}
      >
        <ThemedText
          style={[large ? Type.moneyLarge : Type.money, { color: theme.textMuted }]}
          accessibilityElementsHidden
          importantForAccessibility="no"
        >
          GH₵
        </ThemedText>
        <TextInput
          ref={ref}
          value={value}
          onChangeText={(text) => onChangeText(sanitizeAmountInput(text))}
          keyboardType="decimal-pad"
          inputMode="decimal"
          placeholder="0.00"
          placeholderTextColor={theme.textMuted}
          accessibilityLabel={`${label} in Ghana cedis`}
          accessibilityHint={error ?? hint}
          onFocus={() => setFocused(true)}
          onBlur={() => setFocused(false)}
          style={[
            styles.input,
            large ? styles.inputLarge : Type.money,
            { color: theme.text },
            style,
          ]}
          {...rest}
        />
      </View>

      {quickAmounts && quickAmounts.length > 0 ? (
        <View style={styles.quickRow}>
          {quickAmounts.map((minor) => {
            const active = value === minorToInput(minor);

            return (
              <Pressable
                key={minor}
                accessibilityRole="button"
                accessibilityState={{ selected: active }}
                accessibilityLabel={`Use ${formatMoney(minor)}`}
                onPress={() => onChangeText(minorToInput(minor))}
                style={[
                  styles.quick,
                  {
                    backgroundColor: active ? theme.primarySoft : theme.surfaceMuted,
                    borderColor: active ? theme.primary : 'transparent',
                  },
                ]}
              >
                <ThemedText type="label" style={{ color: active ? theme.primaryText : theme.textSecondary }}>
                  {formatMoney(minor)}
                </ThemedText>
              </Pressable>
            );
          })}
        </View>
      ) : null}

      {error ? (
        <View style={styles.messageRow} accessibilityLiveRegion="polite">
          <Icon name="error" size={14} color={theme.danger} />
          <ThemedText type="small" style={{ color: theme.danger, flex: 1 }}>
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
  wrapper: { gap: 8 },
  field: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: Spacing.two,
    borderRadius: Radii.md,
    paddingHorizontal: Spacing.three,
    minHeight: 52,
  },
  fieldLarge: { minHeight: 68, borderRadius: Radii.lg },
  input: { flex: 1, paddingVertical: 10 },
  inputLarge: { ...Type.moneyLarge, fontSize: 30, lineHeight: 36 },
  quickRow: { flexDirection: 'row', flexWrap: 'wrap', gap: Spacing.two },
  quick: {
    borderRadius: Radii.pill,
    borderWidth: 1,
    paddingHorizontal: 14,
    paddingVertical: 8,
    minHeight: 36,
    justifyContent: 'center',
  },
  messageRow: { flexDirection: 'row', alignItems: 'center', gap: 4 },
});
