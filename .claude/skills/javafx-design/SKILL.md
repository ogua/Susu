---
name: javafx-design
description: Design system and UI conventions for every JavaFX screen (FXML + controller) in susuDesktop. Apply whenever creating or editing an FXML view, its controller, or a stylesheet in this project.
---

# SusuApp Desktop — JavaFX Design System

## When to apply

Apply this skill whenever creating or editing **any** FXML screen, dialog, or stylesheet under
`src/main/resources/com/ogua/susudesktop/`, or the controller/navigation code that drives them.
This is the desktop client's equivalent of the web app's Filament conventions — the same
question ("does this follow house style?") applies here.

## Stack (do not assume MaterialFX — it is not a dependency)

- Java 25 (pom currently pins JavaFX 21 artifacts), Maven, `mvn clean javafx:run`
- `javafx-controls` / `javafx-fxml` / `javafx-web` — plain JavaFX controls, no Material component
  library
- `bootstrapfx-core` — supplies `bootstrapfx.css` (Bootstrap-style base classes: `.btn`, `.h1`-`.h6`,
  `.text-muted`, `.panel`, etc.)
- `ikonli-javafx` (FontAwesome/Material packs available via `requires org.kordamp.ikonli.javafx`
  in `module-info.java`) for icons
- `formsfx-core` / `validatorfx` for form building and validation where a screen outgrows plain
  FXML forms
- `tilesfx` for dashboard tile/KPI widgets
- No MaterialFX, no ControlsFX. If a future screen genuinely needs a control neither JavaFX,
  BootstrapFX, nor FormsFX/ValidatorFX provide, propose the new dependency and its `module-info.java`
  `requires` entry to the user before adding it — don't add UI libraries unilaterally.

## Design tokens — `styles/design-system.css`

