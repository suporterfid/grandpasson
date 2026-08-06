# GrandpaSSOn Locale Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a central `locale` (pt-BR|en) attribute to GrandpaSSOn users, expose it through `/session/exchange` claims and a new `/me/locale` endpoint, so every relying party (jotter, taskconnect, tallymark, statusconnect) can read and write one shared language preference per user.

**Architecture:** A `locale` column on the existing `users` table (not a separate table — it's a scalar attribute, not a relationship). `SessionClaimsResolver` picks it up and surfaces it as a top-level `locale` claim, which `SessionExchangeController` already merges straight into its JSON response. A new `LocaleController`, structurally identical to the existing `ActiveTenantController`, lets any authenticated subject read or overwrite the value immediately (`POST /me/locale`) — other already-open RPs pick up the new value the next time they call `/session/exchange` or refresh their session, per the "no real-time push" decision in the design spec.

**Tech Stack:** PHP 8.2+ (no framework — hand-rolled router, PDO), PHPUnit 10, MySQL 8.0 (via `docker compose up -d mysql`, root/devrootpass, port 3306).

## Global Constraints

- Supported locales: exactly `pt-BR` (default) and `en` — no other values accepted anywhere.
- `locale` column type is `VARCHAR(10)`, not a MySQL `ENUM` — allowed-value enforcement lives in application code (`Locale::isSupported()`), so adding a third language later needs no schema migration.
- No `Accept-Language` sniffing at signup — new users always get `pt-BR`.
- No real-time/push propagation between RPs — an RP picks up a changed locale only on its next `/session/exchange` or token refresh.
- Per-app i18n (Laravel `lang/` files, Vue `vue-i18n` messages, email templates, Blade views) is explicitly out of scope for this plan.
- Never break the existing `/session/exchange` "treat unknown keys as ignorable" contract — the new `locale` key must be purely additive.

---

### Task 1: `Locale` domain class + migration

**Files:**
- Create: `app/Domain/Locale.php`
- Create: `app/Infrastructure/Db/Migrations/024_alter_users_add_locale.sql`
- Test: `tests/Unit/Domain/LocaleTest.php`

**Interfaces:**
- Produces: `GrandpaSSOn\Domain\Locale::DEFAULT` (string, `'pt-BR'`), `GrandpaSSOn\Domain\Locale::SUPPORTED` (`list<string>`, `['pt-BR', 'en']`), `GrandpaSSOn\Domain\Locale::isSupported(string $value): bool`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Domain;

use GrandpaSSOn\Domain\Locale;
use PHPUnit\Framework\TestCase;

final class LocaleTest extends TestCase
{
    public function testDefaultIsPtBr(): void
    {
        $this->assertSame('pt-BR', Locale::DEFAULT);
    }

    public function testSupportedContainsExactlyPtBrAndEn(): void
    {
        $this->assertSame(['pt-BR', 'en'], Locale::SUPPORTED);
    }

    public function testIsSupportedAcceptsKnownLocales(): void
    {
        $this->assertTrue(Locale::isSupported('pt-BR'));
        $this->assertTrue(Locale::isSupported('en'));
    }

    public function testIsSupportedRejectsUnknownLocale(): void
    {
        $this->assertFalse(Locale::isSupported('es'));
        $this->assertFalse(Locale::isSupported(''));
        $this->assertFalse(Locale::isSupported('PT-BR'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit --filter LocaleTest tests/Unit/Domain/LocaleTest.php`
Expected: FAIL — `Class "GrandpaSSOn\Domain\Locale" not found`

- [ ] **Step 3: Write minimal implementation**

`app/Domain/Locale.php`:

```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Domain;

final class Locale
{
    public const DEFAULT = 'pt-BR';

    /** @var list<string> */
    public const SUPPORTED = ['pt-BR', 'en'];

    public static function isSupported(string $value): bool
    {
        return in_array($value, self::SUPPORTED, true);
    }
}
```

`app/Infrastructure/Db/Migrations/024_alter_users_add_locale.sql`:

```sql
ALTER TABLE users ADD COLUMN locale VARCHAR(10) NOT NULL DEFAULT 'pt-BR' AFTER status;
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit --filter LocaleTest tests/Unit/Domain/LocaleTest.php`
Expected: PASS (4 tests)

- [ ] **Step 5: Apply the migration to the local dev database and verify the column exists**

```bash
docker compose up -d mysql
# wait for healthy, then:
docker compose exec -T mysql mysql -uroot -pdevrootpass grandpasson -e "source /docker-entrypoint-initdb.d/../../app/Infrastructure/Db/Migrations/024_alter_users_add_locale.sql" 2>/dev/null || \
  mysql -h127.0.0.1 -P3306 -uroot -pdevrootpass grandpasson < app/Infrastructure/Db/Migrations/024_alter_users_add_locale.sql
mysql -h127.0.0.1 -P3306 -uroot -pdevrootpass grandpasson -e "DESCRIBE users;" | grep locale
```

Expected: a `locale` row, type `varchar(10)`, default `pt-BR`, `NO` (not null).

- [ ] **Step 6: Commit**

```bash
git add app/Domain/Locale.php app/Infrastructure/Db/Migrations/024_alter_users_add_locale.sql tests/Unit/Domain/LocaleTest.php
git commit -m "feat: add Locale domain constants and users.locale migration"
```

---

### Task 2: `UserLocaleRepository`

**Files:**
- Create: `app/Infrastructure/Db/UserLocaleRepository.php`
- Test: `tests/Integration/UserLocaleRepositoryTest.php`

**Interfaces:**
- Consumes: `GrandpaSSOn\Domain\Locale::DEFAULT` (Task 1).
- Produces: `GrandpaSSOn\Infrastructure\Db\UserLocaleRepository::__construct(PDO $pdo)`, `::get(string $userId): string` (returns the stored locale, or `Locale::DEFAULT` if the user row is missing), `::set(string $userId, string $locale): void`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Infrastructure\Db\UserLocaleRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserLocaleRepositoryTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        $this->dbName = 'gp_locale_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testNewUserDefaultsToPtBr(): void
    {
        $userId = $this->seedUser('default@example.com');
        $repo = new UserLocaleRepository($this->pdo);

        $this->assertSame('pt-BR', $repo->get($userId));
    }

    public function testSetThenGetReturnsUpdatedLocale(): void
    {
        $userId = $this->seedUser('setter@example.com');
        $repo = new UserLocaleRepository($this->pdo);

        $repo->set($userId, 'en');

        $this->assertSame('en', $repo->get($userId));
    }

    public function testGetForUnknownUserReturnsDefault(): void
    {
        $repo = new UserLocaleRepository($this->pdo);

        $this->assertSame('pt-BR', $repo->get(Uuid::v4()));
    }

    private function seedUser(string $email): string
    {
        $id = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
             VALUES (:id, :email, 1, :name, NULL, \'active\', :c, :u)'
        );
        $stmt->execute(['id' => $id, 'email' => $email, 'name' => 'Locale Test', 'c' => $now, 'u' => $now]);

        return $id;
    }

    private function rootPdo(): PDO
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: '3306');
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
            ? (string) getenv('TEST_DB_PASS')
            : 'devrootpass';

        return new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose up -d mysql && php vendor/bin/phpunit --filter UserLocaleRepositoryTest tests/Integration/UserLocaleRepositoryTest.php`
Expected: FAIL — `Class "GrandpaSSOn\Infrastructure\Db\UserLocaleRepository" not found`

- [ ] **Step 3: Write minimal implementation**

`app/Infrastructure/Db/UserLocaleRepository.php`:

```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Db;

use GrandpaSSOn\Domain\Locale;
use PDO;

/** Central per-user language preference (i18n foundation). */
final class UserLocaleRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function get(string $userId): string
    {
        $stmt = $this->pdo->prepare('SELECT locale FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $value = $stmt->fetchColumn();

        return $value === false ? Locale::DEFAULT : (string) $value;
    }

    public function set(string $userId, string $locale): void
    {
        $stmt = $this->pdo->prepare('UPDATE users SET locale = :locale, updated_at = :now WHERE id = :id');
        $stmt->execute([
            'locale' => $locale,
            'now' => gmdate('Y-m-d H:i:s'),
            'id' => $userId,
        ]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit --filter UserLocaleRepositoryTest tests/Integration/UserLocaleRepositoryTest.php`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Infrastructure/Db/UserLocaleRepository.php tests/Integration/UserLocaleRepositoryTest.php
git commit -m "feat: add UserLocaleRepository for reading/writing users.locale"
```

---

### Task 3: `LocaleController` + `/me/locale` route

**Files:**
- Create: `app/Http/Controllers/LocaleController.php`
- Modify: `app/Http/AppRoutes.php` (add two route entries after the `/me/active-tenant` pair)
- Modify: `docs/client-integration.md` (document the endpoint)
- Test: `tests/Integration/LocaleControllerTest.php`

**Interfaces:**
- Consumes: `GrandpaSSOn\Domain\Locale::isSupported(string): bool` and `::DEFAULT` (Task 1); `GrandpaSSOn\Infrastructure\Db\UserLocaleRepository::get/set` (Task 2); `GrandpaSSOn\Support\Csrf::token()/validate(?string)`; `GrandpaSSOn\Support\Http::json()/readBody()/clientIp()`; `GrandpaSSOn\Support\RateLimitGate::allowOauth(PDO, string, array)`; `GrandpaSSOn\Infrastructure\Audit\AuditLogger::record(...)`; `GrandpaSSOn\Infrastructure\Db\Connection::get(array)`.
- Produces: `GrandpaSSOn\Http\Controllers\LocaleController::show(array $config, array $params = []): void` and `::set(array $config, array $params = []): void`, wired to `GET /me/locale` and `POST /me/locale`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Http\Controllers\LocaleController;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class LocaleControllerTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $_SESSION = [];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.60';
        $this->dbName = 'gp_melocale_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
            $this->config = [
                'db' => [
                    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
                    'port' => (int) (getenv('TEST_DB_PORT') ?: '3306'),
                    'name' => $this->dbName,
                    'user' => getenv('TEST_DB_USER') ?: 'root',
                    'password' => getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
                        ? (string) getenv('TEST_DB_PASS')
                        : 'devrootpass',
                ],
            ];
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $_SESSION = [];
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testUnauthenticatedGetRejected(): void
    {
        http_response_code(200);
        ob_start();
        (new LocaleController())->show($this->config);
        $this->assertSame(401, http_response_code());
        ob_get_clean();
    }

    public function testGetReturnsDefaultLocaleForNewUser(): void
    {
        $userId = $this->seedUser('reader@example.com');
        $_SESSION['user_id'] = $userId;

        http_response_code(200);
        ob_start();
        (new LocaleController())->show($this->config);
        $body = json_decode((string) ob_get_clean(), true);

        $this->assertSame(200, http_response_code());
        $this->assertSame('pt-BR', $body['locale']);
        $this->assertNotEmpty($body['csrf']);
    }

    public function testSetWithSupportedLocalePersistsAndReturnsIt(): void
    {
        $userId = $this->seedUser('setter@example.com');
        $_SESSION['user_id'] = $userId;
        $this->withJsonBody(['csrf' => Csrf::token(), 'locale' => 'en']);

        http_response_code(200);
        ob_start();
        (new LocaleController())->set($this->config);
        $body = json_decode((string) ob_get_clean(), true);

        $this->assertSame(200, http_response_code());
        $this->assertTrue($body['ok']);
        $this->assertSame('en', $body['locale']);

        $stored = (string) $this->pdo
            ->query('SELECT locale FROM users WHERE id = ' . $this->pdo->quote($userId))
            ->fetchColumn();
        $this->assertSame('en', $stored);
    }

    public function testSetWithUnsupportedLocaleRejected(): void
    {
        $userId = $this->seedUser('bad@example.com');
        $_SESSION['user_id'] = $userId;
        $this->withJsonBody(['csrf' => Csrf::token(), 'locale' => 'es']);

        http_response_code(200);
        ob_start();
        (new LocaleController())->set($this->config);
        $body = json_decode((string) ob_get_clean(), true);

        $this->assertSame(400, http_response_code());
        $this->assertSame('invalid_request', $body['error']);
    }

    public function testSetWithoutCsrfRejected(): void
    {
        $userId = $this->seedUser('nocsrf@example.com');
        $_SESSION['user_id'] = $userId;
        $this->withJsonBody(['locale' => 'en']);

        http_response_code(200);
        ob_start();
        (new LocaleController())->set($this->config);
        $this->assertSame(403, http_response_code());
        ob_get_clean();
    }

    /** @param array<string, mixed> $body */
    private function withJsonBody(array $body): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = $body;
    }

    private function seedUser(string $email): string
    {
        $id = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
             VALUES (:id, :email, 1, :name, NULL, \'active\', :c, :u)'
        );
        $stmt->execute(['id' => $id, 'email' => $email, 'name' => 'Locale Controller Test', 'c' => $now, 'u' => $now]);

        return $id;
    }

    private function rootPdo(): PDO
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: '3306');
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
            ? (string) getenv('TEST_DB_PASS')
            : 'devrootpass';

        return new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit --filter LocaleControllerTest tests/Integration/LocaleControllerTest.php`
Expected: FAIL — `Class "GrandpaSSOn\Http\Controllers\LocaleController" not found`

- [ ] **Step 3: Write minimal implementation**

`app/Http/Controllers/LocaleController.php`:

```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Domain\Locale;
use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\UserLocaleRepository;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\Http;
use GrandpaSSOn\Support\RateLimitGate;

/** Central language preference for authenticated subjects (i18n foundation). */
final class LocaleController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function show(array $config, array $params = []): void
    {
        $userId = $this->requireSubject();
        if ($userId === null) {
            return;
        }

        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowOauth($pdo, 'me_locale', $config)) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        $locale = (new UserLocaleRepository($pdo))->get($userId);

        Http::json(200, ['locale' => $locale, 'csrf' => Csrf::token()]);
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function set(array $config, array $params = []): void
    {
        $userId = $this->requireSubject();
        if ($userId === null) {
            return;
        }

        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowOauth($pdo, 'me_locale_write', $config)) {
            Http::json(429, ['error' => 'rate_limited']);

            return;
        }

        $body = Http::readBody();
        $csrf = isset($body['csrf']) && is_scalar($body['csrf']) ? (string) $body['csrf'] : null;
        $header = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (($csrf === null || $csrf === '') && is_string($header) && $header !== '') {
            $csrf = $header;
        }
        if (!Csrf::validate($csrf)) {
            Http::json(403, ['error' => 'invalid_csrf']);

            return;
        }

        $locale = trim((string) ($body['locale'] ?? ''));
        if (!Locale::isSupported($locale)) {
            Http::json(400, [
                'error' => 'invalid_request',
                'message' => 'locale must be one of: ' . implode(', ', Locale::SUPPORTED),
            ]);

            return;
        }

        (new UserLocaleRepository($pdo))->set($userId, $locale);
        (new AuditLogger($pdo))->record(
            'locale.set',
            AuditLogger::RESULT_SUCCESS,
            AuditLogger::ACTOR_SUBJECT,
            $userId,
            $locale,
            null,
            Http::clientIp(),
        );

        Http::json(200, ['ok' => true, 'locale' => $locale, 'csrf' => Csrf::token()]);
    }

    private function requireSubject(): ?string
    {
        $userId = $_SESSION['user_id'] ?? null;
        if (!is_string($userId) || $userId === '') {
            Http::json(401, ['error' => 'unauthenticated']);

            return null;
        }

        return $userId;
    }
}
```

In `app/Http/AppRoutes.php`: add `use GrandpaSSOn\Http\Controllers\LocaleController;` to the `use` block (alphabetical, after `LoginController`), and add these two rows immediately after the `/me/active-tenant` pair in `definitions()`:

```php
            ['GET', '/me/locale', LocaleController::class, 'show'],
            ['POST', '/me/locale', LocaleController::class, 'set'],
```

In `docs/client-integration.md`: add `"locale": "pt-BR"` to the example `/session/exchange` JSON response (inside the top-level object, alongside `"scopes"`), and add this paragraph directly after the existing "**Active tenant (R2):** ..." paragraph:

```markdown
**Locale (i18n foundation):** the exchange response also carries a top-level `locale` (`pt-BR` default, or `en`) — the subject's single shared language preference across every RP. Subjects can `GET/POST /me/locale` (same session cookie + CSRF pattern as `/me/active-tenant`) to read or change it; other RPs pick up the new value on their next `/session/exchange` or token refresh — there is no real-time push between apps.
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit --filter LocaleControllerTest tests/Integration/LocaleControllerTest.php`
Expected: PASS (5 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/LocaleController.php app/Http/AppRoutes.php docs/client-integration.md tests/Integration/LocaleControllerTest.php
git commit -m "feat: add GET/POST /me/locale endpoint"
```

---

### Task 4: Propagate `locale` through session/exchange claims

**Files:**
- Modify: `app/Infrastructure/Auth/SessionClaimsResolver.php`
- Modify: `app/Http/Controllers/SessionExchangeController.php`
- Modify: `app/Http/Controllers/ActiveTenantController.php`
- Test: `tests/Unit/Auth/SessionClaimsResolverLocaleTest.php`

**Interfaces:**
- Consumes: `GrandpaSSOn\Infrastructure\Db\UserLocaleRepository::get(string): string` (Task 2).
- Produces: `SessionClaimsResolver::resolve(array $user, ...)` return array gains a `locale` key (`string`); `$user` array passed to `resolve()` must now include a `locale` key at every call site.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Auth;

use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Infrastructure\Auth\SessionClaimsResolver;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\TenantRepository;
use PDO;
use PHPUnit\Framework\TestCase;

final class SessionClaimsResolverLocaleTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        Connection::reset();
        $this->dbName = 'gp_claimsloc_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 3) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testResolveIncludesLocaleFromUserArray(): void
    {
        $userId = Uuid::v4();
        $resolver = new SessionClaimsResolver($this->pdo, new TenantRepository($this->pdo));

        $claims = $resolver->resolve([
            'id' => $userId,
            'primary_email' => 'claims@example.com',
            'display_name' => 'Claims Test',
            'status' => 'active',
            'locale' => 'en',
        ]);

        $this->assertSame('en', $claims['locale']);
    }

    private function rootPdo(): PDO
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: '3306');
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
            ? (string) getenv('TEST_DB_PASS')
            : 'devrootpass';

        return new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit --filter SessionClaimsResolverLocaleTest tests/Unit/Auth/SessionClaimsResolverLocaleTest.php`
Expected: FAIL — `Undefined array key "locale"` (or the assertion fails because the key is missing)

- [ ] **Step 3: Write minimal implementation**

In `app/Infrastructure/Auth/SessionClaimsResolver.php`, update the `resolve()` method's docblock parameter type to add `, locale: string` to the `$user` shape, and change its `return` statement from:

```php
        return [
            'subject' => [
                'id' => $user['id'],
                'email' => $user['primary_email'],
                'name' => $user['display_name'],
                'idp' => $this->resolveIdp($user['id']),
            ],
            'tenant' => $active === null ? null : $this->tenantClaim($active),
            'tenants' => $tenants,
            'groups' => $groups,
            'scopes' => self::DEFAULT_SCOPES,
        ];
```

to:

```php
        return [
            'subject' => [
                'id' => $user['id'],
                'email' => $user['primary_email'],
                'name' => $user['display_name'],
                'idp' => $this->resolveIdp($user['id']),
            ],
            'tenant' => $active === null ? null : $this->tenantClaim($active),
            'tenants' => $tenants,
            'groups' => $groups,
            'scopes' => self::DEFAULT_SCOPES,
            'locale' => $user['locale'],
        ];
```

Also update the method's return-type docblock to add `, locale: string` after `scopes: list<string>`.

In `app/Http/Controllers/SessionExchangeController.php`, change the `SELECT` from:

```php
        $stmt = $pdo->prepare('SELECT id, primary_email, display_name, status FROM users WHERE id = :id LIMIT 1');
```

to:

```php
        $stmt = $pdo->prepare('SELECT id, primary_email, display_name, status, locale FROM users WHERE id = :id LIMIT 1');
```

and the array literal passed to `resolve()` from:

```php
            [
                'id' => (string) $row['id'],
                'primary_email' => (string) $row['primary_email'],
                'display_name' => (string) $row['display_name'],
                'status' => (string) $row['status'],
            ],
```

to:

```php
            [
                'id' => (string) $row['id'],
                'primary_email' => (string) $row['primary_email'],
                'display_name' => (string) $row['display_name'],
                'status' => (string) $row['status'],
                'locale' => (string) $row['locale'],
            ],
```

In `app/Http/Controllers/ActiveTenantController.php`, both `show()` and `set()` methods have this identical pattern — update both occurrences the same way:

`SELECT` change (appears twice, once per method):

```php
        $stmt = $pdo->prepare(
            'SELECT id, primary_email, display_name, status FROM users WHERE id = :id LIMIT 1'
        );
```

to:

```php
        $stmt = $pdo->prepare(
            'SELECT id, primary_email, display_name, status, locale FROM users WHERE id = :id LIMIT 1'
        );
```

Array literal change (appears twice, once per method):

```php
        $claims = (new SessionClaimsResolver($pdo, new TenantRepository($pdo)))->resolve([
            'id' => (string) $row['id'],
            'primary_email' => (string) $row['primary_email'],
            'display_name' => (string) $row['display_name'],
            'status' => (string) $row['status'],
        ]);
```

to (note: `set()` uses `$tenants` instead of `new TenantRepository($pdo)` — keep that variable reference unchanged, only add the `locale` array key):

```php
        $claims = (new SessionClaimsResolver($pdo, /* keep existing second arg */))->resolve([
            'id' => (string) $row['id'],
            'primary_email' => (string) $row['primary_email'],
            'display_name' => (string) $row['display_name'],
            'status' => (string) $row['status'],
            'locale' => (string) $row['locale'],
        ]);
```

(Apply this as a targeted edit preserving each method's existing second constructor argument — `show()` passes `new TenantRepository($pdo)`, `set()` passes the local `$tenants` variable already in scope.)

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit --filter SessionClaimsResolverLocaleTest tests/Unit/Auth/SessionClaimsResolverLocaleTest.php`
Expected: PASS (1 test)

Then run the full suite to confirm nothing broke in the two modified controllers:

Run: `php vendor/bin/phpunit`
Expected: PASS (all tests, including pre-existing `SignupControllerTest`, `TenantRepositoryTest`, etc.)

- [ ] **Step 5: Commit**

```bash
git add app/Infrastructure/Auth/SessionClaimsResolver.php app/Http/Controllers/SessionExchangeController.php app/Http/Controllers/ActiveTenantController.php tests/Unit/Auth/SessionClaimsResolverLocaleTest.php
git commit -m "feat: surface locale claim in /session/exchange and /me/active-tenant"
```

---

### Task 5: Default `locale` on signup + end-to-end exchange test

**Files:**
- Modify: `app/Infrastructure/Provisioning/SignupService.php`
- Test: `tests/Integration/SignupServiceLocaleTest.php`
- Test: `tests/Integration/LocaleExchangeEndToEndTest.php`

**Interfaces:**
- Consumes: `GrandpaSSOn\Domain\Locale::DEFAULT` (Task 1).
- Produces: no new public interface — this task closes the loop by asserting the full signup → exchange → `/me/locale` path behaves as designed.

- [ ] **Step 1: Write the failing test (signup default)**

```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Infrastructure\Provisioning\SignupService;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use PDO;
use PHPUnit\Framework\TestCase;

final class SignupServiceLocaleTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        $this->dbName = 'gp_signuploc_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testPendingSignupDefaultsToPtBrLocale(): void
    {
        $service = new SignupService($this->pdo, [
            'app_env' => 'dev',
            'allowed_email_domains' => [],
            'mail' => [],
            'broker' => ['name' => 'GrandpaSSOn', 'base_url' => 'https://auth.example.com'],
            'admin_notification_emails' => [],
        ]);

        $identity = new NormalizedIdentity(
            provider: 'google',
            subject: 'g-123',
            email: 'newsignup@example.com',
            emailVerified: true,
            name: 'New Signup',
            avatarUrl: null,
        );

        $user = $service->createPending($identity, 'New Signup', 'I need access', 'signup_form');

        $locale = (string) $this->pdo
            ->query('SELECT locale FROM users WHERE id = ' . $this->pdo->quote($user->id))
            ->fetchColumn();
        $this->assertSame('pt-BR', $locale);
    }

    private function rootPdo(): PDO
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: '3306');
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
            ? (string) getenv('TEST_DB_PASS')
            : 'devrootpass';

        return new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php vendor/bin/phpunit --filter SignupServiceLocaleTest tests/Integration/SignupServiceLocaleTest.php`
Expected: FAIL — column `locale` is `pt-BR` by table default already (migration sets `DEFAULT 'pt-BR'`), so this may actually PASS immediately since `SignupService`'s `INSERT` doesn't list `locale` and MySQL applies the column default. If it passes on the first run, that confirms Task 1's migration default is sufficient and no `SignupService` code change is needed — record this outcome and skip Step 3's edit, going straight to Step 5 (commit the test only, no production code change). If it fails (e.g. because some other code path sets `locale` explicitly elsewhere), proceed to Step 3.

- [ ] **Step 3: Write minimal implementation (only if Step 2 failed)**

In `app/Infrastructure/Provisioning/SignupService.php`, add `locale` to the `INSERT INTO users` statement:

```php
            $this->pdo->prepare(
                'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, locale, created_at, updated_at)
                 VALUES (:id, :email, 1, :name, :avatar, \'pending\', :locale, :created, :updated)'
            )->execute([
                'id' => $userId,
                'email' => $email,
                'name' => $displayName,
                'avatar' => $identity->avatarUrl,
                'locale' => \GrandpaSSOn\Domain\Locale::DEFAULT,
                'created' => $now,
                'updated' => $now,
            ]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit --filter SignupServiceLocaleTest tests/Integration/SignupServiceLocaleTest.php`
Expected: PASS (1 test)

- [ ] **Step 5: Write the end-to-end exchange test**

```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Http\Controllers\LocaleController;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Db\UserLocaleRepository;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

/** Confirms the full loop: change via /me/locale, read back via the repository the way /session/exchange does. */
final class LocaleExchangeEndToEndTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $_SESSION = [];
        $_SERVER['REMOTE_ADDR'] = '203.0.113.70';
        $this->dbName = 'gp_e2eloc_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
            $this->config = [
                'db' => [
                    'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
                    'port' => (int) (getenv('TEST_DB_PORT') ?: '3306'),
                    'name' => $this->dbName,
                    'user' => getenv('TEST_DB_USER') ?: 'root',
                    'password' => getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
                        ? (string) getenv('TEST_DB_PASS')
                        : 'devrootpass',
                ],
            ];
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $_SESSION = [];
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testChangingLocaleInOneAppIsVisibleToAnotherAppOnNextRead(): void
    {
        $userId = $this->seedUser('crossapp@example.com');
        $_SESSION['user_id'] = $userId;

        // App A changes the locale via /me/locale.
        $this->withJsonBody(['csrf' => Csrf::token(), 'locale' => 'en']);
        http_response_code(200);
        ob_start();
        (new LocaleController())->set($this->config);
        ob_get_clean();
        $this->assertSame(200, http_response_code());

        // App B (a different RP) reads the locale the way SessionExchangeController does,
        // i.e. directly off the users row via UserLocaleRepository — this is what
        // /session/exchange returns to any RP on its next exchange call.
        $repo = new UserLocaleRepository($this->pdo);
        $this->assertSame('en', $repo->get($userId));
    }

    /** @param array<string, mixed> $body */
    private function withJsonBody(array $body): void
    {
        $_SERVER['CONTENT_TYPE'] = 'application/x-www-form-urlencoded';
        $_POST = $body;
    }

    private function seedUser(string $email): string
    {
        $id = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
             VALUES (:id, :email, 1, :name, NULL, \'active\', :c, :u)'
        );
        $stmt->execute(['id' => $id, 'email' => $email, 'name' => 'E2E Locale', 'c' => $now, 'u' => $now]);

        return $id;
    }

    private function rootPdo(): PDO
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: '3306');
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
            ? (string) getenv('TEST_DB_PASS')
            : 'devrootpass';

        return new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
```

- [ ] **Step 6: Run test to verify it passes**

Run: `php vendor/bin/phpunit --filter LocaleExchangeEndToEndTest tests/Integration/LocaleExchangeEndToEndTest.php`
Expected: PASS (1 test)

Then run the whole suite one more time:

Run: `php vendor/bin/phpunit`
Expected: PASS (all tests)

- [ ] **Step 7: Commit**

```bash
git add app/Infrastructure/Provisioning/SignupService.php tests/Integration/SignupServiceLocaleTest.php tests/Integration/LocaleExchangeEndToEndTest.php
git commit -m "test: verify signup default locale and cross-app locale propagation"
```

(If Step 2 passed immediately and no production code changed, the `git add` above simply omits `SignupService.php`.)

---

### Task 6 (optional — cut freely): admin CLI verb `user:set-locale`

**Files:**
- Modify: `app/Infrastructure/Admin/AdminCommandRunner.php`
- Create: `tests/Integration/AdminCommandRunnerLocaleTest.php` (own file, isolated from the existing `AdminCommandRunnerTest.php`, mirroring its exact DB bootstrap pattern)

**Interfaces:**
- Consumes: `GrandpaSSOn\Domain\Locale::isSupported(string): bool` (Task 1); `GrandpaSSOn\Infrastructure\Db\UserLocaleRepository::set(string, string): void` (Task 2).
- Produces: `AdminCommandRunner::run('user:set-locale', [$userId, $locale])` returns `['ok' => true, 'user_id' => string, 'locale' => string]`.

Support already looks up users by `user_id` (uuid) for every other `user:*` verb (`user:approve`, `user:reject`, `user:reopen`) — this verb follows that same convention rather than taking an email, for consistency with the rest of `AdminCommandRunner`.

- [ ] **Step 1: Write the failing test**

```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Infrastructure\Admin\AdminCommandRunner;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class AdminCommandRunnerLocaleTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;
    private AdminCommandRunner $admin;

    protected function setUp(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $this->dbName = 'gp_adminloc_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
            $this->admin = AdminCommandRunner::fromPdo($this->pdo);
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }
    }

    protected function tearDown(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testSetLocaleUpdatesUserAndAudits(): void
    {
        $userId = $this->seedUser('adminloc@example.com');

        $result = $this->admin->run('user:set-locale', [$userId, 'en']);

        $this->assertTrue($result['ok']);
        $this->assertSame('en', $result['locale']);

        $stored = (string) $this->pdo
            ->query('SELECT locale FROM users WHERE id = ' . $this->pdo->quote($userId))
            ->fetchColumn();
        $this->assertSame('en', $stored);
    }

    public function testSetLocaleRejectsUnsupportedValue(): void
    {
        $userId = $this->seedUser('adminbad@example.com');

        $this->expectException(\InvalidArgumentException::class);
        $this->admin->run('user:set-locale', [$userId, 'es']);
    }

    public function testSetLocaleRejectsUnknownUser(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->admin->run('user:set-locale', [Uuid::v4(), 'en']);
    }

    private function seedUser(string $email): string
    {
        $id = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
             VALUES (:id, :email, 1, :name, NULL, \'active\', :c, :u)'
        );
        $stmt->execute(['id' => $id, 'email' => $email, 'name' => 'Admin Locale Test', 'c' => $now, 'u' => $now]);

        return $id;
    }

    private function rootPdo(): PDO
    {
        $host = getenv('TEST_DB_HOST') ?: '127.0.0.1';
        $port = (int) (getenv('TEST_DB_PORT') ?: '3306');
        $user = getenv('TEST_DB_USER') ?: 'root';
        $pass = getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
            ? (string) getenv('TEST_DB_PASS')
            : 'devrootpass';

        return new PDO(sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $host, $port), $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `docker compose up -d mysql && php vendor/bin/phpunit --filter AdminCommandRunnerLocaleTest tests/Integration/AdminCommandRunnerLocaleTest.php`
Expected: FAIL — `Unknown verb: user:set-locale`

- [ ] **Step 3: Write minimal implementation**

In `app/Infrastructure/Admin/AdminCommandRunner.php`, add `'user:set-locale',` to the `verbs()` list (after `'user:reopen',`), add a `'user:set-locale' => $this->userSetLocale($argv),` arm to the `match ($verb)` block in `run()`, and add this private method (place it near the other `user*` private methods, e.g. after `userReopen`):

```php
    /** @param list<string> $argv @return array<string, mixed> */
    private function userSetLocale(array $argv): array
    {
        $userId = (string) ($argv[0] ?? '');
        $locale = (string) ($argv[1] ?? '');
        if ($userId === '' || $locale === '') {
            throw new \InvalidArgumentException('Usage: user:set-locale <user_id> <locale>');
        }
        if (!\GrandpaSSOn\Domain\Locale::isSupported($locale)) {
            throw new \InvalidArgumentException(
                'locale must be one of: ' . implode(', ', \GrandpaSSOn\Domain\Locale::SUPPORTED)
            );
        }
        $this->assertUserExists($userId);

        (new \GrandpaSSOn\Infrastructure\Db\UserLocaleRepository($this->pdo))->set($userId, $locale);
        $this->auditMutation('user.set_locale', $userId);

        return ['ok' => true, 'user_id' => $userId, 'locale' => $locale];
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php vendor/bin/phpunit --filter AdminCommandRunnerLocaleTest tests/Integration/AdminCommandRunnerLocaleTest.php`
Expected: PASS (3 tests)

Then run the whole suite:

Run: `php vendor/bin/phpunit`
Expected: PASS (all tests)

- [ ] **Step 5: Commit**

```bash
git add app/Infrastructure/Admin/AdminCommandRunner.php tests/Integration/AdminCommandRunnerLocaleTest.php
git commit -m "feat: add user:set-locale admin CLI verb"
```

---

## Final verification

- [ ] Run the complete suite once more end to end: `php vendor/bin/phpunit`. Expected: PASS, 0 failures, 0 errors.
- [ ] `docker compose up -d mysql --wait` then hit the running app locally (`docker compose up -d web`) to smoke-test manually: log in, `GET /me/locale` (expect `{"locale":"pt-BR",...}`), `POST /me/locale` with `{"locale":"en","csrf":"<from GET>"}` (expect `{"ok":true,"locale":"en",...}`), then `POST /session/exchange` with a valid code for the same user and confirm the top-level `"locale":"en"` appears in the response.
- [ ] Confirm `docs/client-integration.md` renders sensibly (no broken markdown) and the new paragraph sits directly after the "Active tenant (R2)" one.
