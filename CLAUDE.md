# SusuApp Desktop — Project Overview

This is the **offline desktop client** of **SusuApp**, a susu (rotating savings / micro-finance collection) application. It is a JavaFX application (Java 25, Maven — run with `mvn clean javafx:run`). Unlike the mobile app, this client is designed to keep working while disconnected: it reads/writes local storage and syncs with the Laravel backend when a connection is available. The Laravel backend remains the **single source of truth** that local data reconciles against.

- **Backend / web application** — `c:\xampp\htdocs\Projects\SusuApp`. Laravel 12 + Filament v5 + Livewire. Owns all business logic, validation, and the canonical database.
- **Mobile application** — `D:\Mobile\susu-mobile-app`. Expo / React Native (TypeScript, expo-router). Thin client with no local database; always-online, consumes the same versioned REST API.
- **This app** — JavaFX desktop client. Only client with local persistence, to support offline use. Package scaffolding under `src/main/java/`: `db`, `db.connection`, `db.migration`, `models`, `service`, `utility` (currently empty — the local storage/sync layer has not been built yet).

## Cross-Platform Parity Rules

1. **No business logic here.** Rules, calculations, and validation belong in the Laravel backend. This app renders local/cached data and submits requests; any local validation is a UX convenience that mirrors the server's, never a substitute for it.
2. **Match the API contract.** When a screen needs new data or behavior, the endpoint must be added/changed in the Laravel repo first (or in the same session). Keep local models/DTOs in sync with the API's Eloquent Resource responses.
3. **Offline-first, sync on reconnect.** Reads/writes should hit local storage first so the app remains usable offline, then reconcile with the backend API when connectivity returns. Design local schema changes to map cleanly onto the backend's data shape — don't invent fields that have no server-side equivalent without discussing versioning.
4. **Feature parity with web and mobile.** Any user-facing feature added here should also exist in the Filament/Livewire web UI and the mobile app (and vice versa). If you change one side only, tell the user explicitly what is pending on the others.
5. **Never break the API contract silently.** This app's releases lag behind the server like the mobile app's do. Additive API changes (new fields, new endpoints) are safe to adopt directly; renaming/removing fields or changing types requires a new API version (`/api/v2/...`) or explicit user approval.