All colors are JavaFX **looked-up colors**: declared once on `.root`, referenced elsewhere as a
bare `-ogua-*` name (no `var()`, no leading `--` — that's web CSS syntax, not JavaFX CSS).

| Token | Value | Use |
|---|---|---|
| `-ogua-primary` | `#F59E0B` | Primary actions, focus rings. Matches the web app's Filament panel color (`Color::Amber` in `AdminPanelProvider`/`SuperAdminPanelProvider`) — keep it in sync if the web brand color ever changes. |
| `-ogua-primary-dark` | `#B45309` | Hover/pressed state of primary elements |
| `-ogua-secondary` | `#475569` | Secondary buttons, muted chrome |
| `-ogua-success` / `-ogua-success-bg` / `-ogua-success-text` | `#16A34A` / `#DCFCE7` / `#166534` | Positive states (paid, active, synced) |
| `-ogua-danger` / `-ogua-danger-bg` / `-ogua-danger-text` | `#DC2626` / `#FEE2E2` / `#991B1B` | Errors, destructive actions, overdue |
| `-ogua-warning` / `-ogua-warning-bg` / `-ogua-warning-text` | `#EA580C` / `#FFEDD5` / `#9A3412` | Pending/needs-attention states |
| `-ogua-neutral-bg` / `-ogua-neutral-text` | `#F1F5F9` / `#475569` | Neutral pills, inactive states |
| `-ogua-surface` | `#FFFFFF` | Cards, inputs, table rows |
| `-ogua-bg` | `#F8FAFC` | Screen/window background |
| `-ogua-border` | `#E2E8F0` | Borders, dividers |
| `-ogua-text-primary` | `#0F172A` | Body/heading text |
| `-ogua-text-secondary` | `#64748B` | Muted/caption text |

If a screen needs a token that doesn't exist, add it to `.root` in `design-system.css` — never
hardcode a hex value inline in an FXML `style="..."` attribute or a screen-specific stylesheet.

## Spacing (JavaFX layout, not CSS)

JavaFX containers control spacing via FXML attributes (`spacing`, `padding`/`Insets`, `hgap`/`vgap`),
not CSS classes. Use these values consistently instead of ad hoc numbers:

| Token | Value | Use |
|---|---|---|
| Small | `8` | Control-to-control spacing within a form row (`hgap`/`vgap` on `GridPane`) |
| Base | `16` | Field spacing (`spacing` on a form `VBox`), card internal `padding` |
| Large | `24` | Section spacing, outer screen `padding` |

## Typography classes — `styles/design-system.css`

| Class | Use |
|---|---|
| `page-title` | Screen/window title (one per screen) |
| `section-title` | Section headers within a screen (e.g. "1. Storage" in setup) |
| `card-title` | Title inside a `card` |
| `body-text` | Default readable text |
| `text-muted` / `caption` | De-emphasized text, hints, secondary labels |
| `text-danger` / `text-success` | Inline status text |

Never set `-fx-font-size`/`-fx-font-weight` ad hoc in a screen — use one of these classes so
hierarchy stays consistent app-wide.

## Component classes — `styles/components.css`

| Class | Use |
|---|---|
| `button-primary` | The one primary action per screen/dialog (save, sign in, finish) |
| `button-secondary` | Cancel, back, sign out — anything not the primary action |
| `button-danger` | Destructive actions (delete, deactivate) |
| `card` | Any grouped content block — surface + border + radius + padding |
| `status-pill` + one of `-success`/`-danger`/`-warning`/`-neutral` | Compact status badges in tables/lists |
| `topbar` | The app's top chrome bar (see `main-view.fxml`) |
| `empty-state-title` / `empty-state-message` | "Nothing here yet" placeholder screens |
| `table-view` (on `TableView`) | Applies header/row striping consistent with the palette |

`RadioButton` and `CheckBox` are re-skinned to the brand accent (`-ogua-primary`) automatically via
base selectors in `components.css` — no extra `styleClass` needed on them.

Every screen must load, in this order: BootstrapFX → `design-system.css` → `components.css`. This
already happens automatically in `Navigator.show()` — do not call `FXMLLoader`/build a `Scene`
directly in a controller; always go through `Navigator` so the stylesheets and window sizing stay
centralized.

## Layout rules

- Prefer `BorderPane` (top/center chrome) → `VBox`/`HBox` (flow) → `GridPane` (aligned form rows).
  Avoid `AnchorPane` unless pixel-precise overlap is genuinely required.
- Wrap grouped content in a `card`-styled container rather than letting controls float directly on
  the background.
- Forms with more than ~4 fields: use `GridPane` with aligned labels in column 0, inputs in column
  1 (see `setup-view.fxml`), not one long vertical stack of label+field pairs.
- Every screen needs exactly one primary action (`button-primary`); everything else is
  `button-secondary` or `button-danger`.

## Icons

Ikonli's core (`ikonli-javafx`) is a dependency but **no glyph pack is** (no FontAwesome5/
MaterialDesign2 pack in `pom.xml` yet) — `FontIcon iconLiteral="fas-..."` will not resolve until
one is added. Propose adding a pack (new `pom.xml` dependency + `module-info.java` `requires` +
`opens ... to org.kordamp.ikonli.javafx` for its ServiceLoader) to the user before using icons;
until then, achieve visual hierarchy with typography/color/spacing, not icons.

## Brand imagery — `images/`

`src/main/resources/com/ogua/susudesktop/images/` holds `susu-logo.png` (the circular green/teal
leaf-and-rings mark) and `app-background.jpg` (login backdrop photo, kept at 1920px wide / ~260KB —
**always resize/recompress a source photo before adding it here**; do not commit multi-MB originals,
they bloat the bundled app for no visual benefit at desktop-window sizes).

