import { useEffect, useRef, useState } from 'react';
import { ActivityIndicator, StyleSheet, View } from 'react-native';
import { WebView, type WebViewMessageEvent } from 'react-native-webview';

import { Radii } from '@/constants/theme';
import { useTheme } from '@/hooks/use-theme';
import type { AgentPosition, AgentRoute, AgentTrackingStatus } from '@/types/api';

/** Pin styling shared by both modes. */
export interface MapAgent {
  id: string;
  first_name: string;
  initials: string;
  color: string;
  photo_url: string | null;
  status: AgentTrackingStatus;
}

export type TrackingMapPayload =
  | { mode: 'overview'; agents: AgentPosition[] }
  | { mode: 'route'; agent: MapAgent; route: AgentRoute; index: number };

type Props = {
  payload: TrackingMapPayload;
  /** Fly the map to this point (e.g. a tapped timeline entry); change `key` to re-trigger. */
  focus?: { lat: number; lng: number; key: string } | null;
  onAgentPress?: (agentId: string) => void;
  height?: number;
};

/**
 * Leaflet + OpenStreetMap inside a WebView — the same map and pin design as
 * the web Agent Tracking pages, with no native maps SDK or API key. Needs a
 * connection for tiles, which tracking views do anyway.
 */
export function TrackingMap({ payload, focus, onAgentPress, height = 320 }: Props) {
  const theme = useTheme();
  const webRef = useRef<WebView>(null);
  const [ready, setReady] = useState(false);

  useEffect(() => {
    if (ready) {
      webRef.current?.injectJavaScript(`window.__render(${JSON.stringify(payload)}); true;`);
    }
  }, [payload, ready]);

  useEffect(() => {
    if (ready && focus) {
      webRef.current?.injectJavaScript(`window.__focus(${focus.lat}, ${focus.lng}); true;`);
    }
  }, [focus, ready]);

  function handleMessage(event: WebViewMessageEvent) {
    try {
      const message = JSON.parse(event.nativeEvent.data) as { type: string; id?: string };
      if (message.type === 'agent' && message.id) {
        onAgentPress?.(message.id);
      }
    } catch {
      // Ignore anything that isn't one of our messages.
    }
  }

  return (
    <View style={[styles.wrap, { height, borderColor: theme.border, backgroundColor: theme.surfaceMuted }]}>
      <WebView
        ref={webRef}
        originWhitelist={['*']}
        source={{ html: MAP_HTML }}
        onLoadEnd={() => setReady(true)}
        onMessage={handleMessage}
        javaScriptEnabled
        scrollEnabled={false}
        nestedScrollEnabled
        setSupportMultipleWindows={false}
        style={styles.web}
      />
      {!ready ? (
        <View style={styles.loading} pointerEvents="none">
          <ActivityIndicator color={theme.primary} />
        </View>
      ) : null}
    </View>
  );
}

const styles = StyleSheet.create({
  wrap: { borderRadius: Radii.lg, overflow: 'hidden', borderWidth: StyleSheet.hairlineWidth },
  web: { flex: 1, backgroundColor: 'transparent' },
  loading: { ...StyleSheet.absoluteFill, alignItems: 'center', justifyContent: 'center' },
});

