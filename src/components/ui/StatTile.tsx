import { StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Card } from '@/components/ui/Card';
import { Icon, type IconName } from '@/components/ui/Icon';
import { Radii, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type StatTileProps = {
  label: string;
  value: string;
  hint?: string;
  icon?: IconName;
  accentColor?: string;
};

/** Dashboard stat: icon + muted label over a prominent tabular value. */
export function StatTile({ label, value, hint, icon, accentColor }: StatTileProps) {
  const theme = useTheme();
  const accent = accentColor ?? theme.primaryText;

  return (
    <Card style={styles.tile} accessible accessibilityLabel={`${label}: ${value}${hint ? `, ${hint}` : ''}`}>
      <View style={styles.header}>
        {icon ? (
          <View style={[styles.icon, { backgroundColor: theme.surfaceMuted }]}>
            <Icon name={icon} size={16} color={accent} />
          </View>
        ) : null}
        <ThemedText type="caption" themeColor="textSecondary" style={styles.label} numberOfLines={2}>
          {label}
        </ThemedText>
      </View>
      <ThemedText type="moneyLarge" style={{ fontSize: 20, lineHeight: 26 }} adjustsFontSizeToFit numberOfLines={1}>
        {value}
      </ThemedText>
      {hint ? (
        <ThemedText type="caption" themeColor="textMuted">
          {hint}
        </ThemedText>
      ) : null}
    </Card>
  );
}

/** Lays StatTiles out two-up. */
export function StatTileRow({ children }: { children: React.ReactNode }) {
  return <View style={styles.row}>{children}</View>;
}

const styles = StyleSheet.create({
  tile: { flex: 1, minWidth: 140, gap: 6 },
  header: { flexDirection: 'row', alignItems: 'center', gap: Spacing.two },
  icon: { width: 28, height: 28, borderRadius: Radii.sm, alignItems: 'center', justifyContent: 'center' },
  label: { flex: 1 },
  row: { flexDirection: 'row', gap: 12 },
});
