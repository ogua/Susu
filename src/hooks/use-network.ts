import * as Network from 'expo-network';
import { useEffect, useState } from 'react';

/**
 * Live connectivity. `isInternetReachable` can be null while Android probes;
 * treat "unknown" as online so we never flash an offline banner on launch.
 */
export function useOnline(): boolean {
  const [online, setOnline] = useState(true);

  useEffect(() => {
    let mounted = true;

    void Network.getNetworkStateAsync().then((state) => {
      if (mounted) {
        setOnline(isOnline(state));
      }
    });

    const subscription = Network.addNetworkStateListener((state) => setOnline(isOnline(state)));

    return () => {
      mounted = false;
      subscription.remove();
    };
  }, []);

  return online;
}

function isOnline(state: Network.NetworkState): boolean {
  return state.isConnected !== false && state.isInternetReachable !== false;
}
