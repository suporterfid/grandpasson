# Task 4 report -- browser and release verification

**Status: DONE.** The real-browser theme/UI checks, focused identity tests,
and exact Docker release gate pass. The initial CRLF release-gate concern was
resolved in follow-up round 1. Final review also replaced the original invalid
200% text-resize claim with computed-style evidence that genuinely doubles the
active typography on both `/login` and `/signup`.

## Environment and app startup

Date: 2026-08-07. Browser: Playwright CLI Chromium, named session
`grandpasson-task4`. `npx` was present at `/c/nvm4w/nodejs/npx`.

The Windows host does not have `make`, so both documented Makefile entry points
were executed with their exact underlying Docker recipes. For application
startup:

```sh
# attempted documented entry point (not available on this host)
make up

# documented-equivalent Makefile recipe, through Git Bash
test -f .env || cp .env.example .env; docker compose up -d --build
```

Result: the `mysql` service reached its Compose healthcheck and the rebuilt
`web` service started at `http://localhost:8080`.

## Browser verification

All browser interactions were preceded by a fresh `snapshot` whenever element
references were used. The initial `/login` snapshot exposed an accessible
`radiogroup "Theme"` with native Light, Dark, and System radios. It also
exposed the Google, Microsoft, GitHub, and email sign-in links. `/login` ended
with zero console errors/warnings.

| Check | Command/result |
| --- | --- |
| Light and dark | Selected each radio through the snapshot ref. The root computed tuple for dark was `data-theme=dark`, `color-scheme=dark`, and `meta[name=theme-color]=#191919`; light correspondingly resolved to light / `#FFFFFF`. |
| Persistence | Selected Dark; `localstorage-get grandpasson.theme` returned `dark`. After `reload`, the snapshot still showed Dark checked. |
| Explicit media behavior | With explicit Dark stored, `page.emulateMedia({ colorScheme: 'light' })` (via CLI `run-code`) still returned root `data-theme` = `dark`. |
| System behavior | Selected System (`grandpasson.theme=system`). `page.emulateMedia` followed by a 250ms event-settle delay returned `dark` for dark OS media and `light` for light OS media. |
| Keyboard and radio accessibility | Starting from a reload, Tab reached the checked System radio (the native radio-group tab stop). ArrowUp selected/focused Dark and persisted `dark`; ArrowDown selected/focused System and persisted `system`. Snapshots showed the active/checked radio after each action. |
| Reflow | At `320x812`, `[documentElement.scrollWidth, innerWidth, body.scrollWidth]` was `[320,320,320]`. |
| 200% text resize | Chromium exposes no genuine text-only zoom control through this CLI, so a test-only DOM override first captured every visible element's computed `font-size` and numeric `line-height`, then assigned exactly twice each captured value. On `/login`, representative pairs were body `16/24 -> 32/48`, heading `44/52 -> 88/104`, provider action `14/20 -> 28/40`, and standalone action `12/16 -> 24/32`. On `/signup`, body `16/24 -> 32/48`, heading `44/52 -> 88/104`, label `14/20 -> 28/40`, input/textarea `16/20 -> 32/40`, and primary action `14/20 -> 28/40`. Both pages retained widths `[320,320]`, reported no clipped text, kept the focused final control visible, and had no switcher overlap. |
| RTL and long labels | At `320x812`, temporarily set `document.documentElement.dir='rtl'` on the scrolling `/signup` form and expanded all three switcher labels. The narrow switcher computed to `position: static`, grew to `166px` high, and retained document widths `[320,320]`. The focused final button occupied y=`552..596`; the switcher followed at y=`676..842`; overlap was `false` and the final control remained visible. |
| Reduced motion | With `page.emulateMedia({ reducedMotion: 'reduce' })`, representative switcher-label computed transition and animation durations were `0.001s`, satisfying the stylesheet's near-zero reduced-motion override. |
| Forced colors | Chromium accepted `page.emulateMedia({ forcedColors: 'active' })`; `matchMedia('(forced-colors: active)').matches` was `true` and the forced-colors visual was captured. |
| Provider-facing shared shell | The intentionally incomplete `/login/email` URL returned the expected `400 Sign-in failed` page (`client_id, redirect_uri, and state are required`) and retained the same theme radiogroup. Its console contains the expected 400 failed-resource entry; returning to `/login` left console errors/warnings at zero. |

Visual inspection confirms the desktop and 375px light/dark views render with
the wider switcher fixed in the logical corner and no horizontal scroll. At
320px the switcher follows content in flow. With computed typography doubled,
large headings wrap aggressively as expected, while content remains unclipped,
the final control remains reachable, and switcher labels remain operable.

Artifacts:

