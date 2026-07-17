import { Pressable, StyleSheet, View } from 'react-native';

import { ThemedText } from '@/components/themed-text';
import { useTheme } from '@/hooks/use-theme';

type ListRowProps = {
  title: string;
  subtitle?: string;
  value?: string;
  subvalue?: string;
  onPress?: () => void;
  right?: React.ReactNode;
};

/** Standard list row: title/subtitle left, value/subvalue (or custom node) right. */
export function ListRow({ title, subtitle, value, subvalue, onPress, right }: ListRowProps) {
  const theme = useTheme();

  const content = (
    <>
      <View style={styles.left}>
        <ThemedText type="smallBold">{title}</ThemedText>
        {subtitle ? (
          <ThemedText type="small" themeColor="textSecondary">
            {subtitle}
          </ThemedText>
        ) : null}
      </View>
      {right ?? (
        <View style={styles.right}>
          {value ? <ThemedText>{value}</ThemedText> : null}
          {subvalue ? (
            <ThemedText type="small" themeColor="textSecondary">
              {subvalue}
            </ThemedText>
          ) : null}
        </View>
      )}
    </>
  );

  if (!onPress) {
    return <View style={[styles.row, { borderBottomColor: theme.border }]}>{content}</View>;
  }

  return (
    <Pressable
      onPress={onPress}
      style={(state) => [
        styles.row,
        { borderBottomColor: theme.border },
        state.pressed && { backgroundColor: theme.backgroundSelected },
      ]}
    >
      {content}
    </Pressable>
  );
}

const styles = StyleSheet.create({
  row: {
    flexDirection: 'row',
    justifyContent: 'space-between',
    alignItems: 'center',
    paddingVertical: 12,
    paddingHorizontal: 4,
    borderBottomWidth: StyleSheet.hairlineWidth,
    gap: 12,
  },
  left: { flex: 1, gap: 2 },
  right: { alignItems: 'flex-end', gap: 2 },
});