const MAP_HTML = `<!doctype html>
<html>
<head>
<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1, user-scalable=no" />
<link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
<script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
<style>
  html, body, #map { margin: 0; height: 100%; background: #e5e7eb; }
  .pin-icon { background: transparent; border: 0; }
  .pin { position: relative; width: 100px; display: flex; flex-direction: column; align-items: center; }
  .head { position: relative; width: 40px; height: 40px; border-radius: 999px; border: 3px solid var(--c); background: var(--c); color: #fff; display: flex; align-items: center; justify-content: center; font: 700 13px/1 system-ui, sans-serif; box-shadow: 0 3px 8px rgba(0,0,0,.3); }
  .head img { width: 100%; height: 100%; border-radius: 999px; object-fit: cover; }
  .tail { width: 0; height: 0; border-left: 6px solid transparent; border-right: 6px solid transparent; border-top: 8px solid var(--c); margin-top: -1px; }
  .dot { position: absolute; right: -3px; bottom: -3px; width: 12px; height: 12px; border-radius: 999px; border: 2px solid #fff; }
  .label { margin-top: 2px; padding: 1px 7px; border-radius: 999px; background: #fff; color: #111827; font: 600 11px/1.5 system-ui, sans-serif; box-shadow: 0 1px 3px rgba(0,0,0,.25); white-space: nowrap; }
  .s-active { background: #22c55e; } .s-stale { background: #f59e0b; } .s-off_duty { background: #9ca3af; }
  .pin.stale .head img, .pin.stale .head span { filter: grayscale(1); opacity: .65; }
  .pin.off_duty { opacity: .55; }
  .mark { display: flex; align-items: center; justify-content: center; border-radius: 999px; color: #fff; font: 700 11px/1 system-ui, sans-serif; border: 2px solid #fff; box-shadow: 0 2px 5px rgba(0,0,0,.35); }
  .k-start { background: #16a34a; } .k-stop { background: #f59e0b; } .k-collection { background: #0d9488; }
</style>
</head>
<body>
<div id="map"></div>
<script>
  var map = L.map('map', { zoomControl: false, attributionControl: false }).setView([5.6037, -0.1870], 12);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { maxZoom: 19 }).addTo(map);
  L.control.attribution({ prefix: false }).addAttribution('&copy; OpenStreetMap').addTo(map);
  var layer = L.featureGroup().addTo(map);
  var lastKey = null;

  function esc(v) { return String(v == null ? '' : v).replace(/[&<>"']/g, function (c) { return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]; }); }

  function pinIcon(a) {
    var avatar = a.photo_url ? '<img src="' + esc(a.photo_url) + '">' : '<span>' + esc(a.initials) + '</span>';
    return L.divIcon({
      className: 'pin-icon', iconSize: [100, 70], iconAnchor: [50, 49],
      html: '<div class="pin ' + a.status + '" style="--c:' + a.color + '"><div class="head">' + avatar +
        '<span class="dot s-' + a.status + '"></span></div><div class="tail"></div><div class="label">' + esc(a.first_name) + '</div></div>'
    });
  }

  function mark(kind, badge, size) {
    return L.divIcon({ className: 'pin-icon', iconSize: [size, size], iconAnchor: [size / 2, size / 2],
      html: '<div class="mark k-' + kind + '" style="width:' + size + 'px;height:' + size + 'px">' + esc(badge) + '</div>' });
  }

  function fit(key) {
    if (key === lastKey) { return; }
    lastKey = key;
    var b = layer.getBounds();
    if (b.isValid()) { map.fitBounds(b, { padding: [40, 40], maxZoom: 16 }); }
  }

  window.__render = function (p) {
    layer.clearLayers();

    if (p.mode === 'overview') {
      p.agents.forEach(function (a) {
        L.marker([a.lat, a.lng], { icon: pinIcon(a), zIndexOffset: a.status === 'active' ? 100 : 0 })
          .on('click', function () { window.ReactNativeWebView.postMessage(JSON.stringify({ type: 'agent', id: a.id })); })
          .addTo(layer);
      });
      fit('overview');
      return;
    }

    var pts = p.route.points.map(function (pt) { return [pt.lat, pt.lng]; });
    var c = p.agent.color;
    if (pts.length > 1) {
      L.polyline(pts, { color: '#fff', weight: 7, opacity: .9 }).addTo(layer);
      L.polyline(pts, { color: c, weight: 4, opacity: .3 }).addTo(layer);
      L.polyline(pts.slice(0, p.index + 1), { color: c, weight: 5 }).addTo(layer);
      L.marker(pts[0], { icon: mark('start', 'S', 22) }).addTo(layer);
    }
    p.route.stops.forEach(function (s, i) { L.marker([s.lat, s.lng], { icon: mark('stop', String(i + 1), 22), zIndexOffset: 200 }).addTo(layer); });
    p.route.collections.forEach(function (col) { L.marker([col.lat, col.lng], { icon: mark('collection', '\\u20B5', 24), zIndexOffset: 300 }).addTo(layer); });
    if (pts.length) {
      L.marker(pts[Math.min(p.index, pts.length - 1)], { icon: pinIcon(p.agent), zIndexOffset: 1000 }).addTo(layer);
    }
    fit(p.agent.id + '|' + p.route.date);
  };

  window.__focus = function (lat, lng) { map.flyTo([lat, lng], Math.max(map.getZoom(), 16)); };
</script>
</body>
</html>`;
