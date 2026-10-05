<x-filament-panels::page>
    @include('filament.pages.partials.agent-map-styles')

    <style>
        .ta-head { display: flex; flex-wrap: wrap; align-items: center; gap: 1rem; padding: 1rem; margin-bottom: 1rem; }
        .ta-head__who { display: flex; align-items: center; gap: 0.875rem; flex: 1; min-width: 240px; }
        .ta-head__name { font-size: 1.125rem; font-weight: 700; color: var(--at-text); }
        .ta-controls { display: flex; flex-wrap: wrap; gap: 0.5rem; align-items: center; }
        .ta-controls select, .ta-controls input { font-size: 0.875rem; padding: 0.4375rem 0.625rem; border-radius: 0.5rem; border: 1px solid var(--at-border); background: var(--at-soft); color: var(--at-text); }
        .ta-live { display: inline-flex; align-items: center; gap: 0.375rem; font-size: 0.75rem; font-weight: 700; color: #dc2626; text-transform: uppercase; letter-spacing: .05em; }
        .ta-live i { width: 0.5rem; height: 0.5rem; border-radius: 999px; background: #dc2626; animation: ta-blink 1.4s ease-in-out infinite; }
        @keyframes ta-blink { 50% { opacity: .25; } }

        .ta-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
        @media (min-width: 768px) { .ta-stats { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (min-width: 1280px) { .ta-stats { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
        .ta-stat { padding: 0.875rem 1rem; }
        .ta-stat__sub { font-size: 0.75rem; color: var(--at-muted); margin-top: 2px; }

        .ta-layout { display: flex; flex-direction: column; gap: 1rem; }

        .ta-timeline__head { display: flex; align-items: baseline; justify-content: space-between; gap: 0.5rem; padding: 0.75rem 0.875rem; border-bottom: 1px solid var(--at-border); }
        .ta-timeline__title { font-size: 0.8125rem; font-weight: 700; color: var(--at-text); }
        .ta-timeline__track { display: flex; overflow-x: auto; padding: 0.875rem 0 1rem; scroll-snap-type: x proximity; }
        .ta-event { position: relative; flex: 0 0 190px; display: flex; flex-direction: column; gap: 0.375rem; text-align: left; padding: 0 0.875rem; background: transparent; cursor: pointer; scroll-snap-align: start; }
        .ta-event::before { content: ''; position: absolute; top: 13px; left: 0; right: 0; height: 2px; background: var(--at-border); }
        .ta-event:first-child::before { left: calc(0.875rem + 14px); }
        .ta-event:last-child::before { right: calc(100% - 0.875rem - 14px); }
        .ta-event:hover .ta-event__title { color: var(--agent); }
        .ta-event__icon { position: relative; z-index: 1; width: 28px; height: 28px; border-radius: 999px; display: flex; align-items: center; justify-content: center; font: 700 12px/1 system-ui, sans-serif; color: #fff; border: 2px solid var(--at-surface); }
        .ta-event__time { font-size: 0.6875rem; color: var(--at-muted); font-variant-numeric: tabular-nums; }
        .ta-event__title { font-size: 0.8125rem; font-weight: 600; color: var(--at-text); }
        .ta-event__sub { font-size: 0.75rem; color: var(--at-muted); overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
        .ta-map-wrap { position: relative; }
        .ta-map { height: 560px; isolation: isolate; z-index: 0; }
        .ta-empty { position: absolute; inset: auto 1rem 1rem 1rem; z-index: 500; padding: 0.75rem 1rem; border-radius: 0.75rem; background: var(--at-surface); border: 1px solid var(--at-border); font-size: 0.875rem; color: var(--at-muted); box-shadow: 0 4px 12px rgba(0,0,0,.12); }

        .ta-player { display: flex; flex-wrap: wrap; align-items: center; gap: 0.625rem; padding: 0.625rem 0.875rem; border-bottom: 1px solid var(--at-border); }
        .ta-player input[type=range] { flex: 1; min-width: 160px; accent-color: var(--agent); }
        .ta-player__time { font-size: 0.8125rem; font-weight: 600; color: var(--at-text); font-variant-numeric: tabular-nums; min-width: 4.5rem; }
        .ta-player select { font-size: 0.75rem; padding: 0.3125rem 0.5rem; border-radius: 0.5rem; border: 1px solid var(--at-border); background: var(--at-soft); color: var(--at-text); }
        .ta-toggle { display: inline-flex; align-items: center; gap: 0.375rem; font-size: 0.75rem; color: var(--at-muted); cursor: pointer; }


        .ta-mark { display: flex; align-items: center; justify-content: center; border-radius: 999px; color: #fff; font: 700 12px/1 system-ui, sans-serif; border: 2px solid #fff; box-shadow: 0 2px 6px rgba(0,0,0,.35); }
        .ta-kind--start { background: #16a34a; }
        .ta-kind--stop { background: #f59e0b; }
        .ta-kind--collection { background: #0d9488; }
        .ta-kind--end { background: #6b7280; }
        .ta-kind--live { background: #dc2626; }
    </style>

    <div
        class="at-root"
        x-data="trackAgentMap()"
        x-init="init()"
        wire:poll.15s="refreshLive"
        style="--agent: {{ $agentInfo['color'] ?? '#2563eb' }}"
    >
        <div class="at-card ta-head">
            <div class="ta-head__who">
                <span class="at-avatar at-avatar--lg">
                    @if ($agentInfo['photo_url'] ?? null)
                        <img src="{{ $agentInfo['photo_url'] }}" alt="" />
                    @else
                        <span>{{ $agentInfo['initials'] ?? '?' }}</span>
                    @endif
                    <span class="at-avatar__dot at-status--{{ $agentInfo['status'] ?? 'off_duty' }}"></span>
                </span>
                <div>
                    <div class="ta-head__name">{{ $agentInfo['name'] ?? '' }}</div>
                    <div class="at-row__meta">
                        {{ $agentInfo['phone'] ?? 'No phone on file' }}
                        · Last seen {{ $agentInfo['located_at_human'] ?? 'never' }}
                    </div>
                    <div style="margin-top: 0.375rem; display: flex; gap: 0.5rem; align-items: center">
                        <span class="at-chip at-chip--{{ $agentInfo['status'] ?? 'off_duty' }}">
                            {{ ['active' => 'Active', 'stale' => 'No recent signal', 'off_duty' => 'Off duty'][$agentInfo['status'] ?? 'off_duty'] }}
                        </span>
                        @if ($route['is_today'] ?? false)
                            <span class="ta-live"><i></i> Live</span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="ta-controls">
                <select wire:model.live="agent" aria-label="Agent">
                    @foreach ($this->agentOptions() as $id => $name)
                        <option value="{{ $id }}">{{ $name }}</option>
                    @endforeach
                </select>
                <input type="date" wire:model.live="date" max="{{ now()->toDateString() }}" aria-label="Day" />
                @if (! ($route['is_today'] ?? false))
                    <button type="button" class="at-btn" wire:click="$set('date', '{{ now()->toDateString() }}')">Today</button>
                @endif
                @if ($agentInfo['phone'] ?? null)
                    <a class="at-btn" href="tel:{{ $agentInfo['phone'] }}">Call</a>
                @endif
            </div>
        </div>

        <div class="ta-stats">
            <div class="at-card ta-stat">
                <div class="at-detail__label">Distance</div>
                <div class="at-stat__value">{{ number_format($route['summary']['distance_km'] ?? 0, 2) }} km</div>
                <div class="ta-stat__sub">{{ $route['summary']['points_count'] ?? 0 }} location updates</div>
            </div>
            <div class="at-card ta-stat">
                <div class="at-detail__label">Time on route</div>
                <div class="at-stat__value">
                    {{ intdiv($route['summary']['duration_minutes'] ?? 0, 60) }}h {{ ($route['summary']['duration_minutes'] ?? 0) % 60 }}m
                </div>
                <div class="ta-stat__sub">
                    {{ $route['summary']['started_time'] ?? '—' }} – {{ $route['summary']['ended_time'] ?? '—' }}
                </div>
            </div>
            <div class="at-card ta-stat">
                <div class="at-detail__label">Stops</div>
                <div class="at-stat__value">{{ $route['summary']['stops_count'] ?? 0 }}</div>
                <div class="ta-stat__sub">{{ \App\Actions\Agents\BuildAgentRouteAction::STOP_MIN_MINUTES }}+ min in one place</div>
            </div>
            <div class="at-card ta-stat">
                <div class="at-detail__label">Collections on map</div>
                <div class="at-stat__value">{{ $route['summary']['collections_count'] ?? 0 }}</div>
                <div class="ta-stat__sub">{{ $route['summary']['collections_total_formatted'] ?? 'GHS 0.00' }}</div>
            </div>
            <div class="at-card ta-stat">
                <div class="at-detail__label">Day</div>
                <div class="at-stat__value" style="font-size: 1.125rem">{{ \Illuminate\Support\Carbon::parse($date)->format('D, j M Y') }}</div>
                <div class="ta-stat__sub">{{ ($route['is_today'] ?? false) ? 'Updates every 15 seconds' : 'Replay of a past day' }}</div>
            </div>
        </div>

        <div class="ta-layout">
            <div class="at-card">
                <div class="ta-player">
                    <button type="button" class="at-btn" @click="togglePlay()" :disabled="points.length < 2" x-text="playing ? 'Pause' : 'Replay'"></button>
                    <input type="range" min="0" :max="Math.max(points.length - 1, 0)" x-model.number="index" @input="pause(); updateProgress(true)" :disabled="points.length < 2" aria-label="Route position" />
                    <span class="ta-player__time" x-text="points[index]?.time ?? '—'"></span>
                    <select x-model.number="speed" aria-label="Replay speed">
                        <option value="1">1×</option>
                        <option value="4">4×</option>
                        <option value="10">10×</option>
                    </select>
                    <template x-if="route.is_today">
                        <label class="ta-toggle"><input type="checkbox" x-model="follow" /> Follow live</label>
                    </template>
                    <button type="button" class="at-btn" @click="fitRoute()">Fit route</button>
                </div>
                <div class="ta-map-wrap">
                    <div x-ref="mapEl" wire:ignore class="ta-map"></div>
                    <div class="ta-empty" x-show="points.length === 0" x-cloak>
                        No movement recorded for this day. Agents share their location only while on duty in the mobile app.
                    </div>
                </div>
            </div>

            <div class="at-card">
                <div class="ta-timeline__head">
                    <span class="ta-timeline__title">Timeline</span>
                    <span class="at-row__meta" x-show="events.length" x-text="`${events.length} events · click one to find it on the map`"></span>
                </div>
                <div class="ta-timeline__track" x-show="events.length">
                    <template x-for="event in events" :key="event.key">
                        <button type="button" class="ta-event" @click="focusEvent(event)">
                            <span class="ta-event__icon" :class="`ta-kind--${event.kind}`" x-text="event.badge"></span>
                            <span class="ta-event__time" x-text="event.time"></span>
                            <span class="ta-event__title" x-text="event.title"></span>
                            <span class="ta-event__sub" x-show="event.subtitle" x-text="event.subtitle"></span>
                        </button>
                    </template>
                </div>
                <div class="at-empty" x-show="events.length === 0">Nothing recorded for this day.</div>
            </div>
        </div>
    </div>

    @script
    <script>
        window.trackAgentMap = function () {
            const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

            return {
                map: null,
                layer: null,
                progressLine: null,
                agentMarker: null,
                eventMarkers: {},
                index: 0,
                playing: false,
                speed: 4,
                follow: true,
                timer: null,
                routeKey: null,
                route: @entangle('route'),
                agentInfo: @entangle('agentInfo'),

                get points() {
                    return this.route?.points ?? [];
                },

                get events() {
                    const route = this.route ?? {};
                    const points = this.points;
                    const events = [];

                    if (points.length) {
                        const first = points[0];
                        events.push({ key: 'start', kind: 'start', badge: 'S', at: first.at, time: first.time, title: 'Started route', subtitle: null, lat: first.lat, lng: first.lng });
                    }

                    (route.stops ?? []).forEach((stop, i) => events.push({
                        key: `stop-${i}`, kind: 'stop', badge: String(i + 1), at: stop.arrived_at, time: `${stop.arrived_time} – ${stop.left_time}`,
                        title: `Stopped ${stop.minutes} min`, subtitle: `Stop ${i + 1}`, lat: stop.lat, lng: stop.lng,
                    }));

                    (route.collections ?? []).forEach((collection, i) => events.push({
                        key: `collection-${i}`, kind: 'collection', badge: '₵', at: collection.at, time: collection.time,
                        title: `Collected ${collection.amount_formatted}`, subtitle: collection.description, lat: collection.lat, lng: collection.lng,
                    }));

                    if (points.length > 1) {
                        const last = points[points.length - 1];
                        events.push({
                            key: 'end', kind: route.is_today ? 'live' : 'end', badge: route.is_today ? '●' : 'E', at: last.at, time: last.time,
                            title: route.is_today ? 'Latest position' : 'Last position', subtitle: null, lat: last.lat, lng: last.lng,
                        });
                    }

                    return events.sort((a, b) => Date.parse(a.at) - Date.parse(b.at));
                },

                init() {
                    if (this.map) {
                        return;
                    }

                    this.map = L.map(this.$refs.mapEl).setView([5.6037, -0.1870], 13); // Accra fallback

                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap contributors',
                        maxZoom: 19,
                    }).addTo(this.map);

                    this.layer = L.featureGroup().addTo(this.map);
                    this.draw();
                    this.$watch('route', () => this.draw());

                    // The panel's final size isn't known until layout settles; refit once it is.
                    setTimeout(() => {
                        this.map.invalidateSize();
                        this.fitRoute();
                    }, 150);
                },

                draw() {
                    const key = `${this.agentInfo?.id}|${this.route?.date}`;
                    const isNewRoute = key !== this.routeKey;
                    const wasAtEnd = this.index >= this.points.length - 2;
                    this.routeKey = key;

                    if (isNewRoute) {
                        this.pause();
                    }

                    this.layer.clearLayers();
                    this.eventMarkers = {};
                    const points = this.points;
                    const color = this.agentInfo?.color ?? '#2563eb';
                    const latLngs = points.map((p) => [p.lat, p.lng]);

                    if (isNewRoute || (wasAtEnd && !this.playing)) {
                        this.index = Math.max(points.length - 1, 0);
                    }

                    if (latLngs.length > 1) {
                        L.polyline(latLngs, { color: '#ffffff', weight: 8, opacity: 0.9 }).addTo(this.layer);
                        L.polyline(latLngs, { color, weight: 4, opacity: 0.3 }).addTo(this.layer);
                        this.progressLine = L.polyline([], { color, weight: 5 }).addTo(this.layer);
                    }

                    for (const event of this.events) {
                        if (event.kind === 'end' || event.kind === 'live') {
                            continue;
                        }

                        const size = event.kind === 'collection' ? 26 : 24;
                        const marker = L.marker([event.lat, event.lng], {
                            icon: L.divIcon({
                                className: 'at-pin-icon',
                                iconSize: [size, size],
                                iconAnchor: [size / 2, size / 2],
                                html: `<div class="ta-mark ta-kind--${event.kind}" style="width:${size}px;height:${size}px">${escapeHtml(event.badge)}</div>`,
                            }),
                            zIndexOffset: event.kind === 'collection' ? 300 : 200,
                        })
                            .bindTooltip(`<div class="at-tip"><strong>${escapeHtml(event.title)}</strong>${escapeHtml(event.time)}${event.subtitle ? '<br>' + escapeHtml(event.subtitle) : ''}</div>`, { direction: 'top', offset: [0, -12] })
                            .addTo(this.layer);

                        this.eventMarkers[event.key] = marker;
                    }

                    const fallback = this.agentInfo?.live_lat ? [this.agentInfo.live_lat, this.agentInfo.live_lng] : null;
                    const agentAt = points.length ? [points[this.index].lat, points[this.index].lng] : fallback;

                    if (agentAt) {
                        this.agentMarker = L.marker(agentAt, { icon: this.pinIcon(), zIndexOffset: 1000 }).addTo(this.layer);
                    }

                    this.updateProgress(false);

                    if (isNewRoute) {
                        this.fitRoute();
                    } else if (this.route?.is_today && this.follow && !this.playing && agentAt) {
                        this.map.panTo(agentAt);
                    }
                },

                pinIcon() {
                    const agent = this.agentInfo ?? {};
                    const avatar = agent.photo_url
                        ? `<img src="${escapeHtml(agent.photo_url)}" alt="">`
                        : `<span>${escapeHtml(agent.initials)}</span>`;

                    return L.divIcon({
                        className: 'at-pin-icon',
                        iconSize: [120, 78],
                        iconAnchor: [60, 55],
                        html: `
                            <div class="at-pin at-pin--${agent.status} at-pin--selected" style="--agent: ${agent.color}">
                                <div class="at-pin__head">${avatar}<span class="at-pin__dot at-status--${agent.status}"></span></div>
                                <div class="at-pin__tail"></div>
                                <div class="at-pin__label">${escapeHtml(agent.first_name)}</div>
                            </div>`,
                    });
                },

                updateProgress(pan) {
                    const points = this.points;

                    if (!points.length) {
                        return;
                    }

                    const current = points[Math.min(this.index, points.length - 1)];

                    this.progressLine?.setLatLngs(points.slice(0, this.index + 1).map((p) => [p.lat, p.lng]));
                    this.agentMarker?.setLatLng([current.lat, current.lng]);

                    if (pan && !this.map.getBounds().pad(-0.15).contains([current.lat, current.lng])) {
                        this.map.panTo([current.lat, current.lng]);
                    }
                },

                togglePlay() {
                    this.playing ? this.pause() : this.play();
                },

                play() {
                    if (this.points.length < 2) {
                        return;
                    }

                    if (this.index >= this.points.length - 1) {
                        this.index = 0;
                    }

                    this.playing = true;
                    const tick = () => {
                        if (!this.playing) {
                            return;
                        }

                        if (this.index >= this.points.length - 1) {
                            this.pause();

                            return;
                        }

                        this.index++;
                        this.updateProgress(true);
                        this.timer = setTimeout(tick, 600 / this.speed);
                    };
                    tick();
                },

                pause() {
                    this.playing = false;
                    clearTimeout(this.timer);
                },

                fitRoute() {
                    const bounds = this.layer.getBounds();

                    if (bounds.isValid()) {
                        this.map.fitBounds(bounds, { maxZoom: 16, paddingTopLeft: [40, 70], paddingBottomRight: [70, 40] });
                    }
                },

                focusEvent(event) {
                    this.pause();
                    const eventTime = Date.parse(event.at);
                    let nearest = 0;

                    this.points.forEach((point, i) => {
                        if (Date.parse(point.at) <= eventTime) {
                            nearest = i;
                        }
                    });

                    this.index = nearest;
                    this.updateProgress(false);
                    this.map.flyTo([event.lat, event.lng], Math.max(this.map.getZoom(), 16));
                    this.eventMarkers[event.key]?.openTooltip();
                },
            };
        };
    </script>
    @endscript
</x-filament-panels::page>
