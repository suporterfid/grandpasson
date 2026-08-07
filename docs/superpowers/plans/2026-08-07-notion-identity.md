# Notion-Inspired Identity Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace GrandpaSSOn's dark-only purple theme with the approved warm-neutral Notion-inspired identity, including light/dark/system theming, RTL-safe layout, and WCAG 2.2 AA verification.

**Architecture:** `public_html/assets/theme.css` owns semantic presentation tokens and responsive components. A small CSP-compatible `public_html/assets/theme.js` resolves and persists `light | dark | system` before first paint, then enhances the shared HTML shell with a localized theme control. `GrandpaSSOn\Support\Html` remains the single browser-shell boundary.

**Tech Stack:** PHP 8.1, PHPUnit 10, plain CSS custom properties, framework-free JavaScript, Docker/nginx browser smoke testing.

## Global Constraints

- Preserve shared-hosting deployment, strict same-origin CSP, and base-path-aware asset URLs.
- Components consume semantic tokens; no controller may contain raw color values.
- Light foundations are `#FFFFFF`, `#F7F6F3`, `#252525`, and `#1A6DC1`; dark foundations are `#191919`, `#252525`, `#F1F1EF`, and `#529CCA`.
- Enabled normal text meets 4.5:1; focus and essential control boundaries meet 3:1.
- Theme preference is exactly `light | dark | system`; explicit preference wins and `system` follows runtime OS changes.
- Layout uses logical properties, supports RTL, 320 CSS px reflow, 200% text resize, and 44x44 CSS px pointer targets.
- New user-visible copy is localized for English and Brazilian Portuguese; existing authentication and authorization behavior remains unchanged.

---

### Task 1: Semantic theme contract

**Files:**
- Modify: `tests/Unit/ThemeCssTest.php`
- Modify: `tests/Unit/VisualIdentityTest.php`
- Modify: `public_html/assets/theme.css`

**Interfaces:**
- Consumes: the canonical token names in `C:\workspace-offline\iroh\notion-inspired-visual-identity-spec.md`.
- Produces: light and dark definitions for all 30 `--color-*` semantic tokens plus stable component aliases.

- [ ] Add PHPUnit cases that parse light and dark token blocks independently, require all 30 tokens in both modes, and verify the documented text/action/status/focus/control-boundary contrast pairs.
- [ ] Run `vendor\bin\phpunit tests\Unit\ThemeCssTest.php tests\Unit\VisualIdentityTest.php`; expect failures because the dark-only stylesheet lacks the canonical token blocks.
- [ ] Replace the palette, typography, spacing, shape, elevation, link, form, button, card, motion, forced-color, and responsive rules with the approved semantic contract while retaining legacy aliases only when they resolve to canonical tokens.
- [ ] Re-run the targeted PHPUnit tests; expect all cases to pass.

### Task 2: No-flash theme runtime and shared shell

**Files:**
- Modify: `tests/Unit/Support/HtmlTest.php`
- Create: `public_html/assets/theme.js`
- Modify: `app/Support/Html.php`
- Modify: `public_html/assets/theme.css`

**Interfaces:**
- Consumes: `Html::asset()` and the strict `Html::CSP` policy.
- Produces: blocking same-origin `theme.js`, root `data-theme`, storage key `grandpasson.theme`, and a `data-theme-switcher` radiogroup enhanced from localized message keys.

- [ ] Add shell tests requiring the theme script before the stylesheet, a theme-color meta element, and the switcher mount point while preserving base-path URLs and CSP.
- [ ] Run `vendor\bin\phpunit tests\Unit\Support\HtmlTest.php`; expect failures for the missing runtime and switcher markup.
- [ ] Implement the external runtime with exact `light | dark | system` persistence, `prefers-color-scheme` change handling, accessible English/Portuguese labels, and root/meta synchronization; update `Html` and switcher styling.
- [ ] Re-run the shell tests; expect all cases to pass.

### Task 3: Responsive and bidirectional UI hardening

**Files:**
- Modify: `tests/Unit/ThemeCssTest.php`
- Modify: `public_html/assets/theme.css`
- Modify: `docs/visual-identity.md`

**Interfaces:**
- Consumes: existing `.prose`, `.card`, `.btn`, `.action-list`, form, admin, and table markup.
- Produces: logical-spacing behavior, direction-aware safe areas, 720px reading measure, 1200px data measure, 320px/400% reflow, and a project adoption record for the canonical spec.

- [ ] Add CSS contract tests for logical properties, RTL direction selectors, 44x44 targets, forced colors, and reduced motion; run them and confirm they fail against the pre-migration rules.
- [ ] Implement the minimal layout rules needed by the existing browser surfaces and update the identity document to replace the obsolete dark-purple/Open Sans contract with the approved specification and GrandpaSSOn-specific adoption notes.
- [ ] Re-run the targeted unit tests and the full `vendor\bin\phpunit` suite.

### Task 4: Browser and release verification

**Files:**
- Verify: `public_html/assets/theme.css`
- Verify: `public_html/assets/theme.js`
- Verify: browser-facing controllers through their shared shell

**Interfaces:**
- Consumes: the built Docker application and release artifact.
- Produces: visual evidence for light, dark, system, mobile, RTL, focus, and reduced-motion behavior.

- [ ] Run the app through the repository's Docker workflow and capture login/admin-facing screenshots at desktop and 375px in both themes.
- [ ] Verify keyboard focus, theme persistence, system-theme response, 320 CSS px reflow, RTL mirroring, long-label wrapping, and no console errors.
- [ ] Run the release/build gate and confirm `theme.css`, `theme.js`, fonts, and identity documentation are included.

