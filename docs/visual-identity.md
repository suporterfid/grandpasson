# GrandpaSSOn visual identity adoption record

GrandpaSSOn uses a quiet, content-first visual system for its authentication
and administration surfaces. This is an adoption record for the approved
[canonical visual identity specification](../../notion-inspired-visual-identity-spec.md).
That file is provenance for the policy; GrandpaSSOn does not read, serve, or
otherwise depend on it at runtime. The deployable contract is the checked-in
stylesheet, font asset, and theme runtime described below.

The system takes only high-level inspiration from modern workspace products.
It does not use or imply use of Notion trademarks, assets, copy, screenshots,
or proprietary fonts.

## Scope and implementation boundary

`public_html/assets/theme.css` is the public CSS token and component contract.
Server-rendered controllers use its semantic classes: `.prose`, `.card`,
`.action-list`, `.btn`, `.button`, form controls, and the optional
`.app-shell`, `.data-table-region`, and `.data-view` layout hooks.
`GrandpaSSOn\Support\Html` is the shared document shell. It emits the
stylesheet and the head-loaded `public_html/assets/theme.js` runtime, with
base-path-aware `/assets/` URLs.

Components must consume semantic variables or their documented compatibility
aliases. Component styles must not introduce literal palette colours.

## Colour tokens and themes

The following values are exact sRGB values. The light block is `:root` and
`[data-theme='light']`; the dark block is `[data-theme='dark']`.

| Token | Light | Dark | Meaning |
|---|---:|---:|---|
| `--color-bg-canvas` | `#FFFFFF` | `#191919` | Page canvas |
| `--color-bg-surface` | `#F7F6F3` | `#202020` | Secondary surface |
| `--color-bg-elevated` | `#FFFFFF` | `#252525` | Raised card/menu surface |
| `--color-bg-hover` | `#EFEDEA` | `#2C2C2C` | Neutral hover fill |
| `--color-bg-selected` | `#E7F0FA` | `#123B60` | Selected item fill |
| `--color-text-primary` | `#252525` | `#F1F1EF` | Primary text |
| `--color-text-secondary` | `#5F5F5F` | `#C6C6C2` | Supporting text |
| `--color-text-disabled` | `#929292` | `#888884` | Non-essential disabled text |
| `--color-text-inverse` | `#FFFFFF` | `#191919` | Inverse text |
| `--color-text-link` | `#0F5EAB` | `#79B8E8` | Underlined link text |
| `--color-border-default` | `#D9D7D3` | `#4A4A4A` | Decorative divider |
| `--color-border-strong` | `#8A8882` | `#6E6E6E` | Control boundary |
| `--color-action-primary` | `#1A6DC1` | `#529CCA` | Primary action fill |
| `--color-action-primary-hover` | `#14599E` | `#70B4DE` | Primary-action hover |
| `--color-action-primary-active` | `#104B86` | `#3E83B5` | Primary-action pressed |
| `--color-action-primary-content` | `#FFFFFF` | `#111111` | Content on primary action |
| `--color-action-primary-subtle` | `#E7F0FA` | `#173755` | Quiet action background |
| `--color-focus-ring` | `#1A6DC1` | `#79B8E8` | Focus ring |
| `--color-success-fg` | `#126B3A` | `#7CDA9A` | Success text/icon |
| `--color-success-bg` | `#F1FAF4` | `#13291C` | Success background |
| `--color-success-border` | `#7CCB98` | `#34794C` | Success boundary |
| `--color-warning-fg` | `#7A4A00` | `#F5C775` | Warning text/icon |
| `--color-warning-bg` | `#FFF7E6` | `#33250D` | Warning background |
| `--color-warning-border` | `#F0B35A` | `#8D6418` | Warning boundary |
| `--color-danger-fg` | `#B42318` | `#F4A49E` | Danger text/icon |
| `--color-danger-bg` | `#FFF1F0` | `#381B1B` | Danger background |
| `--color-danger-border` | `#F29A93` | `#8E4540` | Danger boundary |
| `--color-info-fg` | `#0F5EAB` | `#9DCCF2` | Information text/icon |
| `--color-info-bg` | `#EDF5FE` | `#102B45` | Information background |
| `--color-info-border` | `#85BCEB` | `#3D78AA` | Information boundary |

