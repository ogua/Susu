# SusuApp Desktop — Project Overview

This is the **offline desktop client** of **SusuApp**, a susu (rotating savings / micro-finance collection) application. It is a JavaFX application (Java 25, Maven — run with `mvn clean javafx:run`). Unlike the mobile app, this client is designed to keep working while disconnected: it reads/writes local storage and syncs with the Laravel backend when a connection is available. The Laravel backend remains the **single source of truth** that local data reconciles against.

- **Backend / web application** — `c:\xampp\htdocs\Projects\SusuApp`. Laravel 12 + Filament v5 + Livewire. Owns all business logic, validation, and the canonical database.
- **Mobile application** — `D:\Mobile\susu-mobile-app`. Expo / React Native (TypeScript, expo-router). Also offline-capable: field agents work local-first against SQLite (WatermelonDB) with an outbox/sync queue, replaying idempotently through the same `/api/v1/sync/batch` protocol this desktop app uses; the customer experience is online.
- **This app** — JavaFX desktop client. Only client with a full local database, to support offline use. Storage layer (ported from the proven Oguaschoolz desktop): `db.AppConfig` (~/.susudesktop/config.properties: `db.type` sqlite|mysql, `sync.enabled` standalone|hybrid), `db.provider` (DatabaseProvider + SQLite/MySQL Hikari providers + ProviderFactory), `db.migration.MigrationRunner` (V###__*.sql + manifest.txt in resources/migrations, one dialect-adapted file set, pre-migration snapshots), `db.DatabaseConnection` facade, `db.SessionManager`, `service.AuthService` (bcrypt local login + lockout), `models`. Startup: setup wizard (first run) → login → main shell.

## Skills Activation

This project has a design skill at `.claude/skills/javafx-design/SKILL.md`. Activate it whenever
creating or editing an FXML screen, its controller, or a stylesheet — it defines the color tokens,
typography classes, component classes, and layout rules every screen must follow.

## Cross-Platform Parity Rules

1. **The Laravel backend defines the business rules; this app carries a parity port, never a fork.** The desktop supports two modes sharing one codebase: *hybrid* (local DB + background sync) and *standalone* (a fully-offline company; the local operations engine in the `service` package — ledger posting, commission calculation, cycle rollover — is then the running system). Every rule implemented here must be a disciplined, version-stamped (`engine_version`) copy of the corresponding Laravel Action; when the server-side rule changes, change it here in the same session. On sync, the server re-validates everything through its canonical Actions.
2. **Match the API contract.** When a screen needs new data or behavior, the endpoint must be added/changed in the Laravel repo first (or in the same session). Keep local models/DTOs in sync with the API's Eloquent Resource responses.
3. **Offline-first, sync on reconnect.** Reads/writes should hit local storage first so the app remains usable offline, then reconcile with the backend API when connectivity returns. Design local schema changes to map cleanly onto the backend's data shape — don't invent fields that have no server-side equivalent without discussing versioning.
4. **Feature parity with web and mobile.** Any user-facing feature added here should also exist in the Filament/Livewire web UI and the mobile app (and vice versa). If you change one side only, tell the user explicitly what is pending on the others.
5. **Never break the API contract silently.** This app's releases lag behind the server like the mobile app's do. Additive API changes (new fields, new endpoints) are safe to adopt directly; renaming/removing fields or changing types requires a new API version (`/api/v2/...`) or explicit user approval.
