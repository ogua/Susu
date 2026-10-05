import { StyleSheet, View } from 'react-native';

import { Radii } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type ProgressBarProps = {
  /** 0–1; values outside the range are clamped. */
  progress: number;
  color?: string;
  trackColor?: string;
  accessibilityLabel?: string;
};

export function ProgressBar({ progress, color, trackColor, accessibilityLabel }: ProgressBarProps) {
  const theme = useTheme();
  const clamped = Math.max(0, Math.min(Number.isFinite(progress) ? progress : 0, 1));

  return (
    <View
      accessibilityRole="progressbar"
      accessibilityLabel={accessibilityLabel}
      accessibilityValue={{ min: 0, max: 100, now: Math.round(clamped * 100) }}
      style={[styles.track, { backgroundColor: trackColor ?? theme.backgroundSelected }]}
    >
      <View style={[styles.fill, { width: `${clamped * 100}%`, backgroundColor: color ?? theme.primary }]} />
    </View>
  );
}

const styles = StyleSheet.create({
  track: {
    height: 8,
    borderRadius: Radii.pill,
    overflow: 'hidden',
  },
  fill: { height: '100%', borderRadius: Radii.pill },
});