`color-scheme: light` and `color-scheme: dark` accompany their resolved
token blocks so native controls match the active surface. Existing
GrandpaSSOn component names remain aliases, not an alternate palette:

| Compatibility alias | Resolves to |
|---|---|
| `--color-canvas` | `--color-bg-canvas` |
| `--color-surface` | `--color-bg-surface` |
| `--color-surface-emphasis` | `--color-bg-elevated` |
| `--color-text` | `--color-text-primary` |
| `--color-text-muted` | `--color-text-secondary` |
| `--color-border` | `--color-border-default` |
| `--color-action` | `--color-action-primary` |
| `--color-action-hover` | `--color-action-primary-hover` |
| `--color-focus` | `--color-focus-ring` |
| `--color-danger` | `--color-danger-fg` |
| `--color-warning` | `--color-warning-fg` |
| `--color-success` | `--color-success-fg` |
| `--font-sans` | `--font-ui` |
| `--font-mono` | `--font-code` |

### Measured contrast

Ratios below were calculated with the WCAG relative-luminance formula from
the exact token values above. Text and status pairs require at least 4.5:1;
focus rings and strong boundaries are non-text indicators requiring at least
3:1. “Pass” means the stated pair meets its applicable threshold.

| Theme | Pair | Ratio | Threshold | Result |
|---|---|---:|---:|---|
| Light | Primary text / canvas | 15.33:1 | 4.5:1 | Pass |
| Light | Secondary text / canvas | 6.39:1 | 4.5:1 | Pass |
| Light | Primary text / surface | 14.18:1 | 4.5:1 | Pass |
| Light | Secondary text / surface | 5.91:1 | 4.5:1 | Pass |
| Light | Primary text / elevated | 15.33:1 | 4.5:1 | Pass |
| Light | Secondary text / elevated | 6.39:1 | 4.5:1 | Pass |
| Light | Link / canvas | 6.54:1 | 4.5:1 | Pass |
| Light | Primary-action content / default | 5.25:1 | 4.5:1 | Pass |
| Light | Primary-action content / hover | 7.11:1 | 4.5:1 | Pass |
| Light | Primary-action content / active | 8.85:1 | 4.5:1 | Pass |
| Light | Success foreground / background | 6.18:1 | 4.5:1 | Pass |
| Light | Warning foreground / background | 7.02:1 | 4.5:1 | Pass |
| Light | Danger foreground / background | 5.98:1 | 4.5:1 | Pass |
| Light | Info foreground / background | 5.95:1 | 4.5:1 | Pass |
| Light | Focus ring / canvas | 5.25:1 | 3:1 | Pass |
| Light | Strong boundary / canvas | 3.54:1 | 3:1 | Pass |
| Dark | Primary text / canvas | 15.55:1 | 4.5:1 | Pass |
| Dark | Secondary text / canvas | 10.26:1 | 4.5:1 | Pass |
| Dark | Primary text / surface | 14.41:1 | 4.5:1 | Pass |
| Dark | Secondary text / surface | 9.51:1 | 4.5:1 | Pass |
| Dark | Primary text / elevated | 13.55:1 | 4.5:1 | Pass |
| Dark | Secondary text / elevated | 8.95:1 | 4.5:1 | Pass |
| Dark | Link / canvas | 8.23:1 | 4.5:1 | Pass |
| Dark | Primary-action content / default | 6.26:1 | 4.5:1 | Pass |
| Dark | Primary-action content / hover | 8.34:1 | 4.5:1 | Pass |
| Dark | Primary-action content / active | 4.60:1 | 4.5:1 | Pass |
| Dark | Success foreground / background | 9.07:1 | 4.5:1 | Pass |
| Dark | Warning foreground / background | 9.43:1 | 4.5:1 | Pass |
| Dark | Danger foreground / background | 7.94:1 | 4.5:1 | Pass |
| Dark | Info foreground / background | 8.50:1 | 4.5:1 | Pass |
| Dark | Focus ring / canvas | 8.23:1 | 3:1 | Pass |
| Dark | Strong boundary / canvas | 3.45:1 | 3:1 | Pass |

