<x-filament-panels::page>
    <div
        x-data="agentTrackingMap()"
        x-init="init()"
        wire:poll.10s="refreshPositions"
        class="fi-section rounded-xl border border-gray-200 bg-white p-4 dark:border-gray-700 dark:bg-gray-900"
    >
        <div x-ref="mapEl" wire:ignore style="height: 480px; border-radius: 0.75rem; z-index: 0;"></div>

        <div
            x-show="selected"
            x-transition
            x-cloak
            class="mt-4 flex flex-col gap-3 rounded-xl border border-gray-200 p-4 dark:border-gray-700 sm:flex-row sm:items-start"
        >
            <template x-if="selected?.photo_url">
                <img :src="selected.photo_url" class="h-16 w-16 rounded-full object-cover" alt="" />
            </template>
            <template x-if="!selected?.photo_url">
                <div class="flex h-16 w-16 items-center justify-center rounded-full bg-gray-100 text-lg font-semibold text-gray-500 dark:bg-gray-800 dark:text-gray-400">
                    <span x-text="selected?.name?.charAt(0) ?? '?'"></span>
                </div>
            </template>

            <div class="flex-1">
                <p class="text-base font-semibold text-gray-950 dark:text-white" x-text="selected?.name"></p>
                <p class="text-sm text-gray-500 dark:text-gray-400" x-text="selected?.phone ?? 'No phone on file'"></p>
                <p class="mt-1 text-sm">
                    <span
                        class="inline-flex items-center rounded-full px-2 py-0.5 text-xs font-medium"
                        :class="selected?.on_duty ? 'bg-success-100 text-success-700 dark:bg-success-500/10 dark:text-success-400' : 'bg-gray-100 text-gray-600 dark:bg-gray-800 dark:text-gray-400'"
                        x-text="selected?.on_duty ? 'On duty' : 'Off duty'"
                    ></span>
                    <span class="ml-2 text-xs text-gray-500 dark:text-gray-400" x-text="'Last seen ' + (selected?.located_at_human ?? 'never')"></span>
                </p>
                <p class="mt-2 text-sm text-gray-700 dark:text-gray-300">
                    <span x-text="selected?.collections_count ?? 0"></span> collections today —
                    <span x-text="selected?.collections_total_formatted ?? 'GHS 0.00'"></span>
                </p>
            </div>

            <button
                type="button"
                @click="$wire.clearSelection()"
                class="self-start rounded-lg px-3 py-1.5 text-sm font-medium text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-800"
            >
                Close
            </button>
        </div>
    </div>

    <div class="mt-6">
        {{ $this->table }}
    </div>

    @script
    <script>
        window.agentTrackingMap = function () {
            return {
                map: null,
                markers: {},
                trailLine: null,
                positions: @entangle('positions'),
                selectedAgentId: @entangle('selectedAgentId'),
                trail: @entangle('trail'),

                get selected() {
                    return (this.positions ?? []).find((p) => p.id === this.selectedAgentId) ?? null;
                },

                init() {
                    if (this.map) {
                        return;
                    }

                    const defaultCenter = [5.6037, -0.1870]; // Accra fallback

                    this.map = L.map(this.$refs.mapEl).setView(defaultCenter, 12);

                    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                        attribution: '&copy; OpenStreetMap contributors',
                        maxZoom: 19,
                    }).addTo(this.map);

                    this.renderMarkers();

                    this.$watch('positions', () => this.renderMarkers());
                    this.$watch('trail', () => this.renderTrail());
                },

                renderMarkers() {
                    const seen = new Set();

                    for (const position of this.positions ?? []) {
                        seen.add(position.id);
                        const color = position.stale ? '#9ca3af' : (position.on_duty ? '#22c55e' : '#3b82f6');

                        if (this.markers[position.id]) {
                            this.markers[position.id].setLatLng([position.lat, position.lng]);
                            this.markers[position.id].setStyle({ fillColor: color, color });
                        } else {
                            const marker = L.circleMarker([position.lat, position.lng], {
                                radius: 9,
                                fillColor: color,
                                color,
                                weight: 2,
                                fillOpacity: 0.85,
                            }).addTo(this.map);

                            marker.on('click', () => {
                                this.map.flyTo([position.lat, position.lng], 15);
                                this.$wire.selectAgent(position.id);
                            });

                            this.markers[position.id] = marker;
                        }
                    }

                    for (const id of Object.keys(this.markers)) {
                        if (!seen.has(id)) {
                            this.map.removeLayer(this.markers[id]);
                            delete this.markers[id];
                        }
                    }

                    if (Object.keys(this.markers).length && !this.hasFitBounds) {
                        const group = L.featureGroup(Object.values(this.markers));
                        this.map.fitBounds(group.getBounds().pad(0.3));
                        this.hasFitBounds = true;
                    }
                },

                renderTrail() {
                    if (this.trailLine) {
                        this.map.removeLayer(this.trailLine);
                        this.trailLine = null;
                    }

                    if (this.trail && this.trail.length > 1) {
                        this.trailLine = L.polyline(
                            this.trail.map((point) => [point.lat, point.lng]),
                            { color: '#f59e0b', weight: 3, dashArray: '6 6' },
                        ).addTo(this.map);
                    }
                },
            };
        };
    </script>
    @endscript
</x-filament-panels::page>
