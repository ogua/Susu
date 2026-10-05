import { Pressable, StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Icon, type IconName } from '@/components/ui/Icon';
import { Radii, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

export type Option<T extends string> = { value: T; label: string; icon?: IconName; description?: string };

/** 2–3 mutually exclusive options in one row (payment method, client type). */
export function SegmentedControl<T extends string>({
  options,
  value,
  onChange,
  accessibilityLabel,
}: {
  options: Option<T>[];
  value: T | '' | null;
  onChange: (value: T) => void;
  accessibilityLabel?: string;
}) {
  const theme = useTheme();

  return (
    <View
      accessibilityRole="radiogroup"
      accessibilityLabel={accessibilityLabel}
      style={[styles.segmented, { backgroundColor: theme.surfaceMuted }]}
    >
      {options.map((option) => {
        const active = option.value === value;

        return (
          <Pressable
            key={option.value}
            accessibilityRole="radio"
            accessibilityState={{ selected: active, checked: active }}
            accessibilityLabel={option.label}
            onPress={() => onChange(option.value)}
            style={[styles.segment, active && [styles.segmentActive, { backgroundColor: theme.surface }]]}
          >
            {option.icon ? (
              <Icon name={option.icon} size={16} color={active ? theme.primaryText : theme.textSecondary} />
            ) : null}
            <ThemedText type="label" style={{ color: active ? theme.primaryText : theme.textSecondary }}>
              {option.label}
            </ThemedText>
          </Pressable>
        );
      })}
    </View>
  );
}

/** Wrapping single-select chips for longer option lists (ID type, product). */
export function ChipSelect<T extends string>({
  options,
  value,
  onChange,
  accessibilityLabel,
}: {
  options: Option<T>[];
  value: T | '' | null;
  onChange: (value: T) => void;
  accessibilityLabel?: string;
}) {
  const theme = useTheme();

  return (
    <View accessibilityRole="radiogroup" accessibilityLabel={accessibilityLabel} style={styles.chips}>
      {options.map((option) => {
        const active = option.value === value;

        return (
          <Pressable
            key={option.value}
            accessibilityRole="radio"
            accessibilityState={{ selected: active, checked: active }}
            accessibilityLabel={option.description ? `${option.label}, ${option.description}` : option.label}
            onPress={() => onChange(option.value)}
            style={[
              styles.chip,
              {
                backgroundColor: active ? theme.primarySoft : theme.surface,
                borderColor: active ? theme.primary : theme.borderStrong,
              },
            ]}
          >
            {active ? <Icon name="checkCircle" size={16} color={theme.primaryText} /> : null}
            <View>
              <ThemedText type="label" style={{ color: active ? theme.primaryText : theme.text }}>
                {option.label}
              </ThemedText>
              {option.description ? (
                <ThemedText type="caption" themeColor="textMuted">
                  {option.description}
                </ThemedText>
              ) : null}
            </View>
          </Pressable>
        );
      })}
    </View>
  );
}

/** Labelled field wrapper for selectors (matches Input's label style). */
export function Field({ label, optional, children }: { label: string; optional?: boolean; children: React.ReactNode }) {
  return (
    <View style={styles.field}>
      <ThemedText type="label" themeColor="textSecondary">
        {label}
        {optional ? (
          <ThemedText type="caption" themeColor="textMuted">
            {'  '}Optional
          </ThemedText>
        ) : null}
      </ThemedText>
      {children}
    </View>
  );
}

const styles = StyleSheet.create({
  segmented: { flexDirection: 'row', borderRadius: Radii.md, padding: 4, gap: 4 },
  segment: {
    flex: 1,
    flexDirection: 'row',
    gap: 6,
    alignItems: 'center',
    justifyContent: 'center',
    minHeight: 44,
    borderRadius: Radii.sm,
  },
  segmentActive: {
    shadowColor: '#0f172a',
    shadowOpacity: 0.08,
    shadowRadius: 4,
    shadowOffset: { width: 0, height: 1 },
    elevation: 1,
  },
  chips: { flexDirection: 'row', flexWrap: 'wrap', gap: Spacing.two },
  chip: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 6,
    borderWidth: 1,
    borderRadius: Radii.md,
    paddingVertical: 10,
    paddingHorizontal: 14,
    minHeight: 44,
  },
  field: { gap: 8 },
});
