# Task 2 report: no-flash theme runtime and shared shell

## Files changed

- `app/Support/Html.php`
  - Keeps the existing `pageStart(array $config, string $title, string $bodyClass = '')` and `pageEnd()` controller interfaces.
  - Adds a base-path-aware, blocking, same-origin `theme.js` before `theme.css` and an initial light `theme-color` meta element.
  - Adds the stable `data-theme-switcher` mount point before `</body>`.
- `public_html/assets/theme.js`
  - Resolves `grandpasson.theme` values (`light`, `dark`, or `system`) before stylesheet parsing.
  - Treats missing, malformed, and unavailable storage as `system`; writes storage only after an explicit radio choice.
  - Updates the root `data-theme`, allowing the external stylesheet's `[data-theme]` rules to update `color-scheme`, and synchronizes the `theme-color` meta element.
  - Listens to `prefers-color-scheme` only while the active preference is `system`.
  - Enhances the mount point into native radios in an accessible `radiogroup`; text uses the document language for `en` and `pt-BR`, with English fallback. Event listeners are attached from the external script only.
- `public_html/assets/theme.css`
  - Adds token-only, logical-property styling for the switcher, including a visible selected state and 44px option targets.
- `tests/Unit/Support/HtmlTest.php`
  - Covers base-path-aware runtime URL, runtime-before-stylesheet ordering, initial `theme-color`, and the switcher mount point.

## TDD evidence

### RED

Command:

```powershell
php vendor\phpunit\phpunit\phpunit tests\Unit\Support\HtmlTest.php
```

Result: 2 expected failures (13 tests total). The shell lacked the required `theme-color` meta element and `pageEnd()` lacked the switcher mount point.

### GREEN

Command:

```powershell
php vendor\bin\phpunit tests\Unit\Support\HtmlTest.php
```

Result: `OK (13 tests, 33 assertions)`.

Additional runtime verification used a Node VM with a minimal DOM/media-query/storage fixture. It first failed when an explicit Light choice was later overridden by an OS change, then passed after removing a shadowed preference variable. `node --check public_html\assets\theme.js` also passed.

## Behavior decisions

- The external script is deliberately blocking and placed before the stylesheet; it assigns `data-theme` before CSS parsing so the stylesheet's existing `[data-theme]` selectors set the matching `color-scheme` without inline styles or a CSP exception.
- The initial meta color is light (`#FFFFFF`) and changes to `#191919` for the resolved dark theme.
- The shell still defaults to `lang="en"`; a future localized shell automatically receives the `pt-BR` labels when it sets that document language. Any unrecognized language receives English.
- The switcher is appended by `pageEnd()` so existing controllers remain unchanged.

## Self-review

- Confirmed `Html::CSP` remains unchanged and contains neither `unsafe-inline` nor `unsafe-eval`.
- Confirmed no inline handlers or inline script/style tags were introduced.
- Confirmed base-path asset generation is used for both CSS and JS.
- Confirmed explicit light/dark choices ignore media changes and `system`/missing preferences follow them.
- `git diff --check` passed.

## Verification concern

The full `php vendor\bin\phpunit` command did not finish within 120 seconds in this environment. It emitted the existing startup warnings for blocked `pdo_sqlite` and `sqlite3` extensions, then timed out without reporting test completion. The focused task test and JavaScript runtime/syntax checks passed. The generated `.phpunit.cache/test-results` change remains unstaged and is not part of the task commit.

## Commit

`c8e4670 Add no-flash theme runtime`

## Round 1: lowercase BCP 47 Portuguese regression

### Files

- Added `tests/Js/theme-runtime.test.js`, a dependency-free Node `node:test` + VM test that loads the shipped `public_html/assets/theme.js` and exercises the actual DOMContentLoaded enhancement path with `documentElement.lang = 'pt-br'`.
- Updated `public_html/assets/theme.js` to normalize the document language with `toLowerCase()` and store the Portuguese message map under `pt-br`; all other languages continue to use English.

### RED

```powershell
node --test tests\Js\theme-runtime.test.js
```

Result: failed as expected. The enhanced group label was `Theme` rather than `Tema` for lowercase `pt-br`.

### GREEN

```powershell
node --test tests\Js\theme-runtime.test.js
node --check public_html\assets\theme.js
php vendor\bin\phpunit tests\Unit\Support\HtmlTest.php
```

Results: Node test passed (1/1); JavaScript syntax check passed; focused PHPUnit test passed with `OK (13 tests, 33 assertions)`. PHPUnit continues to emit the pre-existing blocked `pdo_sqlite` and `sqlite3` extension warnings.
