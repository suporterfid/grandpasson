# GrandpaSSOn locale foundation (i18n, phase 1)

## Context

Goal: add PT-BR/EN i18n across jotter, taskconnect, tallymark, and statusconnect (grandpasson's four relying parties; sendmark excluded — no app code yet). Each RP already authenticates through GrandpaSSOn's session-exchange flow, so language preference is a **single value owned by GrandpaSSOn** and synced to every RP, rather than a per-app setting.

This spec covers only the GrandpaSSOn side: the schema, the claim, and the read/write endpoint. Per-app consumption (Laravel backend translations, Vue frontend i18n, email templates, Blade views) is out of scope — each RP gets its own follow-up spec.

## Decisions locked in during brainstorming

- Languages: PT-BR (default) and EN only.
- Locale is a single value per user, stored centrally in GrandpaSSOn, not per-app.
- Any RP can change the user's locale (not just a GrandpaSSOn account page); the change is written back to GrandpaSSOn immediately.
- Propagation to *other* already-open RPs happens on their next session/token refresh — no real-time push, no new infra. This matches existing session/token TTLs (GrandpaSSOn access tokens: 900–3600s; RP sessions are DB-backed with their own short TTLs).

## 1. Schema

New migration `app/Infrastructure/Db/Migrations/024_alter_users_add_locale.sql`:

```sql
ALTER TABLE users ADD COLUMN locale VARCHAR(10) NOT NULL DEFAULT 'pt-BR' AFTER status;
```

`VARCHAR(10)`, not a MySQL `ENUM`, so adding a third language later needs no schema migration. The allowed-value check lives in the application layer:

```php
namespace GrandpaSSOn\Domain;

final class Locale
{
    public const DEFAULT = 'pt-BR';
    public const SUPPORTED = ['pt-BR', 'en'];

    public static function isSupported(string $value): bool
    {
        return in_array($value, self::SUPPORTED, true);
    }
}
```

New user signups (`SignupService`) insert `locale = Locale::DEFAULT` explicitly — no `Accept-Language` sniffing (YAGNI; can be added later without breaking anything).

## 2. Claim propagation via session/exchange

`SessionClaimsResolver::resolve()` gains a top-level `locale` field, sibling to `subject`/`tenant`/`groups`/`scopes`, read straight from the `$user` array the caller passes in:

```php
return [
    'subject' => [...],
    'tenant' => ...,
    'tenants' => ...,
    'groups' => ...,
    'scopes' => self::DEFAULT_SCOPES,
    'locale' => $user['locale'],
];
```

Every caller that builds the `$user` array for `resolve()` must add `locale` to its `SELECT` and to the array literal:

- `SessionExchangeController` (the primary path — this is what RPs call on login)
- `ActiveTenantController::show()` / `::set()` (already re-resolves claims after tenant changes)
- `LoginController` / `CallbackController` if they independently query user rows for claims

`docs/client-integration.md`: add `"locale": "pt-BR"` to the example `/session/exchange` JSON response, and a short paragraph documenting `/me/locale`, following the existing "Active tenant (R2)" paragraph's style.

No behavior change for RPs that ignore the new field — same "treat unknown keys as ignorable" contract already documented.

## 3. `/me/locale` endpoint

New `LocaleController`, structurally a mirror of `ActiveTenantController`:

```php
final class LocaleController
{
    public function show(array $config, array $params = []): void
    {
        // requireSubject() -> 401 if no $_SESSION['user_id']
        // rate limit: RateLimitGate::allowOauth($pdo, 'me_locale', $config)
        // SELECT locale FROM users WHERE id = :id
        // Http::json(200, ['locale' => $locale, 'csrf' => Csrf::token()]);
    }

    public function set(array $config, array $params = []): void
    {
        // requireSubject() -> 401
        // rate limit: bucket 'me_locale_write'
        // CSRF: body.csrf or X-CSRF-Token header, via Csrf::validate()
        // validate body.locale against Locale::isSupported() -> 400 invalid_request
        // UserLocaleRepository::set($userId, $locale)
        // AuditLogger::record('locale.set', RESULT_SUCCESS, ACTOR_SUBJECT, $userId, null, null, Http::clientIp())
        // Http::json(200, ['ok' => true, 'locale' => $locale, 'csrf' => Csrf::token()]);
    }
}
```

`UserLocaleRepository` (mirrors `UserActiveTenantRepository`): a single `set(string $userId, string $locale): void` doing a straight `UPDATE users SET locale = :locale WHERE id = :id` — no separate table needed since this is a direct column on `users`, unlike active-tenant which references a foreign `tenants` row.

New routes in `AppRoutes.php`:

```php
['GET', '/me/locale', LocaleController::class, 'show'],
['POST', '/me/locale', LocaleController::class, 'set'],
```

This is the endpoint any RP calls when the user changes language inside that RP's own UI — GrandpaSSOn is updated immediately, and every other RP picks up the new value the next time it hits `/session/exchange` or refreshes its token.

## 4. Admin CLI (optional, cheap — cut if unwanted)

`cron/admin.php user:set-locale <email> <locale>` for support to fix a stuck account without raw SQL, following the existing verb pattern (`tenant:create`, `client:create-service`, `pat:revoke`).

## 5. Tests

- Unit: `SessionClaimsResolver` test asserting `locale` passes through from the `$user` array into the resolved claims.
- Integration: `LocaleControllerTest` — 401 without session, `GET` returns current locale, `POST` with unsupported locale returns `400`, `POST` without valid CSRF returns `403`, `POST` with a supported locale persists and returns it, and rate-limit trips return `429` (same shape as `ActiveTenantController`'s existing test coverage, if any).

## Explicitly out of scope

- Laravel backend translations (`lang/` files), Vue frontend i18n (`vue-i18n` messages), email templates, and Blade views for jotter, taskconnect, tallymark, statusconnect. Each gets its own spec.
- Real-time cross-tab locale sync (push/websocket) — deferred, not needed per the timing decision above.
- `Accept-Language`-based auto-detection at signup.
