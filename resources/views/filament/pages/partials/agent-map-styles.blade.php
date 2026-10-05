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
    .at-btn--primary { background: var(--agent, #2563eb); border-color: var(--agent, #2563eb); color: #fff; text-decoration: none; }
    .at-btn--primary:hover { background: var(--agent, #2563eb); opacity: .9; }
    .at-pop__actions a.at-primary { background: var(--agent); border-color: var(--agent); color: #fff; }

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
