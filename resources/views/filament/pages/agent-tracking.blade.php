<x-filament-panels::page>
    <style>
        .at-root { --at-border: #e5e7eb; --at-muted: #6b7280; --at-text: #111827; --at-surface: #ffffff; --at-soft: #f9fafb; --at-hover: #f3f4f6; }
        .dark .at-root { --at-border: #374151; --at-muted: #9ca3af; --at-text: #f9fafb; --at-surface: #111827; --at-soft: #1f2937; --at-hover: #1f2937; }

        .at-stats { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem; margin-bottom: 1rem; }
        @media (min-width: 1024px) { .at-stats { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .at-stat { background: var(--at-surface); border: 1px solid var(--at-border); border-radius: 0.75rem; padding: 0.875rem 1rem; display: flex; align-items: center; gap: 0.75rem; }
        .at-stat__dot { width: 0.625rem; height: 0.625rem; border-radius: 999px; flex: none; }
        .at-stat__value { font-size: 1.375rem; font-weight: 700; color: var(--at-text); line-height: 1.1; font-variant-numeric: tabular-nums; }
        .at-stat__label { font-size: 0.75rem; color: var(--at-muted); }

        .at-layout { display: grid; grid-template-columns: minmax(0, 1fr); gap: 1rem; }
        @media (min-width: 1024px) { .at-layout { grid-template-columns: minmax(0, 1fr) 320px; } }

        .at-card { background: var(--at-surface); border: 1px solid var(--at-border); border-radius: 0.75rem; overflow: hidden; }
        .at-map { height: 560px; isolation: isolate; z-index: 0; }
        .at-map-bar { display: flex; align-items: center; justify-content: space-between; gap: 0.5rem; padding: 0.625rem 0.875rem; border-bottom: 1px solid var(--at-border); }
        .at-legend { display: flex; flex-wrap: wrap; gap: 0.875rem; font-size: 0.75rem; color: var(--at-muted); }
        .at-legend span { display: inline-flex; align-items: center; gap: 0.375rem; }
        .at-legend i { width: 0.5rem; height: 0.5rem; border-radius: 999px; display: inline-block; }
        .at-btn { font-size: 0.75rem; font-weight: 600; padding: 0.375rem 0.75rem; border-radius: 0.5rem; border: 1px solid var(--at-border); color: var(--at-text); background: var(--at-surface); cursor: pointer; white-space: nowrap; }
        .at-btn:hover { background: var(--at-hover); }

        .at-list { display: flex; flex-direction: column; max-height: 600px; }
        .at-list__search { padding: 0.75rem; border-bottom: 1px solid var(--at-border); }
        .at-list__search input { width: 100%; font-size: 0.875rem; padding: 0.5rem 0.75rem; border-radius: 0.5rem; border: 1px solid var(--at-border); background: var(--at-soft); color: var(--at-text); }
        .at-list__items { overflow-y: auto; flex: 1; }
        .at-row { display: flex; gap: 0.75rem; align-items: center; padding: 0.625rem 0.875rem; border-bottom: 1px solid var(--at-border); cursor: pointer; width: 100%; text-align: left; background: transparent; }
        .at-row:hover { background: var(--at-hover); }
        .at-row--selected { background: var(--at-hover); box-shadow: inset 3px 0 0 var(--agent); }
        .at-row__name { font-size: 0.875rem; font-weight: 600; color: var(--at-text); }
        .at-row__meta { font-size: 0.75rem; color: var(--at-muted); }
        .at-row__amount { margin-left: auto; text-align: right; font-size: 0.75rem; color: var(--at-muted); white-space: nowrap; }
        .at-row__amount strong { display: block; font-size: 0.8125rem; color: var(--at-text); }

        .at-avatar { position: relative; flex: none; width: 2.5rem; height: 2.5rem; border-radius: 999px; border: 2px solid var(--agent); background: var(--agent); color: #fff; display: flex; align-items: center; justify-content: center; font-size: 0.8125rem; font-weight: 700; }
        .at-avatar img { width: 100%; height: 100%; border-radius: 999px; object-fit: cover; }
        .at-avatar--lg { width: 4rem; height: 4rem; font-size: 1.25rem; border-width: 3px; }
        .at-avatar__dot { position: absolute; right: -2px; bottom: -2px; width: 0.75rem; height: 0.75rem; border-radius: 999px; border: 2px solid var(--at-surface); }
        .at-status--active { background: #22c55e; }
        .at-status--stale { background: #f59e0b; }
        .at-status--off_duty { background: #9ca3af; }
        .at-chip { display: inline-flex; align-items: center; gap: 0.25rem; font-size: 0.6875rem; font-weight: 600; padding: 0.125rem 0.5rem; border-radius: 999px; }
        .at-chip--active { background: #dcfce7; color: #15803d; }
        .at-chip--stale { background: #fef3c7; color: #b45309; }
        .at-chip--off_duty { background: #f3f4f6; color: #4b5563; }
        .dark .at-chip--active { background: rgba(34, 197, 94, .15); color: #4ade80; }
        .dark .at-chip--stale { background: rgba(245, 158, 11, .15); color: #fbbf24; }
        .dark .at-chip--off_duty { background: rgba(156, 163, 175, .15); color: #d1d5db; }

        .at-detail { margin-top: 1rem; padding: 1rem; display: flex; flex-wrap: wrap; gap: 1rem; align-items: flex-start; }
        .at-detail__grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 0.75rem 1.5rem; flex: 1; min-width: 260px; }
        @media (min-width: 768px) { .at-detail__grid { grid-template-columns: repeat(4, minmax(0, 1fr)); } }
        .at-detail__label { font-size: 0.6875rem; text-transform: uppercase; letter-spacing: .04em; color: var(--at-muted); }
        .at-detail__value { font-size: 0.9375rem; font-weight: 600; color: var(--at-text); font-variant-numeric: tabular-nums; }
        .at-empty { padding: 2rem 1rem; text-align: center; font-size: 0.875rem; color: var(--at-muted); }

        /* Map pins */
        .at-pin-icon { background: transparent; border: 0; }
        .at-pin { position: relative; width: 120px; display: flex; flex-direction: column; align-items: center; }
        .at-pin__head { position: relative; width: 46px; height: 46px; border-radius: 999px; border: 3px solid var(--agent); background: var(--agent); color: #fff; display: flex; align-items: center; justify-content: center; font: 700 14px/1 system-ui, sans-serif; box-shadow: 0 4px 10px rgba(0, 0, 0, .3); }
        .at-pin__head img { width: 100%; height: 100%; border-radius: 999px; object-fit: cover; }
        .at-pin__tail { width: 0; height: 0; border-left: 7px solid transparent; border-right: 7px solid transparent; border-top: 9px solid var(--agent); margin-top: -1px; }
        .at-pin__dot { position: absolute; right: -3px; bottom: -3px; width: 14px; height: 14px; border-radius: 999px; border: 2px solid #fff; }
        .at-pin__label { margin-top: 3px; padding: 1px 8px; border-radius: 999px; background: #fff; color: #111827; font: 600 11px/1.5 system-ui, sans-serif; box-shadow: 0 1px 4px rgba(0, 0, 0, .25); white-space: nowrap; max-width: 120px; overflow: hidden; text-overflow: ellipsis; }
        .at-pin--stale .at-pin__head img, .at-pin--stale .at-pin__head span { filter: grayscale(1); opacity: .65; }
        .at-pin--off_duty { opacity: .55; }
        .at-pin--active .at-pin__head::before { content: ''; position: absolute; inset: -9px; border-radius: 999px; border: 2px solid #22c55e; animation: at-pulse 2s ease-out infinite; }
        .at-pin--selected .at-pin__head { transform: scale(1.18); box-shadow: 0 0 0 4px rgba(255, 255, 255, .9), 0 6px 14px rgba(0, 0, 0, .35); }
        .at-pin--selected .at-pin__label { background: var(--agent); color: #fff; }
        @keyframes at-pulse { from { transform: scale(.85); opacity: .9; } to { transform: scale(1.35); opacity: 0; } }

        /* Popup card */
        .at-popup .leaflet-popup-content-wrapper { border-radius: 0.875rem; padding: 0; overflow: hidden; }
        .at-popup .leaflet-popup-content { margin: 0; width: 260px !important; font-family: system-ui, sans-serif; }
        .at-pop__head { display: flex; gap: 0.75rem; align-items: center; padding: 0.875rem; color: #fff; background: var(--agent); }
        .at-pop__head .at-avatar { border-color: #fff; }
        .at-pop__name { font-size: 0.9375rem; font-weight: 700; line-height: 1.2; }
        .at-pop__sub { font-size: 0.75rem; opacity: .9; margin-top: 2px; }
        .at-pop__body { padding: 0.75rem 0.875rem; display: grid; grid-template-columns: 1fr 1fr; gap: 0.625rem; color: #111827; }
        .at-pop__label { font-size: 0.625rem; text-transform: uppercase; letter-spacing: .04em; color: #6b7280; }
        .at-pop__value { font-size: 0.8125rem; font-weight: 600; }
        .at-pop__actions { display: flex; gap: 0.5rem; padding: 0 0.875rem 0.875rem; }
        .at-pop__actions a, .at-pop__actions button { flex: 1; text-align: center; font-size: 0.75rem; font-weight: 600; padding: 0.4375rem 0.5rem; border-radius: 0.5rem; border: 1px solid #e5e7eb; color: #111827; background: #fff; cursor: pointer; text-decoration: none; }
        .at-pop__actions button.at-primary { background: var(--agent); border-color: var(--agent); color: #fff; }
        .at-tip { font: 12px/1.4 system-ui, sans-serif; }
        .at-tip strong { display: block; font-size: 13px; }
    </style>

    <div
        class="at-root"
        x-data="agentTrackingMap()"
        x-init="init()"
        wire:poll.10s="refreshPositions"
    >
        <div class="at-stats">
            <div class="at-stat">
                <span class="at-stat__dot at-status--active"></span>
                <div><div class="at-stat__value" x-text="counts.active"></div><div class="at-stat__label">Active now</div></div>
            </div>
            <div class="at-stat">
                <span class="at-stat__dot at-status--stale"></span>
                <div><div class="at-stat__value" x-text="counts.stale"></div><div class="at-stat__label">On duty, no recent signal</div></div>
            </div>
            <div class="at-stat">
                <span class="at-stat__dot at-status--off_duty"></span>
                <div><div class="at-stat__value" x-text="counts.off_duty"></div><div class="at-stat__label">Off duty</div></div>
            </div>
            <div class="at-stat">
                <span class="at-stat__dot" style="background: #2563eb"></span>
                <div><div class="at-stat__value" x-text="counts.collections"></div><div class="at-stat__label">Collections today</div></div>
            </div>
        </div>

        <div class="at-layout">
            <div class="at-card">
                <div class="at-map-bar">
                    <div class="at-legend">
                        <span><i class="at-status--active"></i> Active (signal &lt; 15 min)</span>
                        <span><i class="at-status--stale"></i> No recent signal</span>
                        <span><i class="at-status--off_duty"></i> Off duty</span>
                    </div>
                    <button type="button" class="at-btn" @click="fitAll()">Show all agents</button>
                </div>
                <div x-ref="mapEl" wire:ignore class="at-map"></div>
            </div>

            <div class="at-card at-list">
                <div class="at-list__search">
                    <input type="search" x-model="search" placeholder="Search agents…" />
                </div>
                <div class="at-list__items">
                    <template x-for="agent in filteredAgents" :key="agent.id">
                        <button
                            type="button"
                            class="at-row"
                            :class="{ 'at-row--selected': agent.id === selectedAgentId }"
                            :style="`--agent: ${agent.color}`"
                            @click="focusAgent(agent.id)"
                        >
                            <span class="at-avatar">
                                <template x-if="agent.photo_url"><img :src="agent.photo_url" alt="" /></template>
                                <template x-if="!agent.photo_url"><span x-text="agent.initials"></span></template>
                                <span class="at-avatar__dot" :class="`at-status--${agent.status}`"></span>
                            </span>
                            <span style="min-width: 0">
                                <span class="at-row__name" x-text="agent.name"></span>
                                <span class="at-row__meta" style="display: block" x-text="statusText(agent)"></span>
                            </span>
                            <span class="at-row__amount">
                                <strong x-text="agent.collections_total_formatted"></strong>
                                <span x-text="`${agent.collections_count} collections`"></span>
                            </span>
                        </button>
                    </template>
                    <div class="at-empty" x-show="filteredAgents.length === 0" x-text="(positions ?? []).length ? 'No agent matches your search.' : 'No agents have shared a location yet.'"></div>
                </div>
            </div>
        </div>

        <div class="at-card at-detail" x-show="selected" x-transition x-cloak :style="selected ? `--agent: ${selected.color}` : ''">
            <span class="at-avatar at-avatar--lg">
                <template x-if="selected?.photo_url"><img :src="selected.photo_url" alt="" /></template>
                <template x-if="!selected?.photo_url"><span x-text="selected?.initials"></span></template>
            </span>
            <div style="min-width: 180px">
                <p class="at-row__name" style="font-size: 1rem" x-text="selected?.name"></p>
                <p class="at-row__meta" x-text="selected?.phone ?? 'No phone on file'"></p>
                <p style="margin-top: 0.375rem">
                    <span class="at-chip" :class="`at-chip--${selected?.status}`" x-text="statusLabel(selected)"></span>
                </p>
            </div>
            <div class="at-detail__grid">
                <div><div class="at-detail__label">Last seen</div><div class="at-detail__value" x-text="selected?.located_at_human ?? 'Never'"></div></div>
                <div><div class="at-detail__label">Started today</div><div class="at-detail__value" x-text="selected?.started_at ?? '—'"></div></div>
                <div><div class="at-detail__label">Distance today</div><div class="at-detail__value" x-text="`${Number(trailDistanceKm).toFixed(2)} km`"></div></div>
                <div><div class="at-detail__label">Location updates</div><div class="at-detail__value" x-text="selected?.pings_today ?? 0"></div></div>
                <div><div class="at-detail__label">Collections</div><div class="at-detail__value" x-text="selected?.collections_count ?? 0"></div></div>
                <div><div class="at-detail__label">Collected</div><div class="at-detail__value" x-text="selected?.collections_total_formatted"></div></div>
                <div style="grid-column: span 2"><div class="at-detail__label">Coordinates</div><div class="at-detail__value" x-text="selected ? `${Number(selected.lat).toFixed(5)}, ${Number(selected.lng).toFixed(5)}` : ''"></div></div>
            </div>
            <div style="display: flex; gap: 0.5rem">
                <a class="at-btn" x-show="selected?.phone" :href="`tel:${selected?.phone}`">Call</a>
                <button type="button" class="at-btn" @click="clearSelection()">Close</button>
            </div>
        </div>
    </div>

    <div class="mt-6">
        {{ $this->table }}
    </div>

    @script
    <script>
        window.agentTrackingMap = function () {
            const escapeHtml = (value) => String(value ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);

            return {
                map: null,
                markers: {},
                markerSignatures: {},
                trailLayer: null,
                hasFitBounds: false,
                search: '',
                positions: @entangle('positions'),
                selectedAgentId: @entangle('selectedAgentId'),
                trail: @entangle('trail'),
                trailDistanceKm: @entangle('trailDistanceKm'),

                get selected() {
                    return (this.positions ?? []).find((p) => p.id === this.selectedAgentId) ?? null;
                },

                get counts() {
                    const all = this.positions ?? [];

                    return {
                        active: all.filter((p) => p.status === 'active').length,
                        stale: all.filter((p) => p.status === 'stale').length,
                        off_duty: all.filter((p) => p.status === 'off_duty').length,
                        collections: all.reduce((sum, p) => sum + (p.collections_count ?? 0), 0),
                    };
                },

                get filteredAgents() {
                    const order = { active: 0, stale: 1, off_duty: 2 };
                    const term = this.search.trim().toLowerCase();

                    return (this.positions ?? [])
                        .filter((p) => !term || p.name.toLowerCase().includes(term) || (p.phone ?? '').includes(term))
                        .sort((a, b) => order[a.status] - order[b.status] || a.name.localeCompare(b.name));
                },

                statusLabel(agent) {
                    return { active: 'Active', stale: 'No recent signal', off_duty: 'Off duty' }[agent?.status] ?? '';
                },

                statusText(agent) {
                    return `${this.statusLabel(agent)} · ${agent.located_at_human ?? 'never seen'}`;
                },

                init() {
                    if (this.map) {
                        return;
                    }

                    this.map = L.map(this.$refs.mapEl).setView([5.6037, -0.1870], 12); // Accra fallback

                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap contributors',
                        maxZoom: 19,
                    }).addTo(this.map);

                    this.renderMarkers();
                    this.renderTrail();

                    this.$watch('positions', () => this.renderMarkers());
                    this.$watch('selectedAgentId', () => this.renderMarkers());
                    this.$watch('trail', () => this.renderTrail());
                },

                avatarHtml(agent) {
                    return agent.photo_url
                        ? `<img src="${escapeHtml(agent.photo_url)}" alt="">`
                        : `<span>${escapeHtml(agent.initials)}</span>`;
                },

                pinIcon(agent) {
                    const selected = agent.id === this.selectedAgentId ? ' at-pin--selected' : '';

                    return L.divIcon({
                        className: 'at-pin-icon',
                        iconSize: [120, 78],
                        iconAnchor: [60, 55],
                        html: `
                            <div class="at-pin at-pin--${agent.status}${selected}" style="--agent: ${agent.color}">
                                <div class="at-pin__head">${this.avatarHtml(agent)}<span class="at-pin__dot at-status--${agent.status}"></span></div>
                                <div class="at-pin__tail"></div>
                                <div class="at-pin__label">${escapeHtml(agent.first_name)}</div>
                            </div>`,
                    });
                },

                tooltipHtml(agent) {
                    return `<div class="at-tip"><strong>${escapeHtml(agent.name)}</strong>${escapeHtml(this.statusLabel(agent))} · ${escapeHtml(agent.located_at_human ?? 'never seen')}<br>${agent.collections_count} collections · ${escapeHtml(agent.collections_total_formatted)}</div>`;
                },

                popupElement(agent) {
                    const el = document.createElement('div');
                    el.style.setProperty('--agent', agent.color);
                    el.innerHTML = `
                        <div class="at-pop__head">
                            <span class="at-avatar at-avatar--lg">${this.avatarHtml(agent)}</span>
                            <div>
                                <div class="at-pop__name">${escapeHtml(agent.name)}</div>
                                <div class="at-pop__sub">${escapeHtml(agent.phone ?? 'No phone on file')}</div>
                                <div style="margin-top: 6px"><span class="at-chip at-chip--${agent.status}">${escapeHtml(this.statusLabel(agent))}</span></div>
                            </div>
                        </div>
                        <div class="at-pop__body">
                            <div><div class="at-pop__label">Last seen</div><div class="at-pop__value">${escapeHtml(agent.located_at_human ?? 'Never')}</div></div>
                            <div><div class="at-pop__label">Started today</div><div class="at-pop__value">${escapeHtml(agent.started_at ?? '—')}</div></div>
                            <div><div class="at-pop__label">Collections</div><div class="at-pop__value">${agent.collections_count}</div></div>
                            <div><div class="at-pop__label">Collected</div><div class="at-pop__value">${escapeHtml(agent.collections_total_formatted)}</div></div>
                        </div>
                        <div class="at-pop__actions">
                            ${agent.phone ? `<a href="tel:${escapeHtml(agent.phone)}">Call</a>` : ''}
                            <button type="button" class="at-primary" data-route>Today's route</button>
                        </div>`;
                    el.querySelector('[data-route]').addEventListener('click', () => this.focusAgent(agent.id));

                    return el;
                },

                renderMarkers() {
                    if (!this.map) {
                        return;
                    }

                    const seen = new Set();

                    for (const agent of this.positions ?? []) {
                        seen.add(agent.id);
                        const signature = [agent.status, agent.photo_url, agent.name, agent.color, agent.id === this.selectedAgentId].join('|');
                        let marker = this.markers[agent.id];

                        if (!marker) {
                            marker = L.marker([agent.lat, agent.lng], { icon: this.pinIcon(agent), riseOnHover: true }).addTo(this.map);
                            marker.bindTooltip('', { direction: 'top', offset: [0, -52], opacity: 1 });
                            marker.bindPopup('', { className: 'at-popup', offset: [0, -48], maxWidth: 280, minWidth: 260 });
                            marker.on('click', () => this.$wire.selectAgent(agent.id));
                            this.markers[agent.id] = marker;
                        } else {
                            marker.setLatLng([agent.lat, agent.lng]);

                            if (this.markerSignatures[agent.id] !== signature) {
                                marker.setIcon(this.pinIcon(agent));
                            }
                        }

                        this.markerSignatures[agent.id] = signature;
                        marker.setZIndexOffset(agent.id === this.selectedAgentId ? 1000 : (agent.status === 'active' ? 100 : 0));
                        marker.setTooltipContent(this.tooltipHtml(agent));
                        marker.setPopupContent(this.popupElement(agent));
                    }

                    for (const id of Object.keys(this.markers)) {
                        if (!seen.has(id)) {
                            this.map.removeLayer(this.markers[id]);
                            delete this.markers[id];
                            delete this.markerSignatures[id];
                        }
                    }

                    if (Object.keys(this.markers).length && !this.hasFitBounds) {
                        this.fitAll();
                        this.hasFitBounds = true;
                    }
                },

                fitAll() {
                    const markers = Object.values(this.markers);

                    if (markers.length) {
                        this.map.fitBounds(L.featureGroup(markers).getBounds().pad(0.2), { maxZoom: 15 });
                    }
                },

                focusAgent(agentId) {
                    const marker = this.markers[agentId];

                    if (marker) {
                        this.map.flyTo(marker.getLatLng(), Math.max(this.map.getZoom(), 15));
                        marker.openPopup();
                    }

                    this.$wire.selectAgent(agentId);
                },

                clearSelection() {
                    this.map.closePopup();
                    this.$wire.clearSelection();
                },

                renderTrail() {
                    if (this.trailLayer) {
                        this.map.removeLayer(this.trailLayer);
                        this.trailLayer = null;
                    }

                    const agent = this.selected;

                    if (!agent || !this.trail || this.trail.length < 2) {
                        return;
                    }

                    const points = this.trail.map((point) => [point.lat, point.lng]);
                    const first = this.trail[0];

                    this.trailLayer = L.featureGroup([
                        L.polyline(points, { color: '#ffffff', weight: 7, opacity: 0.9 }),
                        L.polyline(points, { color: agent.color, weight: 4 }),
                        ...this.trail.slice(1, -1).map((point) => L.circleMarker([point.lat, point.lng], {
                            radius: 3, color: agent.color, fillColor: '#ffffff', fillOpacity: 1, weight: 2,
                        }).bindTooltip(point.at, { direction: 'top' })),
                        L.circleMarker([first.lat, first.lng], {
                            radius: 7, color: '#ffffff', fillColor: agent.color, fillOpacity: 1, weight: 3,
                        }).bindTooltip(`Started ${escapeHtml(first.at)}`, { permanent: true, direction: 'left', offset: [-8, 0] }),
                    ]).addTo(this.map);

                    this.trailLayer.bringToBack();
                },
            };
        };
    </script>
    @endscript
</x-filament-panels::page>
