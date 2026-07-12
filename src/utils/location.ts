import * as Location from 'expo-location';

/**
 * Best-effort GPS fix. Never throws and never blocks the caller — location on
 * a collection is a nice-to-have for field verification, not a requirement
 * (StoreCollectionRequest treats latitude/longitude as nullable).
 */
export async function getCurrentPositionSafe(): Promise<{ latitude: number; longitude: number } | null> {
  try {
    const { status } = await Location.requestForegroundPermissionsAsync();
    if (status !== 'granted') {
      return null;
    }

    const position = await Location.getCurrentPositionAsync({
      accuracy: Location.Accuracy.Balanced,
    });

    return { latitude: position.coords.latitude, longitude: position.coords.longitude };
  } catch {
    return null;
  }
}