### Theme preference precedence

The only stored preference values are `light`, `dark`, and `system`, under
the `grandpasson.theme` local-storage key. A valid explicit `light` or `dark`
value wins over operating-system changes. `system`, an absent value, an
invalid stored value, or unavailable storage resolve from
`prefers-color-scheme`. The resolved `data-theme` is updated when the media
query changes while the preference remains `system`.

`theme.js` runs in the document head before the stylesheet link, sets the
root `data-theme`, and updates the `theme-color` meta element to the canvas
colour. The runtime also builds the accessible radio-group switcher after
the document is ready. It persists only a user selection; it does not write a
default. Its English and Brazilian Portuguese switcher labels are runtime
strings, not image text.

## Typography and assets

The UI stack is exactly:

```css
Inter, "Noto Sans", "Noto Sans Arabic", "Noto Sans Hebrew", "Noto Sans SC",
"Noto Sans TC", "Noto Sans JP", "Noto Sans KR", "Noto Sans Thai",
"Noto Sans Devanagari", Arial, sans-serif
```

`public_html/assets/fonts/inter-4.1-variable.woff2` is the selected,
self-hosted Inter 4.1 variable roman face. Its provenance and licence are
recorded in `public_html/assets/fonts/INTER-LICENSE.txt`: the pinned Inter
4.1 release archive, `web/InterVariable.woff2` archive member, and the SIL
Open Font License notice. `theme.css` declares it with `font-display: swap`,
weight range `100 900`, and a relative same-origin URL. It is not CDN-loaded.

The semantic fallback stacks are `--font-ui`, `--font-editorial`, and
`--font-code`; `--font-sans` and `--font-mono` alias the UI and code stacks
for existing markup. The exact active stacks and scale are:

| Token | Active value |
|---|---|
| `--font-ui` | `Inter, "Noto Sans", "Noto Sans Arabic", "Noto Sans Hebrew", "Noto Sans SC", "Noto Sans TC", "Noto Sans JP", "Noto Sans KR", "Noto Sans Thai", "Noto Sans Devanagari", Arial, sans-serif` |
| `--font-editorial` | `"Source Serif 4", "Noto Serif", "Noto Naskh Arabic", "Noto Serif Hebrew", "Noto Serif SC", "Noto Serif TC", "Noto Serif JP", "Noto Serif KR", Georgia, serif` |
| `--font-code` | `"IBM Plex Mono", "Noto Sans Mono", "Noto Sans SC", "Noto Sans TC", "Noto Sans JP", "Noto Sans KR", monospace` |

| Role | Token | Size / line-height | Weight |
|---|---|---:|---:|
| Caption / metadata | `--text-caption` | `12px / 16px` | 400–500 |
| Compact UI | `--text-ui` | `14px / 20px` | 400–600 |
| Body | `--text-body` | `16px / 24px` | 400 |
| Section title | `--text-section` | `20px / 28px` | 600 |
| Page subheading | `--text-subheading` | `24px / 32px` | 600 |
| Page title | `--text-title` | `32px / 40px` | 700 |
| Display title | `--text-display` | `44px / 52px` | 700 |

The approved weights are 400, 500, 600, and 700; font synthesis is disabled.
Code disables ligatures, while ordinary prose keeps normal shaping and
ligatures.

## Spatial, elevation, and motion contract

| Spacing token | Value | Spacing token | Value |
|---|---:|---|---:|
| `--space-0` | `0` | `--space-1` | `4px` |
| `--space-2` | `8px` | `--space-3` | `12px` |
| `--space-4` | `16px` | `--space-5` | `20px` |
| `--space-6` | `24px` | `--space-7` | `32px` |
| `--space-8` | `40px` | `--space-9` | `48px` |
| `--space-10` | `64px` | | |

| Shape or boundary | Exact active value |
|---|---:|
| Inline radius (`--radius-inline`) | `2px` |
| Control radius (`--radius-sm`) | `4px` |
| Card radius (`--radius-md`) | `6px` |
| Dialog radius (`--radius-lg`) | `8px` |
| Pill radius (`--radius-pill`) | `999px` |
| Default border | `1px` |
| Focus and invalid emphasis | `2px` |
| Focus offset | `2px` |

