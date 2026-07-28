# Visual Identity Specification

This document has two parts:

1. **Sections 1–14** — the shared Generic Visual Identity Specification. It is
   project-neutral: it does not mention GrandpaSSOn and should read the same
   in any project that adopts it.
2. **GrandpaSSOn appendix** — the decisions the shared spec explicitly leaves
   to each project (status colors, verified contrast pairs) and this
   project's deviation log.

Tracked as `VI1` under [#87](https://github.com/suporterfid/grandpasson/issues/87).

---

## 1. Purpose and principles

This system exists so that every surface a project renders — login screens,
admin tools, error pages, marketing pages — reads as one coherent product,
without requiring a design tool or a component library.

- **Clarity first.** Legibility and comprehension outrank decoration. When a
  choice trades clarity for visual flourish, choose clarity.
- **Consistency across projects.** Two projects that both adopt this spec
  should feel like siblings, not lookalikes copied by hand. That only holds
  if components consume the semantic tokens (§11) instead of hardcoded
  values.
- **Accessible by default, not by retrofit.** Contrast, focus visibility, and
  semantic HTML are load-bearing requirements, not a final QA pass.
- **Minimal dependencies.** The spec is deliverable as plain CSS custom
  properties and a small number of static assets. It does not assume a
  build pipeline, a framework, or a CDN.

## 2. Consistency across projects

Components should consume semantic tokens such as `--color-action`, rather
than raw palette values. A raw hex value hardcoded into a component is a
regression against this spec even if it happens to match a token's current
value — the point is that a future palette revision only has to touch the
token definitions, not every call site.

Two projects adopting this spec will not have visually identical UIs (their
copy, layout, and information architecture differ), but their buttons,
links, focus rings, and error states should behave identically and use the
same relationships between color, weight, and spacing.

## 3. Color

### 3.1 Palette

The system is dark, high-contrast, and purple-accented:

| Token | Role |
|---|---|
| `--color-canvas` | Page background |
| `--color-surface` | Raised panels, cards |
| `--color-surface-emphasis` | Panels that need to stand out from `surface` (callouts, active state) |
| `--color-text` | Primary body text |
| `--color-text-muted` | Secondary / supporting text |
| `--color-text-inverse` | Text placed on a filled `--color-action` background |
| `--color-border` | Default dividers and input borders |
| `--color-border-strong` | Emphasized borders (focus-adjacent, active cards) |
| `--color-action` | Primary interactive color: links, primary buttons, focus rings |
| `--color-action-hover` | Hover/active state for interactive elements |
| `--color-focus` | Focus ring color (may equal `--color-action`) |

### 3.2 Status colors

Status colors (`danger`, `warning`, `success`, and any project-specific
states) are **deliberately not fixed here**. Every project has different
failure modes and a different number of states to represent. Each adopting
project must:

1. Define its own status tokens.
2. Verify each against **both** `--color-canvas` and `--color-surface` — a
   status color is very often rendered on a surface panel, not directly on
   canvas, and the two backgrounds have different luminance.
3. Record the measured contrast ratios next to the token definition.

### 3.3 Color is reinforcement, not the message

Never encode information using only color, position, sound, or motion. A
status must remain fully understandable if viewed in grayscale — pair a
status color with a text label, an icon with an accessible name, or a
position/shape change. This applies to hover states too: a hover affordance
should change more than hue (an underline, a border, a shadow) since color
transitions are invisible to some users.

Any token used for small body text must clear 4.5:1 against the background
it is composed on. A token that clears only 3:1–4.4:1 is usable for large
text (≥ 1.5rem or ≥ 1.2rem bold), iconography, and non-text UI boundaries,
but must not be the color of a paragraph of running copy.

### 3.4 Baseline verified pairs

The shared spec ships these pairs pre-verified so every adopting project
starts from a known-good baseline. Projects extend this table (they do not
replace it) with their own status-color and surface-emphasis pairs — see
the appendix for GrandpaSSOn's extension.

| Foreground | Background | Ratio | Passes |
|---|---|---|---|
| `--color-text` | `--color-canvas` | 17.62:1 | AAA |
| `--color-text-muted` | `--color-canvas` | 9.68:1 | AAA |
| `--color-text` | `--color-surface` | 15.23:1 | AAA |
| `--color-text-muted` | `--color-surface` | 8.37:1 | AAA |
| `--color-text-inverse` | `--color-action` | 5.19:1 | AA |
| `--color-action` | `--color-canvas` | 4.05:1 | Non-text / large-text only |

## 4. Typography

### 4.1 Typeface

Primary typeface: **Open Sans**. Use real font files for each required
weight. Do not synthesize bold or italic styles when the selected font
source does not provide them — a browser-synthesized "fake bold" is visibly
different across platforms and is not an acceptable substitute for shipping
the weight.

| Weight | Name | Typical use |
|---|---|---|
| 400 | Regular | Body copy |
| 500 | Medium | Emphasis within body copy, form labels |
| 600 | Semibold | Subheadings, button labels |
| 700 | Bold | Headings |

### 4.2 Type scale

| Style | Size | Notes |
|---|---|---|
| Display / H1 | `clamp(2rem, 1.6rem + 2vw, 3.2rem)` | Weight 700 |
| H2 | `clamp(1.5rem, 1.3rem + 1vw, 2rem)` | Weight 700 |
| H3 | `1.25rem` | Weight 600 |
| Lead body | `1.2rem` | Weight 400, `--color-text-muted` — used for introductory copy under a heading |
| Body | `1rem` | Weight 400 |
| Small / caption | `0.85rem` | Weight 400, `--color-text-muted` |

### 4.3 Line length and rhythm

Body copy targets 60–75 characters per line at the body size, which in
practice means a reading column of roughly 640–760px (see §5).

### 4.4 Weight discipline

No more than three font weights in a single view. A page that mixes 400,
500, 600, and 700 in one screen has stopped using weight as a signal and
started using it as noise.

## 5. Spacing and layout

An 8px-based spacing scale, exposed as tokens so components stay in
rhythm:

| Token | Value |
|---|---|
| `--space-1` | 4px |
| `--space-2` | 8px |
| `--space-3` | 12px |
| `--space-4` | 16px |
| `--space-5` | 24px |
| `--space-6` | 32px |
| `--space-7` | 48px |
| `--space-8` | 64px |

A `.prose` reading column is 640–760px wide, centered, with responsive
horizontal padding so it never touches the viewport edge on narrow screens.

## 6. Radius and elevation

| Token | Value |
|---|---|
| `--radius-sm` | 4px |
| `--radius-md` | 8px |
| `--radius-lg` | 16px |

Elevation is communicated with `--color-surface` / `--color-surface-emphasis`
layering and border color, not with drop shadows — shadows read poorly on a
near-black canvas and are the first thing lost when a user forces high
contrast mode.

## 7. Components

**Buttons.** Primary buttons use `--color-action` fill and
`--color-text-inverse` label text; secondary buttons use a
`--color-border`/`--color-border-strong` outline with `--color-text` label
text on a transparent background. Minimum touch target 44×44px for primary
interactions. Hover moves to `--color-action-hover` and must be paired with
a non-color cue (secondary buttons pair it with a border-color change).
Label copy should begin with an action verb where practical ("Continue with
Google", not "Google").

**Links.** Inline links use `--color-text` with an underline by default
(underline is the non-color cue), switching to `--color-action` on
hover/focus. A standalone link with no surrounding body copy may use
`--color-action` directly only if it is large enough to satisfy the
non-text contrast floor.

**Code.** Inline and block code use a monospace stack on a
`--color-surface` background with `--radius-sm` corners and a subtle
`--color-border`.

**Cards and panels.** Use a card only when grouping content genuinely aids
comprehension, not decoratively. `--color-surface` for a standard panel,
`--color-surface-emphasis` for one that needs to stand out (e.g., a
security-relevant disclosure).

**Forms.** Inputs share the button's border tokens and radius. Labels sit
above their control, not inline, so they survive translation into longer
strings.

## 8. Icons, project marks, and images

Use exactly one icon family throughout a project — mixing icon sets is a
consistency failure even if each icon is individually fine. Prefer inline
SVG over icon fonts: an icon font is a single point of failure for
rendering and does not respect the "no font synthesis" rule in §4.1.
Decorative icons get `aria-hidden="true"`; icons that convey meaning on
their own need an accessible name. Meaningful interface graphics need at
least 3:1 contrast against their background.

A project's own wordmark or symbol (its "project mark") is **visually
separate from this shared system** — the identity spec supplies the
environment (color, type, spacing, components); the project supplies the
mark. Provide horizontal, compact, monochrome, and transparent-background
variants, and define a clear-space rule based on a stable feature of the
mark. Do not stretch, recolor (outside the monochrome variant), rotate,
outline, or apply effects to a project mark. Verify the mark stays
identifiable at favicon size (16px) and at the size platforms render
repository/profile avatars.

For photography and illustration: high-contrast, uncluttered focal point;
avoid placing important text over visually complex imagery.

## 9. Motion

Motion should clarify, not decorate: a transition should communicate a
state change (hover, focus, expand/collapse), not simply exist. Respect
`prefers-reduced-motion: reduce` — every stylesheet built against this spec
must include a block that removes non-essential transitions and animations
for users who have that preference set.

Server-driven navigation changes (redirects made for a user rather than
requested by them — e.g., redirecting an anonymous browser session to a
login chooser) are a protocol/UX decision outside this spec's scope, but
where a project makes that choice, it should be visually unsurprising: the
destination should look like part of the same flow the user was already in.

## 10. Accessibility

- Use semantic HTML before adding ARIA. A `<button>` is a button before it
  is `role="button"`.
- Every page declares `lang` and a `viewport` meta tag.
- Support browser zoom to at least 200% without loss of content or
  function.
- Pages must remain usable and legible at a 320px viewport width without
  horizontal scrolling.
- Never encode information using only color, position, sound, or motion
  (restated from §3.3 because it is the single most common regression).
- Every interactive element must be reachable by keyboard in a logical
  order and show a visible focus indicator — `:focus-visible`, not
  `:focus`, so mouse users are not shown a ring they didn't ask for.
- Test layouts with longer strings than the design used — a longer
  translated label, a long identifier, a long name — before assuming a
  layout holds.

## 11. Token reference

The canonical token names a stylesheet built against this spec should
define:

```
--color-canvas
--color-surface
--color-surface-emphasis
--color-text
--color-text-muted
--color-text-inverse
--color-border
--color-border-strong
--color-action
--color-action-hover
--color-focus
--font-sans: "Open Sans", -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif
```

The `--font-sans` fallback chain is intentionally long: it is what renders
if the self-hosted font files fail to deploy, so it must not be shortened
to save bytes.

## 12. Assets and packaging

Suggested repository layout:

```text
assets/
  brand/
    mark.svg
    mark-monochrome.svg
    wordmark.svg
    social-card.png
    favicon.svg
docs/
  visual-identity.md
```

- SVG for scalable marks and icons; optimized PNG/WebP/AVIF for raster
  images.
- Fonts and stylesheets are self-hostable by default — a project may choose
  a CDN, but that is a deviation to record (§14), not the baseline.
- Do not commit editable source files (`.ai`, `.sketch`, `.fig`, …) unless
  their license and contribution workflow are documented alongside them.
- Keep identity documentation at a predictable path — `docs/visual-identity.md`
  — so contributors and tooling can find it without a search.

## 13. Adoption checklist

- [ ] Every semantic token in §11 is defined and consumed — no raw palette
      values in component code
- [ ] Status colors defined per project, verified against both `canvas`
      and `surface`, ratios recorded
- [ ] Font files shipped for every declared weight; no synthesized
      bold/italic
- [ ] No more than three font weights in any single view
- [ ] `lang` and viewport present on every page
- [ ] Keyboard navigation and visible focus verified by tabbing, not just
      by inspection
- [ ] 200% zoom and 320px width verified
- [ ] Grayscale render carries no information loss
- [ ] `prefers-reduced-motion: reduce` removes non-essential motion
- [ ] Project-specific marks documented separately from this spec, with
      clear-space and do-not rules
- [ ] Any intentional departure from this spec is recorded in a deviation
      log (§14)

## 14. Extension and deviation policy

This spec is a floor, not a ceiling. A project may add tokens, components,
or rules it needs, subject to:

1. New tokens follow the existing naming pattern (`--color-*`, `--space-*`,
   `--radius-*`, …) rather than inventing a parallel system.
2. A new color token ships with light/dark, hover, focus, and disabled
   variants where the underlying element has those states, and an error
   variant where the element can be invalid.
3. A new component reuses existing tokens before introducing new ones.
4. Extensions are additive — they must not redefine what an existing token
   means.
5. Any intentional departure from this spec — not an extension, but a
   deliberate choice to do something the spec would otherwise forbid or a
   default it does not provide — is recorded in the adopting project's
   identity doc, next to the project's theme, so future contributors
   understand the reason instead of rediscovering it by git-blame.

---

## GrandpaSSOn appendix

### Status color tokens (§3.2)

GrandpaSSOn needs, at minimum, `danger` (login failure, forbidden, invalid
state), `warning` (admin token entry, "secrets shown once"), and `success`
(reader session established). Each is verified against both
`--color-canvas` (`#000000`) and `--color-surface` (`#1A0A3E`):

| Token | Hex | On `canvas` | On `surface` |
|---|---|---|---|
| `--color-danger` | `#FF6B6B` | 7.57:1 | 6.54:1 |
| `--color-warning` | `#F5A623` | 10.36:1 | 8.96:1 |
| `--color-success` | `#3DDC97` | 11.88:1 | 10.27:1 |

All three clear 4.5:1 on both backgrounds, so they are safe for status text
as well as icons/borders — but per §3.3 they still ship paired with a text
label wherever they appear, never as the sole signal.

### Verified contrast table (GrandpaSSOn-specific pairs)

Extends §3.4 with the pairs this project actually renders — `surface-emphasis`
and purple-on-surface, which the shared spec's §3.4 baseline omits:

| Foreground | Background | Hex / Hex | Ratio | Passes |
|---|---|---|---|---|
| `--color-text` | `--color-surface-emphasis` | `#EBEBEB` / `#1B0F46` | 14.64:1 | AAA |
| `--color-text-muted` | `--color-surface-emphasis` | `#B0B0B0` / `#1B0F46` | 8.05:1 | AAA |
| `--color-focus` | `--color-canvas` | `#814DDE` / `#000000` | 4.05:1 | Non-text (3:1 floor) — this is the focus-ring pair, not a text pair |

The `action`/`focus` pair (`#814DDE`) intentionally sits at 4.05:1 on canvas
— below the 4.5:1 normal-text threshold. This is not a bug: it encodes
§3.3's rule that this purple must not be used for small body text or
standalone links on canvas. If a future palette change pushes this above
4.5:1, update it deliberately (and note why in this table), not by
accident.

### Deviation log (§14)

| Deviation | Reason |
|---|---|
| Fonts are self-hosted rather than CDN-loaded | Deploy target is shared/cPanel hosting with no assumed outbound CDN access, and the login/admin surfaces should not add a third-party request on credential-handling pages (VI4, #91). |
| Asset URLs are base-path-prefixed rather than root-absolute | `public_html/index.php` strips a configurable `BROKER_BASE_URL` path prefix on the way in (subpath installs, e.g. `/sso`); asset and link URLs must add the same prefix on the way out or they 404 under a subpath deploy (VI3, #90). |
| Terminal/console output in the admin UI keeps a monospace stack per §7 "Code" but uses `--color-surface` rather than the near-black it uses today | Keeps the console readout inside the same token system as the rest of the page instead of a one-off dark panel (VI8, #95). |

### Extension policy application (§14 items 1–5)

- GrandpaSSOn's only token additions beyond §11 are the three status colors
  above (item 1: `--color-*` naming preserved).
- Status tokens do not currently need hover/disabled variants — they are
  applied to static text/panels, not interactive controls (item 2: N/A,
  revisit if a status token is ever applied to a button or link).
- No new components were introduced outside §7's button/link/code/card set
  (item 3).
- Nothing in this appendix redefines an existing token's meaning (item 4).
- The three deviations above are recorded per item 5.

### Related docs

- `assets/brand/` clear-space and do-not rules — see VI9 (#96) once brand
  marks land; this appendix will link the specific file rather than
  duplicate it.
