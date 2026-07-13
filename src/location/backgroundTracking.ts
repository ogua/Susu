import * as Location from 'expo-location';
import * as TaskManager from 'expo-task-manager';

import { drainOutbox } from '@/sync/engine';
import { enqueueLocationPings } from '@/sync/ops';

/**
 * Background GPS tracking for on-duty agents (AD-11 — the Uber-style admin
 * map needs real pings to show anything). expo-task-manager tasks must be
 * defined at module load time, not inside a component, so this file is
 * imported once for its side effect from the root layout.
 */
export const LOCATION_TRACKING_TASK = 'susu-agent-location-tracking';

TaskManager.defineTask(LOCATION_TRACKING_TASK, async ({ data, error }) => {
  if (error) {
    return;
  }

  const { locations } = (data ?? {}) as { locations?: Location.LocationObject[] };
  if (!locations || locations.length === 0) {
    return;
  }

  const pings = locations.map((location) => ({
    latitude: location.coords.latitude,
    longitude: location.coords.longitude,
    accuracy: location.coords.accuracy ?? undefined,
    recorded_at: new Date(location.timestamp).toISOString(),
  }));

  try {
    await enqueueLocationPings(pings);
    void drainOutbox();
  } catch {
    // Best-effort: if the local write fails the fix is simply lost — a
    // background task must never throw back into the OS scheduler.
  }
});

/** ~60s / 100m fixes, matching AD-11. Requires "always" location permission. */
export async function startBackgroundLocationTracking(): Promise<boolean> {
  try {
    const foreground = await Location.requestForegroundPermissionsAsync();
    if (foreground.status !== 'granted') {
      return false;
    }

    const background = await Location.requestBackgroundPermissionsAsync();
    if (background.status !== 'granted') {
      return false;
    }

    if (await Location.hasStartedLocationUpdatesAsync(LOCATION_TRACKING_TASK)) {
      return true;
    }

    await Location.startLocationUpdatesAsync(LOCATION_TRACKING_TASK, {
      accuracy: Location.Accuracy.Balanced,
      timeInterval: 60_000,
      distanceInterval: 100,
      showsBackgroundLocationIndicator: true,
      foregroundService: {
        notificationTitle: 'SusuApp — on duty',
        notificationBody: 'Recording your route while you are on duty.',
      },
    });

    return true;
  } catch {
    return false;
  }
}

export async function stopBackgroundLocationTracking(): Promise<void> {
  try {
    if (await Location.hasStartedLocationUpdatesAsync(LOCATION_TRACKING_TASK)) {
      await Location.stopLocationUpdatesAsync(LOCATION_TRACKING_TASK);
    }
  } catch {
    // Best-effort — nothing sensible to do if the OS refuses to stop it.
  }
}

export async function isBackgroundLocationTracking(): Promise<boolean> {
  try {
    return await Location.hasStartedLocationUpdatesAsync(LOCATION_TRACKING_TASK);
  } catch {
    return false;
  }
}
