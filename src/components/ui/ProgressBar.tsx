import { StyleSheet, View } from 'react-native';

import { Palette, Radii } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';

type ProgressBarProps = {
  /** 0–1; values outside the range are clamped. */
  progress: number;
  color?: string;
};

export function ProgressBar({ progress, color = Palette.primary500 }: ProgressBarProps) {
  const theme = useTheme();
  const clamped = Math.max(0, Math.min(progress, 1));

  return (
    <View style={[styles.track, { backgroundColor: theme.backgroundSelected }]}>
      <View style={[styles.fill, { width: `${clamped * 100}%`, backgroundColor: color }]} />
    </View>
  );
}

const styles = StyleSheet.create({
  track: {
    height: 6,
    borderRadius: Radii.pill,
    overflow: 'hidden',
    marginTop: 6,
  },
  fill: { height: '100%' },
});
