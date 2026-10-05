import { useHeaderHeight } from 'expo-router/react-navigation';
import {
  KeyboardAvoidingView,
  Platform,
  ScrollView,
  StyleSheet,
  View,
  type ScrollViewProps,
  type StyleProp,
  type ViewStyle,
} from 'react-native';
import { useSafeAreaInsets } from 'react-native-safe-area-context';

import { ShadowFloating, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type ScreenProps = ScrollViewProps & {
  /** Non-scrolling screens (FlatList owners) render a plain View instead. */
  scroll?: boolean;
  /**
   * Pinned bottom action area (primary submit). Sits above the keyboard and
   * the home indicator so the main action is always reachable.
   */
  footer?: React.ReactNode;
  /** Set false for edge-to-edge content (e.g. a full-bleed list). */
  padded?: boolean;
  style?: StyleProp<ViewStyle>;
};

/**
 * Standard screen chrome — the app's single safe-area + keyboard strategy:
 *  - top inset is owned by the native stack header;
 *  - bottom inset (gesture bar / home indicator) is added to the content or
 *    the footer, never hard-coded;
 *  - KeyboardAvoidingView (iOS `padding`, offset by the header height) keeps
 *    the footer above the keyboard; on Android edge-to-edge the window
 *    resizes and KAV with no behaviour is enough (Expo keyboard guide);
 *  - `keyboardShouldPersistTaps="handled"` so the first tap on a button or
 *    search result acts instead of only dismissing the keyboard.
 */
export function Screen({
  scroll = true,
  footer,
  padded = true,
  style,
  contentContainerStyle,
  children,
  ...rest
}: ScreenProps) {
  const theme = useTheme();
  const insets = useSafeAreaInsets();
  const headerHeight = useHeaderHeight();

  const bottomPad = footer ? Spacing.three : Spacing.three + insets.bottom;

  const body = scroll ? (
    <ScrollView
      style={styles.flex}
      contentContainerStyle={[
        padded && styles.content,
        { paddingBottom: bottomPad },
        contentContainerStyle,
      ]}
      keyboardShouldPersistTaps="handled"
      keyboardDismissMode={Platform.OS === 'ios' ? 'interactive' : 'on-drag'}
      showsVerticalScrollIndicator={false}
      {...rest}
    >
      {children}
    </ScrollView>
  ) : (
    <View style={[styles.flex, padded && styles.staticContent, !footer && { paddingBottom: 0 }]}>
      {children}
    </View>
  );

  return (
    <KeyboardAvoidingView
      style={[styles.flex, { backgroundColor: theme.background }, style]}
      behavior={Platform.OS === 'ios' ? 'padding' : undefined}
      keyboardVerticalOffset={headerHeight}
    >
      {body}
      {footer ? (
        <View
          style={[
            styles.footer,
            ShadowFloating,
            {
              backgroundColor: theme.surface,
              borderTopColor: theme.border,
              paddingBottom: Spacing.three + insets.bottom,
            },
          ]}
        >
          {footer}
        </View>
      ) : null}
    </KeyboardAvoidingView>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  content: { padding: Spacing.three, gap: Spacing.three },
  staticContent: { paddingHorizontal: Spacing.three, paddingTop: Spacing.three, gap: Spacing.three },
  footer: {
    paddingHorizontal: Spacing.three,
    paddingTop: Spacing.three,
    gap: Spacing.two,
    borderTopWidth: StyleSheet.hairlineWidth,
  },
});
