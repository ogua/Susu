import { Pressable, StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Icon, type IconName } from '@/components/ui/Icon';
import { MinTouch, Radii, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import { initials } from '@/utils/format';

type ListRowProps = {
  title: string;
  subtitle?: string;
  /** Right-aligned primary value (usually money — rendered tabular). */
  value?: string;
  valueLabel?: string;
  subvalue?: string;
  onPress?: () => void;
  right?: React.ReactNode;
  left?: React.ReactNode;
  icon?: IconName;
  chevron?: boolean;
  accessibilityLabel?: string;
  divider?: boolean;
};

/** Standard list row: leading visual, title/subtitle, value/subvalue, chevron. */
export function ListRow({
  title,
  subtitle,
  value,
  valueLabel,
  subvalue,
  onPress,
  right,
  left,
  icon,
  chevron = !!onPress,
  accessibilityLabel,
  divider = true,
}: ListRowProps) {
  const theme = useTheme();

  const content = (
    <>
      {left ??
        (icon ? (
          <View style={[styles.leading, { backgroundColor: theme.surfaceMuted }]}>
            <Icon name={icon} size={20} color={theme.textSecondary} />
          </View>
        ) : null)}
      <View style={styles.middle}>
        <ThemedText type="bodyStrong" numberOfLines={1}>
          {title}
        </ThemedText>
        {subtitle ? (
          <ThemedText type="small" themeColor="textSecondary" numberOfLines={2}>
            {subtitle}
          </ThemedText>
        ) : null}
      </View>
      {right ??
        (value || subvalue ? (
          <View style={styles.right}>
            {valueLabel ? (
              <ThemedText type="caption" themeColor="textMuted">
                {valueLabel}
              </ThemedText>
            ) : null}
            {value ? <ThemedText type="money">{value}</ThemedText> : null}
            {subvalue ? (
              <ThemedText type="caption" themeColor="textMuted">
                {subvalue}
              </ThemedText>
            ) : null}
          </View>
        ) : null)}
      {chevron ? <Icon name="chevronRight" size={18} color={theme.textMuted} /> : null}
    </>
  );

  const rowStyle = [styles.row, divider && { borderBottomColor: theme.border, borderBottomWidth: StyleSheet.hairlineWidth }];

  if (!onPress) {
    return (
      <View style={rowStyle} accessible={!!accessibilityLabel} accessibilityLabel={accessibilityLabel}>
        {content}
      </View>
    );
  }

  return (
    <Pressable
      accessibilityRole="button"
      accessibilityLabel={accessibilityLabel}
      onPress={onPress}
      style={({ pressed }) => [rowStyle, pressed && { backgroundColor: theme.surfaceMuted }]}
    >
      {content}
    </Pressable>
  );
}

export function Avatar({ name, size = 40 }: { name: string; size?: number }) {
  const theme = useTheme();

  return (
    <View
      style={[
        styles.avatar,
        { width: size, height: size, borderRadius: size / 2, backgroundColor: theme.primarySoft },
      ]}
      accessibilityElementsHidden
      importantForAccessibility="no-hide-descendants"
    >
      <ThemedText type="label" style={{ color: theme.primaryText, fontSize: size * 0.36 }}>
        {initials(name)}
      </ThemedText>
    </View>
  );
}

export function SectionHeader({ title, action }: { title: string; action?: React.ReactNode }) {
  return (
    <View style={styles.section} accessibilityRole="header">
      <ThemedText type="label" themeColor="textSecondary" style={styles.sectionTitle}>
        {title}
      </ThemedText>
      {action}
    </View>
  );
}

/** Label/value line for summaries and receipts. */
export function KeyValueRow({
  label,
  value,
  emphasis,
  valueColor,
}: {
  label: string;
  value: string;
  emphasis?: boolean;
  valueColor?: string;
}) {
  return (
    <View style={styles.kv} accessible accessibilityLabel={`${label}: ${value}`}>
      <ThemedText type="small" themeColor="textSecondary" style={styles.kvLabel}>
        {label}
      </ThemedText>
      <ThemedText
        type={emphasis ? 'money' : 'small'}
        style={[styles.kvValue, !emphasis && { fontWeight: '600' }, valueColor ? { color: valueColor } : null]}
      >
        {value}
      </ThemedText>
    </View>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    paddingVertical: 12,
    paddingHorizontal: Spacing.three,
    gap: 12,
    minHeight: MinTouch + 16,
  },
  leading: {
    width: 40,
    height: 40,
    borderRadius: Radii.md,
    alignItems: 'center',
    justifyContent: 'center',
  },
  middle: { flex: 1, gap: 2 },
  right: { alignItems: 'flex-end', gap: 1 },
  avatar: { alignItems: 'center', justifyContent: 'center' },
  section: {
    flexDirection: 'row',
    alignItems: 'center',
    justifyContent: 'space-between',
    marginTop: Spacing.two,
  },
  sectionTitle: { textTransform: 'uppercase', letterSpacing: 0.6, fontSize: 12 },
  kv: { flexDirection: 'row', justifyContent: 'space-between', alignItems: 'center', gap: Spacing.three, paddingVertical: 6 },
  kvLabel: { flexShrink: 0 },
  kvValue: { flex: 1, textAlign: 'right' },
});
