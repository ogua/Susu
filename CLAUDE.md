@AGENTS.md


## Cross-project reference: mobile app update / version gate

When adding or changing a mobile "please update the app" prompt (version check, force-update,
minimum-supported-version, the `app/update-check` endpoint, or the Filament page that publishes
releases), follow **`.claude/reference/app-update-reference.md`** in this repo — the frozen API
contract plus full backend and Expo-client implementation. Also available as the personal Claude
skill `app-update-feature` (loads in every project).