| Elevation token | Exact shadow |
|---|---|
| `--shadow-0` | `none` |
| `--shadow-1` | `0 1px 2px rgb(0 0 0 / 0.08)` |
| `--shadow-2` | `0 4px 12px rgb(0 0 0 / 0.14)` |
| `--shadow-3` | `0 12px 28px rgb(0 0 0 / 0.18)` |

| Layer | Active z-index | Deployed use |
|---|---:|---|
| Normal document flow | `auto` | Page content and cards |
| Fixed theme switcher | `10` | `.theme-switcher` |
| Other overlay layers | Not currently declared | Future menus, dialogs, toasts, and sidebars must reserve canonical layers without reusing `10` |

| Icon role | Required size |
|---|---:|
| Inline icon | `16px` |
| Control icon | `20px` |
| Standalone icon | `24px` |

Current GrandpaSSOn pages do not ship a general icon component; future SVG
icons use `currentColor`, no fill unless meaningful, and a 1.5 or 2 stroke.

| Motion token or rule | Exact active value |
|---|---|
| `--duration-feedback` | `120ms` |
| `--duration-control` | `180ms` |
| `--ease-standard` | `cubic-bezier(0.2, 0, 0, 1)` |
| Reduced motion | Animation/transition duration `1ms !important`; one iteration; `scroll-behavior: auto !important` |

No entry/exit or layout-expansion animation is currently deployed. If one is
introduced, it must use the approved 240ms entry/exit duration and must remain
non-essential under reduced motion.

Legacy Open Sans files remain in the font directory as unreferenced package
artifacts; they are not declared by `theme.css` and are not part of the
GrandpaSSOn visual identity contract.

## Accessibility, direction, and responsive contract

Enabled normal-size text pairs intended for text must meet 4.5:1 contrast;
large text needs 3:1. Primary-action content and status foregrounds are
paired with their matching semantic backgrounds at the normal-text floor.
Focus rings and visible control boundaries meet the applicable 3:1 non-text
floor. Disabled text is non-essential only. Status always includes text or
an accessible name (and, where helpful, an icon or shape); colour is never
the only signal. Links remain visibly underlined in every state.

All new layout work uses logical properties. `--safe-inline-start` and
`--safe-inline-end` map physical safe-area insets, and `[dir='rtl']` swaps
them before `.prose` or `.app-shell` consumes them. This preserves asymmetric
safe areas under RTL. Set `dir` from the locale or content context, use
`dir="auto"`/`<bdi>` for mixed-direction identifiers, and mirror only
directional arrows, chevrons, disclosures, and navigation. Do not mirror
logos, media controls, charts, clocks, numbers, code, photos, search, close,
check, settings, edit, or delete icons.

All visible text must come from complete messages rather than concatenated
fragments or imagery. Let controls and messages grow: short labels must allow
2x English length and general copy 30% expansion. Preserve IME composition;
do not validate, transform, submit, or discard a composing CJK, Arabic,
Thai, or Devanagari value. Use the supplied Noto fallbacks (and nearest
`lang`) for scripts that need them; do not add Arabic letter spacing or
per-character wrappers.

| Viewport | Active measure and behavior |
|---|---|
| `<480px` | `.prose` has `720px` max inline size, `max(16px, safe-inline-start/end)` gutters, and `max(48px, top/bottom safe area)` block padding. The fixed switcher anchors 16px-or-safe-area from inline end and block end. |
| `480–767px` | `.prose` increases only its logical inline gutters to `max(20px, safe-inline-start/end)`; its block safe-area padding remains in force. |
| `768–1023px` | `.data-table-region` may own horizontal scrolling; it must be labeled, keyboard-operable, and visibly scrollable. |
| `1024–1279px` | The forward-compatible `.app-shell` uses `max(24px, safe-inline-start/end)` inline padding. GrandpaSSOn currently has no persistent sidebar or top bar. |
| `≥1280px` | `.data-view` may reach `1200px` max inline size; reading content remains `720px`. |

