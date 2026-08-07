# Final fix report -- Notion-inspired identity

**Status: DONE.** The four Important final-review findings were fixed together
without optional scope. The focused regressions, real-browser checks, and exact
Docker release gate pass.

## 1. Invalid control boundary

### RED

The new contract test required the actual `aria-invalid="true"` rule to resolve
to the canonical strong boundary and computed contrast against the form
control's actual elevated background in both themes. Before the fix:

- the rule resolved to `--color-danger-border`;
- light contrast was `2.1387:1`, below the `3:1` non-text floor;
- the focused RED run failed both the semantic mapping and measured contrast.

### GREEN

Invalid input/select/textarea outlines now use a 2px
`--color-border-strong` boundary. Programmatic `aria-invalid` state and existing
text error messages remain unchanged, so the error is still exposed without
depending on boundary colour. The actual boundary/background ratios are:

- light: `#8A8882` on `#FFFFFF` = `3.5445:1`;
- dark: `#6E6E6E` on `#252525` = `3.0062:1`.

## 2. Standalone action links

### RED

The CSS test failed because `.action-link` did not exist. The controller
regression reported all six plain standalone anchors lacking an explicit target
class:

- `LoginController`: email and signup;
- `EmailOtpLoginController`: signup and request-new-code;
- `SignupController`: start-over;
- `SiteReaderController`: check-session.

The rendered `LoginController` and `EmailOtpLoginController` assertions also
failed before implementation. Button links were excluded, and inline prose
links remain governed only by the normal link rule.

### GREEN

`.action-link` is an underlined `inline-flex` target with `44px` minimum inline
and block sizes. All six standalone actions opt into the class. The CSS,
controller-wide structural regression, and real rendered-controller assertions
pass.

## 3. Narrow theme-switcher flow

### RED

The new CSS contract expected normal-flow positioning below 480px and preserved
fixed positioning from 480px. It failed because `.theme-switcher` was fixed at
every width.

### GREEN

Below 480px the switcher is static, fit-content, and follows page content with
logical-end and bottom safe-area margins. At 480px and wider it retains the
existing fixed logical corner and z-index. The adoption document now records
the breakpoint-specific behavior.

Real Chromium verification used the long `/signup` form at `320x812`, set RTL,
expanded all three labels, and focused the final submit button. Results:

- direction `rtl`; switcher `position: static`;
- switcher height `166px`;
- document widths `320/320` (no horizontal overflow);
- focused final button y=`552..596`, visible in the viewport;
- switcher y=`676..842`, after the button;
- overlap `false`.

Artifact: `output/playwright/signup-320-rtl-long-final-focus.png`.

## 4. Correct 200% text-resize evidence

The old root-only `html { font-size: 200% }` claim was invalid because the
active type scale and line heights are pixel-tokenized. Chromium's CLI does not
offer a genuine text-only zoom control, so the replacement test-only browser
override first captured computed typography for every visible element, then
assigned exactly 2x each captured numeric `font-size` and `line-height`.
Ratios were checked after the override rather than inferred from root style.

At `/login` (`320x812`):

| Sample | Before font/line | After font/line |
|---|---:|---:|
| Body | `16/24` | `32/48` |
| Heading | `44/52` | `88/104` |
| Lead | `24/32` | `48/64` |
| Provider action | `14/20` | `28/40` |
| Standalone action | `12/16` | `24/32` |
| Switcher option | `14/20` | `28/40` |

At `/signup` (`320x812`):

| Sample | Before font/line | After font/line |
|---|---:|---:|
| Body | `16/24` | `32/48` |
| Heading | `44/52` | `88/104` |
| Lead | `24/32` | `48/64` |
| Label | `14/20` | `28/40` |
| Input / textarea | `16/20` | `32/40` |
| Primary action | `14/20` | `28/40` |
| Switcher option | `14/20` | `28/40` |

Both pages reported document widths `320/320`, horizontal overflow `false`,
and no visible element clipped by hidden/clip overflow. On `/login`, the final
standalone action was focused and visible at y=`550..614`, before the switcher
at y=`694..796`; overlap was false. On `/signup`, the final submit button was
focused and visible at y=`556..614`, before the same switcher range; overlap was
false.

Artifacts:

- `output/playwright/login-320-200-text.png` (replaces the old root-only image);
- `output/playwright/signup-320-200-text.png`.

Visual inspection of all three replacement screenshots found wrapped but
readable text, visible focus, complete controls, and no clipping. The temporary
browser-only OAuth client used to reach the long signup form was removed after
the checks.

## RED/GREEN command evidence

The first focused run, before production edits:

```powershell
php vendor\bin\phpunit --do-not-cache-result tests\Unit\ThemeCssTest.php tests\Unit\VisualIdentityTest.php tests\Unit\Http\Controllers\LoginControllerChooserTest.php tests\Integration\EmailOtpLoginControllerTest.php
```

Result: `38` tests, `180` assertions, `7` expected failures. They identified
the missing action-link CSS, wrong invalid-boundary token and `2.1387:1` light
ratio, fixed narrow switcher, all six unclassified standalone anchors, and the
two rendered controller failures.

The identical command after the minimal implementation returned `OK`: `38`
tests, `194` assertions.

## Final verification

```powershell
node --test tests\Js\theme-runtime.test.js
php vendor\bin\phpunit --do-not-cache-result tests\Unit\ThemeCssTest.php tests\Unit\VisualIdentityTest.php tests\Unit\Support\HtmlTest.php tests\Unit\Http\Controllers\LoginControllerChooserTest.php tests\Integration\EmailOtpLoginControllerTest.php tests\Integration\SignupControllerTest.php tests\Integration\ReaderSessionTest.php
docker compose -f docker-compose.build.yml run --rm --build build
git diff --check
```

Results:

- Node: `1/1` passed.
- Focused identity/controller PHPUnit: `60` tests, `262` assertions, pass.
- Docker release gate: pass; production dependency prune, ZIP verification,
  and S1 scan completed; `458` entries.
- `git diff --check`: pass.

The host PHP process still emits its pre-existing startup warning that local
policy blocks `pdo_sqlite` and `sqlite3`; the requested MySQL-backed controller
tests ran and passed. Docker also printed its existing orphan-container notice
and an allowed offline Packagist-filter warning during production pruning;
neither affected the successful release gate.

## Files changed

- `public_html/assets/theme.css`
- `app/Http/Controllers/LoginController.php`
- `app/Http/Controllers/EmailOtpLoginController.php`
- `app/Http/Controllers/SignupController.php`
- `app/Http/Controllers/SiteReaderController.php`
- `tests/Unit/ThemeCssTest.php`
- `tests/Unit/VisualIdentityTest.php`
- `tests/Unit/Http/Controllers/LoginControllerChooserTest.php`
- `tests/Integration/EmailOtpLoginControllerTest.php`
- `docs/visual-identity.md`
- `.superpowers/sdd/2026-08-07-notion-identity/task-4-report.md`
- `.superpowers/sdd/2026-08-07-notion-identity/final-fix-report.md`

## Concerns

No open functional concern. Only the pre-existing local extension warnings and
non-failing Docker informational warnings noted above remain.