- `output/playwright/login-desktop-light.png`
- `output/playwright/login-desktop-dark.png`
- `output/playwright/login-mobile-375-light.png`
- `output/playwright/login-mobile-375-dark.png`
- `output/playwright/login-320-200-text.png`
- `output/playwright/signup-320-200-text.png`
- `output/playwright/signup-320-rtl-long-final-focus.png`
- `output/playwright/login-forced-colors.png`

The prior `html { font-size: 200% }` result was withdrawn: active typography
uses absolute pixel tokens, so changing only the root size did not establish a
200% text-resize condition. The replacement screenshots and numeric results
above are the canonical evidence.

## Automated checks

```powershell
node --test tests\Js\theme-runtime.test.js
php vendor\bin\phpunit --do-not-cache-result tests\Unit\ThemeCssTest.php tests\Unit\VisualIdentityTest.php tests\Unit\Support\HtmlTest.php
```

Results:

- Node runtime test: `1/1` passed.
- Focused identity PHPUnit suite: `OK (31 tests, 147 assertions)`.
- The PHP executable emitted the pre-existing, environment-policy warnings that
  `pdo_sqlite` and `sqlite3` are blocked; these did not affect the passing
  focused suite.

## Release/build gate and artifact audit

Documented Makefile recipe executed directly (because `make` is unavailable):

```sh
docker compose -f docker-compose.build.yml run --rm --build build
```

Result: **failed (exit 1)**. Docker reported:

```text
/usr/local/bin/docker-php-entrypoint: 9: exec: /workspace/docker/build/build.sh: not found
```

Container diagnosis showed the bind-mounted script exists and is executable,
but its initial bytes are `23 21 2f 62 69 6e 2f 73 68 0d 0a`; invoking it with
`/bin/sh` yields `set: Illegal option -\r`. This is the CRLF release-gate
defect and was not patched by Task 4.

For content validation only, this container-only fallback normalized the script
to `/tmp/build.sh` and replaced its calculated root with `/workspace`; it does
not modify the checked-out script or tracked configuration:

```sh
docker compose -f docker-compose.build.yml run --rm --entrypoint /bin/sh build -lc \
  'sed -e '"'"'s/\r$//'"'"' -e '"'"'s|^ROOT=.*|ROOT="/workspace"|'"'"' \
  /workspace/docker/build/build.sh > /tmp/build.sh; /bin/sh /tmp/build.sh'
```

Fallback result: passed Composer production pruning, zip verification, and S1
secret scan; it created `grandpasson-release.zip` with 458 entries.

`tar -tf grandpasson-release.zip` confirmed:

- `public_html/assets/theme.css`
- `public_html/assets/theme.js`
- `public_html/assets/fonts/inter-4.1-variable.woff2`
- `public_html/assets/fonts/INTER-LICENSE.txt` (and `LICENSE.txt`)

The top-level `docs/` directory is deliberately excluded by the existing build
script's forbidden-path check, so `docs/visual-identity.md` is not a deployable
release artifact. That matches this repository's existing packaging policy;
vendor documentation is unrelated dependency content.

## Concern requiring follow-up

Normalize `docker/build/build.sh` to LF so the documented release command can
execute directly in its Linux Docker container. Until then, a standard
`make build` / Compose release-gate run fails even though the normalized
fallback proves the resulting package content is valid.

## Follow-up round 1/5 -- LF portability fix

**Status: resolved.** The documented release command now succeeds without any
fallback.

### RED

Added `ReleaseArtifactGateTest::testDockerBuildScriptHasLfPortableShebang()`
to read the actual `docker/build/build.sh` bytes. It rejects any carriage
return and requires the literal LF shebang prefix `#!/bin/sh\n`.

```powershell
php vendor\bin\phpunit --do-not-cache-result tests\Unit\ReleaseArtifactGateTest.php
```

Against the pre-fix checkout: failed as expected (4 tests, 14 assertions, one
failure) because the script contained `\r`, starting with
`#!/bin/sh\r\n`.

### GREEN

Added `.gitattributes` with `*.sh text eol=lf` and normalized the checked-out
`docker/build/build.sh` to LF. The file's first bytes are now
`23 21 2f 62 69 6e 2f 73 68 0a`, and it contains no carriage return. The script
already stores LF in Git's normalized index, so the committed durable change is
the new checkout-enforcement rule rather than a semantic/script-content diff.

The focused gate passed:

```text
OK (4 tests, 15 assertions)
```

The exact documented Docker command then passed without a fallback:

```sh
docker compose -f docker-compose.build.yml run --rm --build build
```

It completed the production Composer prune, release zip verification, and S1
secret scan, producing a 458-entry `grandpasson-release.zip`.

Re-audited ZIP entries confirmed `theme.css`, `theme.js`,
`inter-4.1-variable.woff2`, `INTER-LICENSE.txt`, and `LICENSE.txt`; the
top-level `docs/` tree remains intentionally excluded by the existing package
gate.

Committed focused fix: `e99eeb8 fix(build): enforce LF shell script portability`.