The current page wrapper is `.prose`: it has a 720px reading measure,
16px-plus-safe-area mobile gutters, 20px-plus-safe-area gutters from 480px,
and 48px-plus-safe-area top and bottom padding. It uses block/inline padding
so direction is structural, not visual. The fixed theme switcher uses the
same logical inline-end variable and the bottom safe area.
`.data-view` permits a 1200px measure at 1280px; `.data-table-region` may
scroll horizontally from 768px only when it is a labeled, keyboard-operable
data region with a visible affordance. `pre` is the equivalent code region.
At 320 CSS pixels (including 400% zoom from 1280px), required content must
wrap without page-wide horizontal scrolling, clipping, overlap, or lost
keyboard reachability. The same is required for 200% text resizing and is
checked again at 200% browser zoom.

Controls are compact visually but pointer-operable buttons and form controls
have a 44px minimum block target; buttons also have a 44px minimum inline
target. Focus uses a 2px semantic focus ring with 2px offset. Reduced-motion
mode reduces animation and transition duration to 1ms and restores automatic
scrolling. In forced-colours mode, controls retain native colour adjustment
and cards/buttons remove shadows, so state must also have text, shape, or
focus cues.

## Component mapping

| Existing surface | Contract |
|---|---|
| `.prose` | Centred reading column and responsive safe-area gutter |
| `.card`, `.card--emphasis` | Surface/elevated grouping with semantic borders and restrained elevation |
| `.btn`, `.button`, primary/secondary variants | 44px target, semantic action states, visible focus |
| `.action-list` | Wrapping-safe vertical action stack; child actions fill the available line |
| `input`, `select`, `textarea`, `.control` | Label-above form control, strong boundary, 44px target, invalid outline |
| `a`, `.link` | Semantic link colour and persistent underline |
| `code`, `pre` | Code stack, surface boundary; `pre` is the horizontal-scroll exception |
| `.theme-switcher` | Fixed, logical-end theme radiogroup with selected state |
| `.app-shell`, `.data-table-region`, `.data-view` | Forward-compatible application-shell and data-layout utilities |

The present product is page-oriented authentication and admin UI: it has no
persistent sidebar, breadcrumbs, or application top bar. If those surfaces
are introduced, they must follow the canonical breakpoints (overlay below
768px, collapsible/persistent sidebar from 1024px, wide data only from
1280px) without changing the existing reading measure.

## Runtime delivery and security

All active stylesheet, script, font, and favicon URLs are same-origin and
base-path-aware through `Html::asset()`, so a `/sso` deployment resolves
them under `/sso/assets/`. `Html::pageStart()` sets a strict browser-facing
CSP with `style-src 'self'`, `script-src 'self'`, `font-src 'self'`, and no
inline-script or CDN exception. No runtime request loads the canonical
provenance document or an external font.

## Deviations and follow-up boundaries

| Item | Rationale |
|---|---|
| Inter is self-hosted rather than CDN-loaded. | Authentication pages should not depend on a third-party request; cPanel-style deployments may not have a usable CDN path. |
| Legacy Open Sans font files are retained but unused. | They are harmless release artifacts from the prior identity. Remove them only with a packaging/release audit; active CSS must continue to reference only Inter. |
| No sidebar/top-bar implementation today. | GrandpaSSOn currently renders focused, server-driven flows rather than a workspace shell. The utility hooks and responsive contract prevent a future shell from diverging. |
| Theme labels have English and `pt-BR` strings while the shared document shell currently declares `lang="en"`. | The switcher is prepared for the existing language handling; full locale selection and translated pages remain separate product work. |

## QA

Run the focused CSS contracts and the complete PHP suite from the repository
root:

```powershell
php vendor\bin\phpunit tests\Unit\ThemeCssTest.php
php vendor\bin\phpunit
```

Task 4 owns browser-level QA. Before release it must verify light/dark/system
changes (including OS theme changes), keyboard focus, forced colours, reduced
motion, 320px/400% reflow, 200% text and browser zoom, long `en-XA` strings,
RTL `ar-XB`, mixed-direction IDs/emails, and CJK/Arabic/Thai/Devanagari input
composition. The PHP unit suite verifies rule contracts only; it does not
compute layout, media-query behavior, or browser-resolved safe areas. Check
both web-root and configured base-path asset delivery.
