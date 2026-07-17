import { ScrollView, StyleSheet, View, type ScrollViewProps } from 'react-native';

import { Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type ScreenProps = ScrollViewProps & {
  /** Non-scrolling screens (FlatList owners) render a plain View instead. */
  scroll?: boolean;
};

/** Standard screen chrome: app background + consistent padding/gap. */
export function Screen({ scroll = true, style, contentContainerStyle, children, ...rest }: ScreenProps) {
  const theme = useTheme();

  if (!scroll) {
    return (
      <View style={[styles.static, { backgroundColor: theme.background }, style]}>{children}</View>
    );
  }

  return (
    <ScrollView
      style={[{ backgroundColor: theme.background }, style]}
      contentContainerStyle={[styles.content, contentContainerStyle]}
      {...rest}
    >
      {children}
    </ScrollView>
  );
}

const styles = StyleSheet.create({
  content: { padding: Spacing.three, gap: Spacing.three },
  static: { flex: 1, padding: Spacing.three, gap: Spacing.three },
});
