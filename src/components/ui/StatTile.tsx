import { StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { Card } from '@/components/ui/Card';

type StatTileProps = {
  label: string;
  value: string;
  hint?: string;
  accentColor?: string;
};

/** Dashboard stat: muted label over a prominent value, optional hint line. */
export function StatTile({ label, value, hint, accentColor }: StatTileProps) {
  return (
    <Card style={styles.tile}>
      <ThemedText type="small" themeColor="textSecondary">
        {label}
      </ThemedText>
      <ThemedText type="subtitle" style={accentColor ? { color: accentColor } : undefined}>
        {value}
      </ThemedText>
      {hint ? (
        <ThemedText type="small" themeColor="textSecondary">
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
  tile: { flex: 1, minWidth: 140 },
  row: { flexDirection: 'row', gap: 12 },
});