The logo is intentionally **not** recolored to match `-ogua-primary` (amber) — the user confirmed
the amber accent (from the web app's Filament panel color) and the logo's own green/teal stay
independent; don't try to reconcile them into one hue.

- Logo usage: `<ImageView fitWidth="…" fitHeight="…" preserveRatio="true" smooth="true"><Image
  url="@images/susu-logo.png"/></ImageView>` — present on the login card (64px), the setup header
  (48px), the main topbar (28px), and as the window/taskbar icon (`Navigator.APP_ICON`, set via
  `stage.getIcons()` in `Navigator.show()`). Any new top-level screen should follow the same
  pattern rather than going logo-less.
- Background image usage: `login-view.fxml` roots on a `StackPane` with two full-bleed `Region`
  layers stacked before the content — `.login-background-image` (the photo, `-fx-background-size:
  cover`) then `.login-scrim` (a dark gradient, `rgba(15,23,42,0.55)` → `rgba(15,23,42,0.25)`) —
  followed by the centered card `VBox`. **Do not** try to combine an image and a color into one
  `-fx-background-image`/`-fx-background-color` multi-layer declaration on a single node — JavaFX
  CSS has no `none` placeholder for a background-image layer, so a mixed list like
  `-fx-background-image: none, url(...)` silently fails to parse and the image never renders (hit
  this exact bug once already). Two stacked `Region`s is the reliable pattern; reuse it for any
  other full-bleed photo background.

## Responsiveness — every screen must survive being resized down to its stage min size

- Any screen whose content can plausibly exceed the window's minimum height (more than ~1 card, or
  any form with more than a couple of fields) must wrap its content in a `ScrollPane`
  (`fitToWidth="true"`) — see `setup-view.fxml` (whole screen scrolls) and `main-view.fxml` (only
  `<center>` scrolls, `<top>` stays pinned). Short single-card screens like `login-view.fxml` don't
  need one.
- `ScrollPane` gets no extra styling in FXML — `components.css` already makes `.scroll-pane` and
  its viewport/corner transparent so it blends into `-ogua-bg` instead of showing default gray
  chrome.
- Every `GridPane` form must declare `columnConstraints`: a fixed-width label column
  (`minWidth`/`prefWidth` ~100) and an `hgrow="ALWAYS"` input column, with `maxWidth="Infinity"` on
  each `TextField`/`PasswordField`/`ComboBox` in it — otherwise fields stay pinned at their pref
  width and the form doesn't actually use extra horizontal space when the window is widened.
- `Navigator.show(...)` sets a `stage.setMinWidth`/`setMinHeight` per screen — never let a screen's
  content assume more space than that minimum guarantees.

## Motion

`Navigator.show(...)` calls `Animations.fadeInScreen(root)` (fade + 8px slide-up, ~240ms) on every
screen swap and `Animations.attachPressFeedback(node)` on every `.button`-styled node (subtle
scale-down on press) automatically — don't hand-roll per-controller animation code. If a screen
needs a new interaction pattern (e.g. a card hover lift, a toast/snackbar entrance), add a method to
`Animations.java` and call it from `Navigator` or the controller, rather than inlining a
`Transition` in a controller ad hoc.

## Navigation contract (current — small, will need to evolve)

Today, `Navigator.show(stage, fxml, width, height)` does a **full scene replacement per screen**:
each screen gets its own fixed `Scene` size and there is no persistent shell/sidebar or
background-thread FXML loading yet. This is fine at 3 screens (login, setup, main). If/when the
back office grows past a handful of screens, revisit this with the user before building a shell —
don't silently introduce a different navigation pattern (e.g. a `mainLayout.setContent()` swap)
without discussing it, since every existing controller depends on `Navigator`'s current contract.

## Multi-tenancy / session

Every screen that displays data scoped to the logged-in user must go through
`SessionManager.getCurrentUser()` / the `service` layer's existing filtering — never bypass it for
a "quick" table binding.

## What NOT to do

- Do not add MaterialFX, ControlsFX, or any other new UI dependency without user approval — see
  Stack above.
- Do not hardcode hex colors in FXML `style="..."` or screen-specific CSS — add a token to
  `design-system.css` instead.
- Do not build a `Scene` or call `FXMLLoader` outside `Navigator` — it breaks the shared
  stylesheet loading.
- Do not use BootstrapFX's raw heading classes (`h1`-`h6`) directly in new screens — use this
  skill's typography classes (`page-title`, `section-title`, etc.) so hierarchy stays token-driven
  and consistent with the color palette above.
- Do not claim a redesign works without actually running `mvn clean javafx:run` and clicking
  through it — there is no automated test suite for JavaFX views in this project, and Claude has
  no way to screenshot a native desktop window, so manual verification by the user is required
  before considering a screen done.
