import { useEffect } from 'react';
import { StyleSheet, View, type DimensionValue, type StyleProp, type ViewStyle } from 'react-native';
import Animated, {
  cancelAnimation,
  useAnimatedStyle,
  useSharedValue,
  withRepeat,
  withTiming,
} from 'react-native-reanimated';

import { Radii, Spacing } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

/** Pulsing placeholder block shown while content loads. */
export function Skeleton({
  width = '100%',
  height = 14,
  radius = Radii.sm,
  style,
}: {
  width?: DimensionValue;
  height?: number;
  radius?: number;
  style?: StyleProp<ViewStyle>;
}) {
  const theme = useTheme();
  const opacity = useSharedValue(0.5);

  useEffect(() => {
    opacity.value = withRepeat(withTiming(1, { duration: 700 }), -1, true);

    return () => cancelAnimation(opacity);
  }, [opacity]);

  const animated = useAnimatedStyle(() => ({ opacity: opacity.value }));

  return (
    <Animated.View
      style={[{ width, height, borderRadius: radius, backgroundColor: theme.backgroundSelected }, animated, style]}
    />
  );
}

/** Placeholder for a list of rows (avatar + two lines + value). */
export function SkeletonList({ rows = 6 }: { rows?: number }) {
  const theme = useTheme();

  return (
    <View accessibilityRole="progressbar" accessibilityLabel="Loading">
      {Array.from({ length: rows }, (_, index) => (
        <View key={index} style={[styles.row, { borderBottomColor: theme.border }]}>
          <Skeleton width={40} height={40} radius={Radii.pill} />
          <View style={styles.lines}>
            <Skeleton width="60%" height={14} />
            <Skeleton width="40%" height={11} />
          </View>
          <Skeleton width={72} height={16} />
        </View>
      ))}
    </View>
  );
}

/** Placeholder matching the gradient HeroCard's footprint. */
export function SkeletonHero() {
  return (
    <View accessibilityRole="progressbar" accessibilityLabel="Loading" style={styles.hero}>
      <Skeleton width="45%" height={14} />
      <Skeleton width="70%" height={36} radius={Radii.md} />
      <View style={styles.heroStats}>
        <Skeleton height={48} radius={Radii.md} style={styles.flex} />
        <Skeleton height={48} radius={Radii.md} style={styles.flex} />
        <Skeleton height={48} radius={Radii.md} style={styles.flex} />
      </View>
    </View>
  );
}

const styles = StyleSheet.create({
  flex: { flex: 1 },
  row: {
    flexDirection: 'row',
    alignItems: 'center',
    gap: 12,
    paddingHorizontal: Spacing.three,
    paddingVertical: 14,
    borderBottomWidth: StyleSheet.hairlineWidth,
  },
  lines: { flex: 1, gap: 8 },
  hero: { gap: Spacing.three, paddingVertical: Spacing.two },
  heroStats: { flexDirection: 'row', gap: Spacing.two },
});
