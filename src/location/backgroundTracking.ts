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

export type TrackingStartResult = 'started' | 'no-permission' | 'failed';

/**
 * ~60s fixes (AD-11). Only foreground ("while using the app") permission is
 * required: on Android the task runs as a user-visible foreground service,
 * which does not need "Allow all the time". Background permission is still
 * requested so iOS keeps tracking when the app is minimised, but refusing it
 * must not stop tracking — Android 11+ refuses it unless the agent goes to
 * Settings, which previously left every such agent off the map.
 */
export async function startBackgroundLocationTracking(): Promise<TrackingStartResult> {
  try {
    const foreground = await Location.requestForegroundPermissionsAsync();
    if (foreground.status !== 'granted') {
      return 'no-permission';
    }

    await Location.requestBackgroundPermissionsAsync().catch(() => undefined);

    if (await Location.hasStartedLocationUpdatesAsync(LOCATION_TRACKING_TASK)) {
      return 'started';
    }

    await Location.startLocationUpdatesAsync(LOCATION_TRACKING_TASK, {
      accuracy: Location.Accuracy.Balanced,
      timeInterval: 60_000,
      // No distance filter: an agent standing at one market stall must keep
      // pinging, or the map marks them "no recent signal" after 15 minutes
      // and the route view can't detect stops.
      distanceInterval: 0,
      showsBackgroundLocationIndicator: true,
      foregroundService: {
        notificationTitle: 'SusuApp — on duty',
        notificationBody: 'Recording your route while you are on duty.',
      },
    });

    return 'started';
  } catch {
    return 'failed';
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
