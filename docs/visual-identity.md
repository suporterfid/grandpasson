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
| `--color-danger`, `--color-warning`, `--color-success` | Corresponding `*-fg` token |

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
for existing markup. The active type scale is caption `12/16`, UI `14/20`,
body `16/24`, section `20/28`, subheading `24/32`, title `32/40`, and display
`44/52` (pixels). The approved weights are 400, 500, 600, and 700; font
synthesis is disabled. Code disables ligatures, while ordinary prose keeps
normal shaping and ligatures.

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

The current page wrapper is `.prose`: it has a 720px reading measure,
16px-plus-safe-area mobile gutters, and 20px-plus-safe-area gutters from
480px. It uses block/inline padding so direction is structural, not visual.
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

Before release, manually verify light/dark/system changes (including OS theme
changes), keyboard focus, forced colours, reduced motion, 320px/400% reflow,
200% text and browser zoom, long `en-XA` strings, RTL `ar-XB`, mixed-direction
IDs/emails, and CJK/Arabic/Thai/Devanagari input composition. Check both
web-root and configured base-path asset delivery.
