# Expo HAS CHANGED

Read the exact versioned docs at https://docs.expo.dev/versions/v57.0.0/ before writing any code.

# SusuApp Mobile — Project Overview

This is the mobile client of **SusuApp**, a susu (rotating savings / micro-finance collection) application. It is a thin client: all business logic, validation, and data live in the Laravel backend. SusuApp ships on three platforms total that must stay in feature parity.

- **Backend / web application** — `c:\xampp\htdocs\Projects\SusuApp`. Laravel 12 + Filament v5 + Livewire. Single source of truth.
- **This app** — Expo / React Native (TypeScript, expo-router). Consumes the backend's versioned REST API (`/api/v1/...`, Sanctum token auth). Always-online; no local database.
- **Desktop application (offline)** — `D:\Desktop App\susuDesktop`. JavaFX (Java 25, Maven). Unlike this app, it keeps a local database for offline use and syncs with the same backend API when reconnected.

## Cross-Platform Parity Rules

1. **No business logic here.** Rules, calculations, and validation belong in the Laravel backend; this app only renders API data and submits requests. Client-side validation may mirror the server's for UX, but the server's is authoritative.
2. **Match the API contract.** When a screen needs new data or behavior, the endpoint must be added/changed in the Laravel repo first (or in the same session). Keep TypeScript types in sync with the API's Eloquent Resource responses.
3. **Feature parity with the web app and the desktop app.** Any user-facing feature added here should exist in the Filament/Livewire web UI and the JavaFX desktop app too (and vice versa). If you change one side only, tell the user explicitly what is pending on the other sides.
