<x-filament-panels::page>
    @include('filament.pages.partials.agent-map-styles')

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
                <a class="at-btn at-btn--primary" :href="selected?.track_url">Track agent</a>
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
                            <button type="button" data-route>Route</button>
                            <a class="at-primary" href="${escapeHtml(agent.track_url)}">Track agent</a>
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
