# Self-enrollment with admin approval — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** New users can request a GrandpaSSOn account (email OTP or OAuth), land in `pending` status, and gain no access until an admin approves them via CLI or the existing `/admin` HTML UI.

**Architecture:** `UserProvisioner::resolve()` stops auto-creating accounts — it only looks up existing users and now distinguishes "not found" / "pending" / "rejected" / "disabled" via typed exceptions. A new `SignupService` owns account creation, always landing in `status='pending'` plus a `signup_requests` audit row. A new `SignupController` drives two entry points (email OTP, and OAuth-completion reached when `CallbackController` catches "not found"). Four new `AdminCommandRunner` verbs (`user:list-pending`, `user:approve`, `user:reject`, `user:reopen`) do the approval — they're exposed for free by both `cron/admin.php` and the generic `/admin` HTML UI, which already run any verb from `AdminCommandRunner::verbs()`. No jotter (or other federated app) code changes: a `pending`/`rejected` user simply never obtains a valid `AUTHSESSID` session.

**Tech Stack:** PHP 8.2, PDO/MySQL, PHPUnit (integration tests spin up a real MySQL schema per test, skip if unavailable — see `tests/Integration/UserProvisionerTest.php`), sendmail/SMTP mailer already wired via `Infrastructure/Mail`.

## Global Constraints

- Every SQL migration file added under `app/Infrastructure/Db/Migrations/` must also be copied to `docker/mysql/init/` — `make check-migrations` diffs the two directories and fails the build otherwise.
- Follow the existing code style exactly: `declare(strict_types=1)`, `final class`, constructor property promotion, `Html::e()` for all interpolated HTML, `Csrf::token()`/`Csrf::validate()` on every form, `RateLimitGate::allowDb`-backed throttles on every POST that touches the DB or sends mail.
- Never log or store raw OTP codes, tokens, or secrets — mirror `AuditLogger::assertNoSecrets` conventions already in the codebase.
- Design spec: `docs/superpowers/specs/2026-08-06-self-enrollment-admin-approval-design.md`.

---

## Task 1: Migrations — pending/rejected status + signup_requests table

**Files:**
- Create: `app/Infrastructure/Db/Migrations/022_alter_users_status_add_pending_rejected.sql`
- Create: `app/Infrastructure/Db/Migrations/023_create_signup_requests.sql`
- Create: `docker/mysql/init/022_alter_users_status_add_pending_rejected.sql` (identical copy)
- Create: `docker/mysql/init/023_create_signup_requests.sql` (identical copy)
- Test: `tests/Schema` (existing schema tests already glob the Migrations directory — verify with the check below, no new test file needed)

**Interfaces:**
- Produces: `users.status` ENUM now includes `'pending'`, `'rejected'` (existing `'active'`, `'disabled'` unchanged). New table `signup_requests(id, user_id, email, display_name, justification, source, status, reviewed_by, reviewed_at, rejection_reason, created_at, updated_at)`.

- [ ] **Step 1: Write the migration files**

`app/Infrastructure/Db/Migrations/022_alter_users_status_add_pending_rejected.sql`:
```sql
-- Self-enrollment with admin approval: new signups start 'pending' until
-- an admin approves them, and may be permanently 'rejected'.
ALTER TABLE users
  MODIFY COLUMN status ENUM('active','disabled','pending','rejected') NOT NULL DEFAULT 'active';
```

`app/Infrastructure/Db/Migrations/023_create_signup_requests.sql`:
```sql
-- Audit trail for self-enrollment requests, 1:1 with the pending `users`
-- row created at signup time. Kept even after approve/reject for audit.
CREATE TABLE IF NOT EXISTS signup_requests (
  id CHAR(36) NOT NULL PRIMARY KEY,
  user_id CHAR(36) NOT NULL,
  email VARCHAR(255) NOT NULL,
  display_name VARCHAR(255) NOT NULL,
  justification TEXT NOT NULL,
  source ENUM('email','google','microsoft','github') NOT NULL,
  status ENUM('pending','approved','rejected') NOT NULL DEFAULT 'pending',
  reviewed_by VARCHAR(255) NULL,
  reviewed_at DATETIME NULL,
  rejection_reason TEXT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  UNIQUE KEY uniq_signup_requests_user (user_id),
  KEY idx_signup_requests_status (status),
  FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
```

- [ ] **Step 2: Copy both files verbatim into `docker/mysql/init/`**

```bash
cp app/Infrastructure/Db/Migrations/022_alter_users_status_add_pending_rejected.sql docker/mysql/init/
cp app/Infrastructure/Db/Migrations/023_create_signup_requests.sql docker/mysql/init/
```

- [ ] **Step 3: Verify migrations apply cleanly against a throwaway database**

Run:
```bash
make check-migrations
```
Expected: no output (directories match).

Then, with a reachable MySQL (docker `make up` running, or `TEST_DB_*` env vars set), confirm the new SQL is syntactically valid by letting the existing integration test bootstrap pick it up (it globs `app/Infrastructure/Db/Migrations/*.sql` and applies every file to a scratch DB):
```bash
php vendor/bin/phpunit tests/Integration/UserProvisionerTest.php
```
Expected: PASS (existing tests still pass — the enum widening and new table are additive and don't break current behavior yet).

- [ ] **Step 4: Commit**

```bash
git add app/Infrastructure/Db/Migrations/022_alter_users_status_add_pending_rejected.sql \
        app/Infrastructure/Db/Migrations/023_create_signup_requests.sql \
        docker/mysql/init/022_alter_users_status_add_pending_rejected.sql \
        docker/mysql/init/023_create_signup_requests.sql
git commit -m "feat: add pending/rejected user status and signup_requests table"
```

---

## Task 2: UserProvisioner stops auto-creating; typed not-found/pending/rejected exceptions

**Files:**
- Create: `app/Infrastructure/Providers/AccountNotFoundException.php`
- Create: `app/Infrastructure/Providers/AccountPendingException.php`
- Create: `app/Infrastructure/Providers/AccountRejectedException.php`
- Create: `app/Infrastructure/Provisioning/LinkedIdentityWriter.php`
- Modify: `app/Infrastructure/Provisioning/UserProvisioner.php` (full rewrite of `resolve()`; drop `createUser()`, `assertMayAutoCreate()`; drop the `$config` constructor param)
- Modify: `app/Http/Controllers/CallbackController.php:78-82` (constructor call site)
- Modify: `app/Http/Controllers/EmailOtpLoginController.php:187-190` (constructor call site)
- Test: `tests/Integration/UserProvisionerTest.php` (rewrite)

**Interfaces:**
- Produces: `UserProvisioner::__construct(PDO $pdo)` (no more `$config` array). `resolve(NormalizedIdentity $identity): User` throws `AccountNotFoundException` (no match by subject or email), `AccountPendingException`, `AccountRejectedException`, or the existing `ProviderException` (disabled / unverified email) — all three new classes extend `ProviderException`, so any existing `catch (ProviderException $e)` still compiles and still catches them, but call sites that need to branch can catch the specific subclass first.
- Produces: `LinkedIdentityWriter::insert(PDO $pdo, string $userId, NormalizedIdentity $identity): void` — shared by `UserProvisioner` (linking a second provider to an existing account) and `SignupService` (Task 4).
- Consumes: `GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity`, `GrandpaSSOn\Domain\User`, `GrandpaSSOn\Domain\Uuid`.

- [ ] **Step 1: Write the failing tests (full replacement of the integration test)**

Replace `tests/Integration/UserProvisionerTest.php` entirely:
```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Providers\AccountNotFoundException;
use GrandpaSSOn\Infrastructure\Providers\AccountPendingException;
use GrandpaSSOn\Infrastructure\Providers\AccountRejectedException;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use GrandpaSSOn\Infrastructure\Provisioning\UserProvisioner;
use GrandpaSSOn\Domain\Uuid;
use PDO;
use PHPUnit\Framework\TestCase;

final class UserProvisionerTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        $this->dbName = 'gp_prov_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
            Connection::reset();
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
            Connection::reset();
        }
    }

    public function testThrowsAccountNotFoundForUnknownIdentity(): void
    {
        $provisioner = new UserProvisioner($this->pdo);

        $this->expectException(AccountNotFoundException::class);
        $provisioner->resolve(new NormalizedIdentity(
            'google',
            'sub-unknown',
            'nobody@example.com',
            true,
            'Nobody',
        ));
    }

    public function testRefusesUnverifiedEmailOnUnknownIdentity(): void
    {
        $provisioner = new UserProvisioner($this->pdo);

        $this->expectException(ProviderException::class);
        $provisioner->resolve(new NormalizedIdentity(
            'microsoft',
            'sub-upn',
            'user@contoso.com',
            false,
            'User',
        ));
    }

    public function testResolvesExistingActiveUserBySubject(): void
    {
        $userId = $this->seedUser('alice@example.com', 'active', 'google', 'g-alice');
        $provisioner = new UserProvisioner($this->pdo);

        $user = $provisioner->resolve(new NormalizedIdentity(
            'google',
            'g-alice',
            'alice@example.com',
            true,
            'Alice Updated',
        ));

        $this->assertSame($userId, $user->id);
        $this->assertSame('Alice Updated', $user->displayName);
        $this->assertTrue($user->isActive());
    }

    public function testLinksNewProviderToExistingActiveUserByEmail(): void
    {
        $userId = $this->seedUser('link@example.com', 'active', 'google', 'g-1');
        $provisioner = new UserProvisioner($this->pdo);

        $linked = $provisioner->resolve(new NormalizedIdentity(
            'github',
            'gh-1',
            'link@example.com',
            true,
            'Link',
            null,
            'link',
        ));

        $this->assertSame($userId, $linked->id);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM linked_identities')->fetchColumn();
        $this->assertSame(2, $count);
    }

    public function testThrowsAccountPendingForPendingUser(): void
    {
        $this->seedUser('pending@example.com', 'pending', 'google', 'g-pending');
        $provisioner = new UserProvisioner($this->pdo);

        $this->expectException(AccountPendingException::class);
        $provisioner->resolve(new NormalizedIdentity(
            'google',
            'g-pending',
            'pending@example.com',
            true,
            'Pending Person',
        ));
    }

    public function testThrowsAccountRejectedForRejectedUser(): void
    {
        $this->seedUser('rejected@example.com', 'rejected', 'google', 'g-rejected');
        $provisioner = new UserProvisioner($this->pdo);

        $this->expectException(AccountRejectedException::class);
        $provisioner->resolve(new NormalizedIdentity(
            'google',
            'g-rejected',
            'rejected@example.com',
            true,
            'Rejected Person',
        ));
    }

    public function testThrowsGenericProviderExceptionForDisabledUser(): void
    {
        $this->seedUser('disabled@example.com', 'disabled', 'google', 'g-disabled');
        $provisioner = new UserProvisioner($this->pdo);

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('disabled');
        $provisioner->resolve(new NormalizedIdentity(
            'google',
            'g-disabled',
            'disabled@example.com',
            true,
            'Disabled Person',
        ));
    }

    /** Seeds a user + one linked identity directly via SQL — resolve() no longer creates accounts. */
    private function seedUser(string $email, string $status, string $provider, string $subject): string
    {
        $id = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
             VALUES (:id, :email, 1, :name, NULL, :status, :now, :now)'
        )->execute(['id' => $id, 'email' => $email, 'name' => 'Seed User', 'status' => $status, 'now' => $now]);
        $this->pdo->prepare(
            'INSERT INTO linked_identities (id, user_id, provider, provider_subject, provider_email, provider_username, raw_claims_json, linked_at, last_login_at)
             VALUES (:lid, :uid, :provider, :subject, :email, NULL, :raw, :now, :now)'
        )->execute([
            'lid' => Uuid::v4(),
            'uid' => $id,
            'provider' => $provider,
            'subject' => $subject,
            'email' => $email,
            'raw' => '{}',
            'now' => $now,
        ]);

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

- [ ] **Step 2: Run the test to verify it fails**

Run: `php vendor/bin/phpunit tests/Integration/UserProvisionerTest.php`
Expected: FAIL — `AccountNotFoundException` class doesn't exist yet, and current `resolve()` still auto-creates (so `testThrowsAccountNotFoundForUnknownIdentity` would currently succeed in creating a user rather than throwing).

- [ ] **Step 3: Create the exception classes**

`app/Infrastructure/Providers/AccountNotFoundException.php`:
```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Providers;

/**
 * Thrown by UserProvisioner::resolve() when no user matches the identity.
 * CallbackController catches this to redirect into the signup-completion
 * screen instead of treating it as a generic login failure.
 */
final class AccountNotFoundException extends ProviderException
{
}
```

`app/Infrastructure/Providers/AccountPendingException.php`:
```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Providers;

/**
 * Thrown by UserProvisioner::resolve() when the matched user is awaiting
 * admin approval (self-enrollment).
 */
final class AccountPendingException extends ProviderException
{
}
```

`app/Infrastructure/Providers/AccountRejectedException.php`:
```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Providers;

/**
 * Thrown by UserProvisioner::resolve() when the matched user's signup was
 * rejected by an admin.
 */
final class AccountRejectedException extends ProviderException
{
}
```

- [ ] **Step 4: Create `LinkedIdentityWriter`**

`app/Infrastructure/Provisioning/LinkedIdentityWriter.php`:
```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Provisioning;

use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use PDO;

/**
 * Shared linked_identities INSERT — used by UserProvisioner (linking a new
 * provider to an already-active account) and SignupService (first-time
 * signup), so the column list only lives in one place.
 */
final class LinkedIdentityWriter
{
    public static function insert(PDO $pdo, string $userId, NormalizedIdentity $identity): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $stmt = $pdo->prepare(
            'INSERT INTO linked_identities
             (id, user_id, provider, provider_subject, provider_email, provider_username, raw_claims_json, linked_at, last_login_at)
             VALUES (:id, :user_id, :provider, :subject, :email, :username, :raw, :linked_at, :last_login)'
        );
        $stmt->execute([
            'id' => Uuid::v4(),
            'user_id' => $userId,
            'provider' => $identity->provider,
            'subject' => $identity->subject,
            'email' => $identity->email,
            'username' => $identity->username,
            'raw' => json_encode($identity->rawClaims, JSON_THROW_ON_ERROR),
            'linked_at' => $now,
            'last_login' => $now,
        ]);
    }
}
```

- [ ] **Step 5: Rewrite `UserProvisioner`**

`app/Infrastructure/Provisioning/UserProvisioner.php` (full replacement):
```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Provisioning;

use GrandpaSSOn\Domain\User;
use GrandpaSSOn\Infrastructure\Providers\AccountNotFoundException;
use GrandpaSSOn\Infrastructure\Providers\AccountPendingException;
use GrandpaSSOn\Infrastructure\Providers\AccountRejectedException;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use PDO;

/**
 * Resolves an already-existing user for a normalized identity (login only —
 * never creates accounts; see SignupService for that). Distinguishes
 * not-found / pending / rejected / disabled so callers can route each case
 * to the right screen.
 */
final class UserProvisioner
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    /**
     * @throws AccountNotFoundException No user matches this identity.
     * @throws AccountPendingException Matched user is awaiting approval.
     * @throws AccountRejectedException Matched user's signup was rejected.
     * @throws ProviderException Matched user is disabled, or email is unverified.
     */
    public function resolve(NormalizedIdentity $identity): User
    {
        $existing = $this->findByProviderSubject($identity->provider, $identity->subject);
        if ($existing !== null) {
            $this->assertUsable($existing);
            $this->syncProfileAndTouch($existing, $identity);

            return $this->findById($existing->id) ?? $existing;
        }

        if ($identity->email === null || $identity->email === '' || !$identity->emailVerified) {
            throw new ProviderException('Verified email is required to provision or link an account');
        }

        $email = strtolower($identity->email);
        $byEmail = $this->findByEmail($email);
        if ($byEmail !== null) {
            $this->assertUsable($byEmail);
            LinkedIdentityWriter::insert($this->pdo, $byEmail->id, $identity);
            $this->syncProfileAndTouch($byEmail, $identity);

            return $this->findById($byEmail->id) ?? $byEmail;
        }

        throw new AccountNotFoundException('No account found for this identity; sign up first.');
    }

    private function assertUsable(User $user): void
    {
        if ($user->status === 'pending') {
            throw new AccountPendingException('Your account is awaiting admin approval');
        }
        if ($user->status === 'rejected') {
            throw new AccountRejectedException('Your signup was not approved');
        }
        if (!$user->isActive()) {
            throw new ProviderException('User account is disabled');
        }
    }

    private function syncProfileAndTouch(User $user, NormalizedIdentity $identity): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $name = $identity->name ?: $user->displayName;
        $avatar = $identity->avatarUrl ?? $user->avatarUrl;

        $stmt = $this->pdo->prepare(
            'UPDATE users SET display_name = :name, avatar_url = :avatar, updated_at = :updated WHERE id = :id'
        );
        $stmt->execute([
            'name' => $name,
            'avatar' => $avatar,
            'updated' => $now,
            'id' => $user->id,
        ]);

        $touch = $this->pdo->prepare(
            'UPDATE linked_identities
             SET provider_email = :email, provider_username = :username, raw_claims_json = :raw, last_login_at = :last_login
             WHERE provider = :provider AND provider_subject = :subject'
        );
        $touch->execute([
            'email' => $identity->email,
            'username' => $identity->username,
            'raw' => json_encode($identity->rawClaims, JSON_THROW_ON_ERROR),
            'last_login' => $now,
            'provider' => $identity->provider,
            'subject' => $identity->subject,
        ]);
    }

    private function findByProviderSubject(string $provider, string $subject): ?User
    {
        $stmt = $this->pdo->prepare(
            'SELECT u.* FROM users u
             INNER JOIN linked_identities li ON li.user_id = u.id
             WHERE li.provider = :provider AND li.provider_subject = :subject
             LIMIT 1'
        );
        $stmt->execute(['provider' => $provider, 'subject' => $subject]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapUser($row);
    }

    private function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE primary_email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapUser($row);
    }

    private function findById(string $id): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapUser($row);
    }

    /** @param array<string, mixed> $row */
    private function mapUser(array $row): User
    {
        return new User(
            id: (string) $row['id'],
            primaryEmail: (string) $row['primary_email'],
            emailVerified: (bool) $row['email_verified'],
            displayName: (string) $row['display_name'],
            avatarUrl: $row['avatar_url'] !== null ? (string) $row['avatar_url'] : null,
            status: (string) $row['status'],
        );
    }
}
```
- [ ] **Step 6: Update the two call sites' constructor calls**

In `app/Http/Controllers/CallbackController.php`, replace:
```php
            $provisioner = new UserProvisioner($pdo, [
                'app_env' => (string) $config['app_env'],
                'allowed_email_domains' => $config['allowed_email_domains'] ?? [],
            ]);
```
with:
```php
            $provisioner = new UserProvisioner($pdo);
```

In `app/Http/Controllers/EmailOtpLoginController.php`, replace:
```php
            $provisioner = new UserProvisioner($pdo, [
                'app_env' => (string) $config['app_env'],
                'allowed_email_domains' => $config['allowed_email_domains'] ?? [],
            ]);
```
with:
```php
            $provisioner = new UserProvisioner($pdo);
```

Leave the rest of both files untouched for now — the new catch blocks for `AccountNotFoundException`/`AccountPendingException`/`AccountRejectedException` are added in Task 6 and Task 7 so this task stays focused on `UserProvisioner` itself. For now the existing `catch (ProviderException $e)` in both files still compiles and still catches the new subclasses (as a generic failure) — that's an acceptable intermediate state since Task 6/7 land in the same PR series.

- [ ] **Step 7: Run the test to verify it passes**

Run: `php vendor/bin/phpunit tests/Integration/UserProvisionerTest.php`
Expected: PASS (all 7 tests).

- [ ] **Step 8: Run the full suite to catch any other break**

Run: `php vendor/bin/phpunit`
Expected: exactly one failure — `tests/Integration/EmailOtpLoginControllerTest.php::testStartIssuesCodeAndVerifyCompletesRoundTripToRp` — because it currently asserts that a brand-new email auto-creates a user and redirects with a `code` (302). That assumption is gone by design; Task 7 rewrites this specific test to assert the new "no account found" behavior instead. Confirm no other test fails — if something else breaks, stop and investigate before continuing (don't paper over an unrelated regression by attributing it to this known, single expected failure).

- [ ] **Step 9: Commit**

```bash
git add app/Infrastructure/Providers/AccountNotFoundException.php \
        app/Infrastructure/Providers/AccountPendingException.php \
        app/Infrastructure/Providers/AccountRejectedException.php \
        app/Infrastructure/Provisioning/LinkedIdentityWriter.php \
        app/Infrastructure/Provisioning/UserProvisioner.php \
        app/Http/Controllers/CallbackController.php \
        app/Http/Controllers/EmailOtpLoginController.php \
        tests/Integration/UserProvisionerTest.php
git commit -m "feat: UserProvisioner no longer auto-creates accounts; typed not-found/pending/rejected exceptions"
```

---

## Task 3: Config — `ADMIN_NOTIFICATION_EMAILS`

**Files:**
- Modify: `app/Config/ConfigLoader.php`
- Modify: `.env.example`
- Test: `tests/Unit/Config/ConfigLoaderTest.php` (create if it doesn't already cover this — check first with `find tests -iname "*ConfigLoader*"`; if a test file already exists, add cases to it instead of creating a new one)

**Interfaces:**
- Produces: `config('admin_notification_emails')` → `list<string>` (lower-cased, trimmed, empty entries dropped — same parsing shape as `allowed_email_domains`, but not lower-cased since these are literal addresses to send to, not domains to match).

- [ ] **Step 1: Check for an existing ConfigLoader test**

Run: `find tests -iname "*ConfigLoader*"`

If a file exists, read it fully before editing — match its existing style (fixture `.env` array, `ConfigLoader::load()` called against a temp file or via process env, per whatever pattern is already there).

- [ ] **Step 2: Write the failing test**

Add a test asserting the new key parses correctly, e.g. (adapt to the existing file's fixture style — this shows the assertion shape, not necessarily the exact harness):
```php
public function testParsesAdminNotificationEmails(): void
{
    $env = $this->baseEnv(); // however the existing file builds a minimal valid env array
    $env['ADMIN_NOTIFICATION_EMAILS'] = ' admin1@example.com, Admin2@Example.com ,,';

    $config = $this->loadFromEnv($env); // however the existing file invokes ConfigLoader::load()

    $this->assertSame(['admin1@example.com', 'admin2@example.com'], $config['admin_notification_emails']);
}

public function testDefaultsAdminNotificationEmailsToEmptyList(): void
{
    $config = $this->loadFromEnv($this->baseEnv());

    $this->assertSame([], $config['admin_notification_emails']);
}
```
If no `ConfigLoaderTest` exists yet, create `tests/Unit/Config/ConfigLoaderTest.php` writing a temp `.env` file with all required keys (copy the `$required` list from `ConfigLoader::load()`) plus the two cases above, calling `ConfigLoader::load($tempPath)`.

- [ ] **Step 3: Run to verify it fails**

Run: `php vendor/bin/phpunit tests/Unit/Config/ConfigLoaderTest.php --filter AdminNotificationEmails`
Expected: FAIL — `admin_notification_emails` key doesn't exist in the returned array.

- [ ] **Step 4: Add the key to `ConfigLoader`**

In `app/Config/ConfigLoader.php`, add `'ADMIN_NOTIFICATION_EMAILS'` to the `knownKeys()` list (alongside `'ALLOWED_EMAIL_DOMAINS'`), and in `load()`, after the existing `$domainsRaw`/`$domains` block, add:
```php
        $adminEmailsRaw = $env['ADMIN_NOTIFICATION_EMAILS'] ?? '';
        $adminEmails = array_values(array_filter(
            array_map(
                static fn (string $e): string => strtolower(trim($e)),
                explode(',', $adminEmailsRaw)
            ),
            static fn (string $e): bool => $e !== ''
        ));
```
Then add `'admin_notification_emails' => $adminEmails,` to the returned array, right after the existing `'allowed_email_domains' => $domains,` line. Also add `admin_notification_emails: list<string>,` to the class docblock's `@return` shape, next to `allowed_email_domains`.

- [ ] **Step 5: Run to verify it passes**

Run: `php vendor/bin/phpunit tests/Unit/Config/ConfigLoaderTest.php`
Expected: PASS.

- [ ] **Step 6: Document the env var**

In `.env.example`, after the `MAIL_*` block, add:
```
# Comma-separated list of admin emails notified when a new signup request
# arrives. Leave empty to disable admin notification email (approvals still
# work via CLI/HTML UI list-pending).
ADMIN_NOTIFICATION_EMAILS=
```

- [ ] **Step 7: Run the full config test file and full suite**

Run: `php vendor/bin/phpunit tests/Unit/Config/ConfigLoaderTest.php && php vendor/bin/phpunit`
Expected: PASS (no regressions elsewhere — this task only adds a key, doesn't remove or rename one).

- [ ] **Step 8: Commit**

```bash
git add app/Config/ConfigLoader.php .env.example tests/Unit/Config/ConfigLoaderTest.php
git commit -m "feat: add ADMIN_NOTIFICATION_EMAILS config for signup approval alerts"
```

---

## Task 4: `SignupService` — creates the pending user + signup_requests row

**Files:**
- Create: `app/Infrastructure/Provisioning/SignupService.php`
- Test: `tests/Integration/SignupServiceTest.php`

**Interfaces:**
- Consumes: `LinkedIdentityWriter::insert()` (Task 2), `GrandpaSSOn\Infrastructure\Mail\MailerFactory`, `GrandpaSSOn\Domain\{User,Uuid}`, `GrandpaSSOn\Infrastructure\Providers\{NormalizedIdentity,ProviderException}`.
- Produces: `SignupService::__construct(PDO $pdo, array $config)` where `$config` has keys `app_env`, `allowed_email_domains` (list<string>), `mail` (the full mail config array), `broker` (`['name' => ..., 'base_url' => ...]`), `admin_notification_emails` (list<string>). `createPending(NormalizedIdentity $identity, string $displayName, string $justification, string $source): User` — throws `ProviderException` on: unverified/missing email, empty name/justification, domain not allowed, or an account already exists for that email. `assertEmailAllowed(string $email): void` — throws `ProviderException` if the domain gate rejects it (exposed separately so `SignupController::start()` can validate *before* sending an OTP).

- [ ] **Step 1: Write the failing tests**

`tests/Integration/SignupServiceTest.php`:
```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use GrandpaSSOn\Infrastructure\Provisioning\SignupService;
use PDO;
use PHPUnit\Framework\TestCase;

final class SignupServiceTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;

    protected function setUp(): void
    {
        $this->dbName = 'gp_signup_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $this->pdo = $root;
            Connection::reset();
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
            Connection::reset();
        }
    }

    private function config(array $overrides = []): array
    {
        return array_merge([
            'app_env' => 'dev',
            'allowed_email_domains' => [],
            'mail' => ['transport' => 'sendmail', 'from_address' => 'noreply@example.com', 'from_name' => 'Test'],
            'broker' => ['name' => 'Test Broker', 'base_url' => 'https://sso.example.com'],
            'admin_notification_emails' => [],
        ], $overrides);
    }

    public function testCreatesPendingUserWithSignupRequest(): void
    {
        $service = new SignupService($this->pdo, $this->config());

        $user = $service->createPending(
            new NormalizedIdentity('google', 'g-1', 'alice@example.com', true, 'Alice'),
            'Alice',
            'I need access to review reports.',
            'google',
        );

        $this->assertSame('pending', $user->status);
        $this->assertFalse($user->isActive());

        $row = $this->pdo->query('SELECT status FROM users WHERE id = ' . $this->pdo->quote($user->id))->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $row['status']);

        $request = $this->pdo->query(
            'SELECT status, source, justification FROM signup_requests WHERE user_id = ' . $this->pdo->quote($user->id)
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $request['status']);
        $this->assertSame('google', $request['source']);
        $this->assertSame('I need access to review reports.', $request['justification']);

        $linkedCount = (int) $this->pdo->query(
            'SELECT COUNT(*) FROM linked_identities WHERE user_id = ' . $this->pdo->quote($user->id)
        )->fetchColumn();
        $this->assertSame(1, $linkedCount);
    }

    public function testRejectsDuplicateEmail(): void
    {
        $service = new SignupService($this->pdo, $this->config());
        $service->createPending(
            new NormalizedIdentity('google', 'g-1', 'dup@example.com', true, 'Dup'),
            'Dup',
            'First request',
            'google',
        );

        $this->expectException(ProviderException::class);
        $this->expectExceptionMessage('already exists');
        $service->createPending(
            new NormalizedIdentity('github', 'gh-1', 'dup@example.com', true, 'Dup'),
            'Dup',
            'Second request',
            'github',
        );
    }

    public function testRejectsDisallowedDomainOutsideDev(): void
    {
        $service = new SignupService($this->pdo, $this->config([
            'app_env' => 'prod',
            'allowed_email_domains' => ['allowed.com'],
        ]));

        $this->expectException(ProviderException::class);
        $service->createPending(
            new NormalizedIdentity('google', 'g-1', 'someone@notallowed.com', true, 'Someone'),
            'Someone',
            'Please let me in',
            'google',
        );
    }

    public function testAssertEmailAllowedPassesForAllowedDomain(): void
    {
        $service = new SignupService($this->pdo, $this->config([
            'app_env' => 'prod',
            'allowed_email_domains' => ['allowed.com'],
        ]));

        $service->assertEmailAllowed('someone@allowed.com');
        $this->expectNotToPerformAssertions();
    }

    public function testRejectsEmptyJustification(): void
    {
        $service = new SignupService($this->pdo, $this->config());

        $this->expectException(ProviderException::class);
        $service->createPending(
            new NormalizedIdentity('google', 'g-1', 'noreason@example.com', true, 'No Reason'),
            'No Reason',
            '   ',
            'google',
        );
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

- [ ] **Step 2: Run to verify it fails**

Run: `php vendor/bin/phpunit tests/Integration/SignupServiceTest.php`
Expected: FAIL — class `SignupService` doesn't exist.

- [ ] **Step 3: Implement `SignupService`**

`app/Infrastructure/Provisioning/SignupService.php`:
```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Provisioning;

use GrandpaSSOn\Domain\User;
use GrandpaSSOn\Domain\Uuid;
use GrandpaSSOn\Infrastructure\Mail\MailerFactory;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use PDO;

/**
 * Creates the pending-approval user row for self-enrollment. Distinct from
 * UserProvisioner::resolve(), which only ever looks up *existing* users.
 */
final class SignupService
{
    /**
     * @param array{app_env: string, allowed_email_domains: list<string>, mail: array<string, mixed>, broker: array{name: string, base_url: string}, admin_notification_emails: list<string>} $config
     */
    public function __construct(
        private readonly PDO $pdo,
        private readonly array $config,
    ) {
    }

    public function createPending(
        NormalizedIdentity $identity,
        string $displayName,
        string $justification,
        string $source,
    ): User {
        if ($identity->email === null || $identity->email === '' || !$identity->emailVerified) {
            throw new ProviderException('Verified email is required to sign up');
        }

        $email = strtolower($identity->email);
        $displayName = trim($displayName);
        $justification = trim($justification);
        if ($displayName === '') {
            throw new ProviderException('Name is required');
        }
        if ($justification === '') {
            throw new ProviderException('Justification is required');
        }

        if ($this->findByEmail($email) !== null) {
            throw new ProviderException('An account with this email already exists.');
        }

        $this->assertEmailAllowed($email);

        $userId = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');

        $this->pdo->beginTransaction();
        try {
            $this->pdo->prepare(
                'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
                 VALUES (:id, :email, 1, :name, :avatar, \'pending\', :created, :updated)'
            )->execute([
                'id' => $userId,
                'email' => $email,
                'name' => $displayName,
                'avatar' => $identity->avatarUrl,
                'created' => $now,
                'updated' => $now,
            ]);

            LinkedIdentityWriter::insert($this->pdo, $userId, $identity);

            $this->pdo->prepare(
                'INSERT INTO signup_requests (id, user_id, email, display_name, justification, source, status, created_at, updated_at)
                 VALUES (:id, :user_id, :email, :name, :justification, :source, \'pending\', :created, :updated)'
            )->execute([
                'id' => Uuid::v4(),
                'user_id' => $userId,
                'email' => $email,
                'name' => $displayName,
                'justification' => $justification,
                'source' => $source,
                'created' => $now,
                'updated' => $now,
            ]);

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $this->notifyAdmins($email, $displayName, $justification, $source);

        return new User($userId, $email, true, $displayName, $identity->avatarUrl, 'pending');
    }

    public function assertEmailAllowed(string $email): void
    {
        $domains = $this->config['allowed_email_domains'];
        $env = $this->config['app_env'];

        if ($domains === []) {
            if ($env === 'dev' || $env === 'local') {
                return;
            }
            throw new ProviderException('Signup refused: ALLOWED_EMAIL_DOMAINS is empty outside APP_ENV=dev');
        }

        $host = substr(strrchr($email, '@') ?: '', 1);
        if ($host === '' || !in_array(strtolower($host), $domains, true)) {
            throw new ProviderException('Email domain is not allowed for signup');
        }
    }

    private function findByEmail(string $email): ?User
    {
        $stmt = $this->pdo->prepare('SELECT * FROM users WHERE primary_email = :email LIMIT 1');
        $stmt->execute(['email' => $email]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            return null;
        }

        return new User(
            (string) $row['id'],
            (string) $row['primary_email'],
            (bool) $row['email_verified'],
            (string) $row['display_name'],
            $row['avatar_url'] !== null ? (string) $row['avatar_url'] : null,
            (string) $row['status'],
        );
    }

    private function notifyAdmins(string $email, string $displayName, string $justification, string $source): void
    {
        $recipients = $this->config['admin_notification_emails'];
        if ($recipients === []) {
            return;
        }
        $brokerName = (string) ($this->config['broker']['name'] ?? 'GrandpaSSOn');
        $adminUrl = rtrim((string) ($this->config['broker']['base_url'] ?? ''), '/') . '/admin';
        $body = "New {$brokerName} signup awaiting approval:\n\n"
            . "Name: {$displayName}\n"
            . "Email: {$email}\n"
            . "Source: {$source}\n"
            . "Justification: {$justification}\n\n"
            . "Review: {$adminUrl}\n";

        try {
            $mailer = (new MailerFactory($this->config['mail']))->make();
            foreach ($recipients as $recipient) {
                $mailer->send($recipient, "{$brokerName}: new signup awaiting approval", $body);
            }
        } catch (\Throwable $e) {
            error_log('signup admin notification mail failed: ' . $e->getMessage());
        }
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php vendor/bin/phpunit tests/Integration/SignupServiceTest.php`
Expected: PASS (all 6 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Infrastructure/Provisioning/SignupService.php tests/Integration/SignupServiceTest.php
git commit -m "feat: add SignupService to create pending accounts + signup_requests audit row"
```

---

## Task 5: `SignupController` — email OTP entry point

**Files:**
- Create: `app/Http/Controllers/SignupController.php` (this task implements `form`, `start`, `verifyForm`, `verify` only — `complete`/`completeSubmit` land in Task 6)
- Modify: `app/Http/AppRoutes.php` (add 4 routes + import)
- Test: `tests/Integration/SignupControllerTest.php`

**Interfaces:**
- Consumes: `RpRequestValidator::validate()`, `EmailOtpService`, `SignupService` (Task 4), `RateLimitGate::allowEmailOtpStart/Verify`, `Csrf`, `Html`, `Http`.
- Produces: routes `GET /signup`, `POST /signup/start`, `GET /signup/verify`, `POST /signup/verify`. Session keys `$_SESSION['signup_otp_id']`, `$_SESSION['signup_profile']` (`['name' => string, 'justification' => string]`).

- [ ] **Step 1: Write the failing integration test**

`tests/Integration/EmailOtpLoginControllerTest.php` does **not** use an HTTP server or cookie jar — it directly instantiates the controller, sets `$_GET`/`$_POST`/`$_SESSION` superglobals, calls the action method, and captures output via `ob_start()`/`ob_get_clean()` plus `http_response_code()`. `SignupController` follows the exact same shape (same `RpRequestValidator`, same `EmailOtpService`), so `SignupControllerTest` uses the identical pattern:

```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Http\Controllers\SignupController;
use GrandpaSSOn\Infrastructure\Auth\EmailOtpService;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class SignupControllerTest extends TestCase
{
    private ?PDO $pdo = null;
    private string $dbName;
    /** @var array<string, mixed> */
    private array $config;

    protected function setUp(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $_SERVER['REMOTE_ADDR'] = '203.0.113.' . random_int(1, 254);
        $_GET = [];
        $_POST = [];
        if (session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }
        $_SESSION = [];

        $this->dbName = 'gp_signup_ctrl_' . substr(bin2hex(random_bytes(4)), 0, 8);
        try {
            $root = $this->rootPdo();
            $root->exec('CREATE DATABASE `' . $this->dbName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $root->exec('USE `' . $this->dbName . '`');
            foreach (glob(dirname(__DIR__, 2) . '/app/Infrastructure/Db/Migrations/*.sql') ?: [] as $file) {
                $root->exec((string) file_get_contents($file));
            }
            $uris = $root->quote(json_encode(['https://app.example/cb'], JSON_THROW_ON_ERROR));
            $root->exec(
                "INSERT INTO oauth_clients (client_id, client_secret_hash, name, redirect_uris, type, enabled)
                 VALUES ('cid', NULL, 'App', {$uris}, 'public', 1)"
            );
            $this->pdo = $root;
            Connection::reset();
        } catch (\Throwable $e) {
            $this->markTestSkipped('MySQL not available: ' . $e->getMessage());
        }

        $this->config = [
            'app_env' => 'dev',
            'broker' => ['name' => 'GrandpaSSOn', 'base_url' => 'http://localhost:8080'],
            'db' => [
                'host' => getenv('TEST_DB_HOST') ?: '127.0.0.1',
                'port' => (int) (getenv('TEST_DB_PORT') ?: '3306'),
                'name' => $this->dbName,
                'user' => getenv('TEST_DB_USER') ?: 'root',
                'password' => getenv('TEST_DB_PASS') !== false && getenv('TEST_DB_PASS') !== ''
                    ? (string) getenv('TEST_DB_PASS')
                    : 'devrootpass',
            ],
            'allowed_email_domains' => [],
            'admin_notification_emails' => [],
            'rate_limit' => [
                'email_otp_start_max' => 5,
                'email_otp_start_window_seconds' => 900,
                'email_otp_verify_max' => 10,
                'email_otp_verify_window_seconds' => 900,
            ],
            'mail' => [
                'transport' => 'sendmail',
                'from_address' => 'noreply@example.com',
                'from_name' => 'GrandpaSSOn',
                'smtp_host' => '',
                'smtp_port' => 587,
                'smtp_username' => '',
                'smtp_password' => '',
                'smtp_encryption' => 'tls',
            ],
            'email_otp' => ['ttl_seconds' => 600, 'code_length' => 6, 'max_attempts' => 5],
        ];
    }

    protected function tearDown(): void
    {
        RateLimitGate::reset();
        Connection::reset();
        $_GET = [];
        $_POST = [];
        $_SESSION = [];
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }
        if ($this->pdo instanceof PDO) {
            try {
                $this->pdo->exec('DROP DATABASE IF EXISTS `' . $this->dbName . '`');
            } catch (\Throwable) {
            }
        }
    }

    public function testFullEmailSignupFlowCreatesPendingUser(): void
    {
        $_GET = ['client_id' => 'cid', 'redirect_uri' => 'https://app.example/cb', 'state' => 's'];
        ob_start();
        (new SignupController())->form($this->config, []);
        $formBody = (string) ob_get_clean();
        $this->assertStringContainsString('name="justification"', $formBody);

        $csrf = Csrf::token();
        $_POST = [
            'csrf' => $csrf,
            'client_id' => 'cid',
            'redirect_uri' => 'https://app.example/cb',
            'state' => 's',
            'name' => 'Alice Newperson',
            'email' => 'alice.new@example.com',
            'justification' => 'I need to review shared reports.',
        ];
        ob_start();
        (new SignupController())->start($this->config, []);
        $startBody = (string) ob_get_clean();
        $this->assertStringContainsString('Check your email', $startBody);
        $this->assertArrayHasKey('signup_otp_id', $_SESSION);

        $started = (new EmailOtpService($this->pdo))->start(
            'alice.new@example.com',
            [
                'client_id' => 'cid',
                'redirect_uri' => 'https://app.example/cb',
                'client_state' => 's',
                'return_to' => null,
                'code_challenge' => null,
                'code_challenge_method' => null,
            ],
            600,
            5,
            6,
        );
        $_SESSION['signup_otp_id'] = $started['id'];
        $_SESSION['signup_profile'] = ['name' => 'Alice Newperson', 'justification' => 'I need to review shared reports.'];

        $csrf2 = Csrf::token();
        $_POST = ['csrf' => $csrf2, 'code' => $started['code']];
        ob_start();
        (new SignupController())->verify($this->config, []);
        $verifyBody = (string) ob_get_clean();

        $this->assertStringContainsString('Request received', $verifyBody);
        $this->assertArrayNotHasKey('signup_otp_id', $_SESSION);

        $user = $this->pdo->query(
            "SELECT id, status FROM users WHERE primary_email = 'alice.new@example.com'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($user);
        $this->assertSame('pending', $user['status']);

        $request = $this->pdo->query(
            'SELECT status, justification, source FROM signup_requests WHERE user_id = ' . $this->pdo->quote($user['id'])
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('pending', $request['status']);
        $this->assertSame('I need to review shared reports.', $request['justification']);
        $this->assertSame('email', $request['source']);
    }

    public function testSignupStartRejectsDisallowedDomain(): void
    {
        $this->config['app_env'] = 'prod';
        $this->config['allowed_email_domains'] = ['allowed.com'];

        $csrf = Csrf::token();
        $_POST = [
            'csrf' => $csrf,
            'client_id' => 'cid',
            'redirect_uri' => 'https://app.example/cb',
            'state' => 's',
            'name' => 'Bob Outsider',
            'email' => 'bob@notallowed.com',
            'justification' => 'Let me in please',
        ];
        ob_start();
        (new SignupController())->start($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('not allowed', $body);
        $this->assertArrayNotHasKey('signup_otp_id', $_SESSION);
        $count = (int) $this->pdo->query('SELECT COUNT(*) FROM email_otp_codes')->fetchColumn();
        $this->assertSame(0, $count);
    }

    public function testDuplicateEmailIsRejectedAtVerify(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
             VALUES (:id, :email, 1, :name, NULL, \'active\', :now, :now)'
        )->execute(['id' => \GrandpaSSOn\Domain\Uuid::v4(), 'email' => 'dup@example.com', 'name' => 'Existing', 'now' => $now]);

        $started = (new EmailOtpService($this->pdo))->start(
            'dup@example.com',
            [
                'client_id' => 'cid',
                'redirect_uri' => 'https://app.example/cb',
                'client_state' => 's',
                'return_to' => null,
                'code_challenge' => null,
                'code_challenge_method' => null,
            ],
            600,
            5,
            6,
        );
        $_SESSION['signup_otp_id'] = $started['id'];
        $_SESSION['signup_profile'] = ['name' => 'Dup Person', 'justification' => 'Reason'];

        $csrf = Csrf::token();
        $_POST = ['csrf' => $csrf, 'code' => $started['code']];
        ob_start();
        (new SignupController())->verify($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('already exists', $body);
        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM users WHERE primary_email = 'dup@example.com'"
        )->fetchColumn();
        $this->assertSame(1, $count);
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

- [ ] **Step 2: Run to verify it fails**

Run: `php vendor/bin/phpunit tests/Integration/SignupControllerTest.php`
Expected: FAIL — route `/signup` doesn't exist (404) / class `SignupController` doesn't exist.

- [ ] **Step 3: Implement `SignupController` (email path only)**

`app/Http/Controllers/SignupController.php`:
```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Http\Controllers;

use GrandpaSSOn\Infrastructure\Auth\EmailOtpService;
use GrandpaSSOn\Infrastructure\Auth\EmailOtpVerifyResult;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Infrastructure\Mail\MailerFactory;
use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;
use GrandpaSSOn\Infrastructure\Providers\ProviderException;
use GrandpaSSOn\Infrastructure\Provisioning\SignupService;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\Html;
use GrandpaSSOn\Support\Http;
use GrandpaSSOn\Support\RateLimitGate;
use GrandpaSSOn\Support\RpRequestValidator;

/**
 * Self-enrollment (design doc: docs/superpowers/specs/2026-08-06-self-enrollment-admin-approval-design.md).
 * Email OTP entry point lives here (form/start/verifyForm/verify); the
 * OAuth-completion entry point (complete/completeSubmit) is added in a
 * follow-up task, reached from CallbackController when
 * UserProvisioner::resolve() throws AccountNotFoundException.
 */
final class SignupController
{
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function form(array $config, array $params = []): void
    {
        $pdo = Connection::get($config['db']);
        $validated = RpRequestValidator::validate($pdo, $_GET);
        if (!$validated->ok) {
            $this->fail($config, $validated->status, (string) $validated->message);

            return;
        }

        $this->renderForm(
            $config,
            (string) $validated->clientId,
            (string) $validated->redirectUri,
            (string) $validated->clientState,
            $validated->returnTo,
            $validated->codeChallenge,
            $validated->codeChallengeMethod,
        );
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function start(array $config, array $params = []): void
    {
        $pdo = Connection::get($config['db']);
        if (!RateLimitGate::allowEmailOtpStart($pdo, 'signup_email_start', $config)) {
            $this->fail($config, 429, 'Too many attempts. Please wait a bit and try again.');

            return;
        }
        if (!Csrf::validate((string) ($_POST['csrf'] ?? ''))) {
            $this->fail($config, 400, 'Your session expired. Please start again.');

            return;
        }

        $validated = RpRequestValidator::validate($pdo, $_POST);
        if (!$validated->ok) {
            $this->fail($config, $validated->status, (string) $validated->message);

            return;
        }

        $email = trim((string) ($_POST['email'] ?? ''));
        $name = trim((string) ($_POST['name'] ?? ''));
        $justification = trim((string) ($_POST['justification'] ?? ''));

        $error = null;
        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $error = 'Enter a valid email address.';
        } elseif ($name === '') {
            $error = 'Enter your name.';
        } elseif ($justification === '') {
            $error = 'Tell us why you need access.';
        }

        if ($error === null) {
            try {
                $this->signupService($pdo, $config)->assertEmailAllowed(strtolower($email));
            } catch (ProviderException $e) {
                $error = $e->getMessage();
            }
        }

        if ($error !== null) {
            $this->renderForm(
                $config,
                (string) $validated->clientId,
                (string) $validated->redirectUri,
                (string) $validated->clientState,
                $validated->returnTo,
                $validated->codeChallenge,
                $validated->codeChallengeMethod,
                $error,
            );

            return;
        }

        $emailOtp = $config['email_otp'];
        $started = (new EmailOtpService($pdo))->start(
            $email,
            [
                'client_id' => (string) $validated->clientId,
                'redirect_uri' => (string) $validated->redirectUri,
                'client_state' => (string) $validated->clientState,
                'return_to' => $validated->returnTo,
                'code_challenge' => $validated->codeChallenge,
                'code_challenge_method' => $validated->codeChallengeMethod,
            ],
            (int) $emailOtp['ttl_seconds'],
            (int) $emailOtp['max_attempts'],
            (int) $emailOtp['code_length'],
        );

        if ($started['code'] !== null) {
            $brokerName = (string) ($config['broker']['name'] ?? 'GrandpaSSOn');
            $minutes = (int) ceil(((int) $emailOtp['ttl_seconds']) / 60);
            (new MailerFactory($config['mail']))->make()->send(
                $email,
                $brokerName . ' signup code',
                "Your {$brokerName} signup verification code is {$started['code']}.\n\n"
                . "This code expires in {$minutes} minute(s).\n"
                . "If you didn't request this, you can safely ignore this email.\n",
            );
        }

        $_SESSION['signup_otp_id'] = $started['id'];
        $_SESSION['signup_profile'] = ['name' => $name, 'justification' => $justification];
        $this->renderCheckEmail($config);
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function verifyForm(array $config, array $params = []): void
    {
        $id = $_SESSION['signup_otp_id'] ?? null;
        if (!is_string($id) || $id === '') {
            Http::redirect(Html::basePath($config) . '/signup');

            return;
        }

        $this->renderCodeForm($config);
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function verify(array $config, array $params = []): void
    {
        $pdo = Connection::get($config['db']);
        $id = $_SESSION['signup_otp_id'] ?? null;
        $profile = $_SESSION['signup_profile'] ?? null;
        if (!is_string($id) || $id === '' || !is_array($profile)) {
            Http::redirect(Html::basePath($config) . '/signup');

            return;
        }

        if (!RateLimitGate::allowEmailOtpVerify($pdo, 'signup_email_verify', $config)) {
            $this->fail($config, 429, 'Too many attempts. Please wait a bit and try again.');

            return;
        }
        if (!Csrf::validate((string) ($_POST['csrf'] ?? ''))) {
            $this->fail($config, 400, 'Your session expired. Please start again.');

            return;
        }

        $submittedCode = trim((string) ($_POST['code'] ?? ''));
        $result = (new EmailOtpService($pdo))->verify($id, $submittedCode);

        if ($result->status === EmailOtpVerifyResult::WRONG_CODE) {
            $this->renderCodeForm($config, "Incorrect code. {$result->attemptsRemaining} attempt(s) remaining.");

            return;
        }

        if ($result->status !== EmailOtpVerifyResult::OK) {
            unset($_SESSION['signup_otp_id'], $_SESSION['signup_profile']);
            $this->fail($config, 400, 'That code is no longer valid. Please start again.');

            return;
        }

        try {
            $identity = new NormalizedIdentity(
                provider: 'email_otp',
                subject: (string) $result->email,
                email: (string) $result->email,
                emailVerified: true,
                name: (string) $profile['name'],
            );
            $this->signupService($pdo, $config)->createPending(
                $identity,
                (string) $profile['name'],
                (string) $profile['justification'],
                'email',
            );

            unset($_SESSION['signup_otp_id'], $_SESSION['signup_profile']);
            $this->renderPending($config);
        } catch (ProviderException $e) {
            unset($_SESSION['signup_otp_id'], $_SESSION['signup_profile']);
            $this->fail($config, 400, $e->getMessage());
        }
    }

    /** @param array<string, mixed> $config */
    private function signupService(\PDO $pdo, array $config): SignupService
    {
        return new SignupService($pdo, [
            'app_env' => (string) $config['app_env'],
            'allowed_email_domains' => $config['allowed_email_domains'] ?? [],
            'mail' => $config['mail'],
            'broker' => $config['broker'],
            'admin_notification_emails' => $config['admin_notification_emails'] ?? [],
        ]);
    }

    /** @param array<string, mixed> $config */
    private function renderForm(
        array $config,
        string $clientId,
        string $redirectUri,
        string $clientState,
        ?string $returnTo,
        ?string $codeChallenge,
        ?string $codeChallengeMethod,
        ?string $error = null,
    ): void {
        header('Content-Type: text/html; charset=utf-8');
        $name = (string) ($config['broker']['name'] ?? 'GrandpaSSOn');
        $action = Html::e(Html::basePath($config) . '/signup/start');

        echo Html::pageStart($config, $name . ' - Request access');
        echo '<div class="prose">';
        echo '<h1>Request access</h1>';
        echo '<p class="lead">Tell us who you are — an admin will review your request.</p>';
        if ($error !== null) {
            echo '<p class="text-small">' . Html::e($error) . '</p>';
        }
        echo '<form method="post" action="' . $action . '">';
        echo '<input type="hidden" name="csrf" value="' . Html::e(Csrf::token()) . '">';
        echo '<input type="hidden" name="client_id" value="' . Html::e($clientId) . '">';
        echo '<input type="hidden" name="redirect_uri" value="' . Html::e($redirectUri) . '">';
        echo '<input type="hidden" name="state" value="' . Html::e($clientState) . '">';
        if ($returnTo !== null) {
            echo '<input type="hidden" name="return_to" value="' . Html::e($returnTo) . '">';
        }
        if ($codeChallenge !== null) {
            echo '<input type="hidden" name="code_challenge" value="' . Html::e($codeChallenge) . '">';
        }
        if ($codeChallengeMethod !== null) {
            echo '<input type="hidden" name="code_challenge_method" value="' . Html::e($codeChallengeMethod) . '">';
        }
        echo '<label for="name">Your name</label>';
        echo '<input type="text" id="name" name="name" required autofocus autocomplete="name">';
        echo '<label for="email">Email address</label>';
        echo '<input type="email" id="email" name="email" required autocomplete="email">';
        echo '<label for="justification">Why do you need access?</label>';
        echo '<textarea id="justification" name="justification" rows="3" required></textarea>';
        echo '<p><button type="submit" class="btn btn--primary">Request access</button></p>';
        echo '</form>';
        echo '</div>';
        echo Html::pageEnd();
    }

    /** @param array<string, mixed> $config */
    private function renderCheckEmail(array $config): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $name = (string) ($config['broker']['name'] ?? 'GrandpaSSOn');
        $verifyHref = Html::e(Html::basePath($config) . '/signup/verify');

        echo Html::pageStart($config, $name . ' - Check your email');
        echo '<div class="prose">';
        echo '<div class="card">';
        echo '<h1>Check your email</h1>';
        echo '<p>If that address is eligible, we sent it a verification code.</p>';
        echo '<p><a class="btn btn--primary" href="' . $verifyHref . '">Enter code</a></p>';
        echo '</div>';
        echo '</div>';
        echo Html::pageEnd();
    }

    /** @param array<string, mixed> $config */
    private function renderCodeForm(array $config, ?string $error = null): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $name = (string) ($config['broker']['name'] ?? 'GrandpaSSOn');
        $action = Html::e(Html::basePath($config) . '/signup/verify');
        $restartHref = Html::e(Html::basePath($config) . '/signup');

        echo Html::pageStart($config, $name . ' - Enter your code');
        echo '<div class="prose">';
        echo '<h1>Enter your code</h1>';
        echo '<p class="lead">Enter the verification code we emailed you.</p>';
        if ($error !== null) {
            echo '<p class="text-small">' . Html::e($error) . '</p>';
        }
        echo '<form method="post" action="' . $action . '">';
        echo '<input type="hidden" name="csrf" value="' . Html::e(Csrf::token()) . '">';
        echo '<label for="code">Verification code</label>';
        echo '<input type="text" id="code" name="code" required autofocus inputmode="numeric" autocomplete="one-time-code">';
        echo '<p><button type="submit" class="btn btn--primary">Verify</button></p>';
        echo '</form>';
        echo '<p class="text-small"><a href="' . $restartHref . '">Start over</a></p>';
        echo '</div>';
        echo Html::pageEnd();
    }

    /** @param array<string, mixed> $config */
    private function renderPending(array $config): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $name = (string) ($config['broker']['name'] ?? 'GrandpaSSOn');

        echo Html::pageStart($config, $name . ' - Awaiting approval');
        echo '<div class="prose">';
        echo '<div class="card">';
        echo '<h1>Request received</h1>';
        echo '<p>An admin will review your request. You will be able to sign in once approved.</p>';
        echo '</div>';
        echo '</div>';
        echo Html::pageEnd();
    }

    /** @param array<string, mixed> $config */
    private function fail(array $config, int $status, string $message): void
    {
        http_response_code($status);
        header('Content-Type: text/html; charset=utf-8');
        echo Html::pageStart($config, 'Request failed');
        echo '<div class="prose">';
        echo '<h1>Request failed</h1>';
        echo '<p>' . Html::e($message) . '</p>';
        $href = Html::e(Html::basePath($config) . '/signup');
        echo '<p><a class="btn btn--primary" href="' . $href . '">Try again</a></p>';
        echo '</div>';
        echo Html::pageEnd();
    }
}
```

Note: `complete()` and `completeSubmit()` (OAuth path) are added to this same class in Task 6 — don't add stub methods for them here.

- [ ] **Step 4: Wire the routes**

In `app/Http/AppRoutes.php`, add the import:
```php
use GrandpaSSOn\Http\Controllers\SignupController;
```
and, in `definitions()`, add these four rows (placed near the `/login/email*` group for readability):
```php
            ['GET', '/signup', SignupController::class, 'form'],
            ['POST', '/signup/start', SignupController::class, 'start'],
            ['GET', '/signup/verify', SignupController::class, 'verifyForm'],
            ['POST', '/signup/verify', SignupController::class, 'verify'],
```

- [ ] **Step 5: Run to verify it passes**

Run: `php vendor/bin/phpunit tests/Integration/SignupControllerTest.php`
Expected: PASS.

- [ ] **Step 6: Run the full suite**

Run: `php vendor/bin/phpunit`
Expected: only the previously-noted `EmailOtpLoginControllerTest`/`CallbackController`-related failures from Task 2 remain (fixed in Task 6/7); nothing new breaks.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/SignupController.php app/Http/AppRoutes.php tests/Integration/SignupControllerTest.php
git commit -m "feat: add email-OTP self-enrollment signup flow"
```

---

## Task 6: OAuth signup-completion path + `CallbackController` routing

**Files:**
- Modify: `app/Http/Controllers/SignupController.php` (add `complete`, `completeSubmit`, `renderComplete`)
- Modify: `app/Http/Controllers/CallbackController.php` (catch `AccountNotFoundException`, redirect)
- Modify: `app/Http/AppRoutes.php` (add 2 more routes)
- Test: `tests/Integration/SignupOAuthCompletionTest.php`

**Interfaces:**
- Consumes: `$_SESSION['pending_signup']` (set by `CallbackController`): `['provider' => string, 'subject' => string, 'email' => string, 'name' => ?string, 'avatar_url' => ?string, 'username' => ?string, 'raw_claims' => array]`.
- Produces: routes `GET /signup/complete`, `POST /signup/complete`.

**Test scope note:** `CallbackController::handle()` calls `ProviderFactory::make($provider)->handleCallback(...)`, which makes real HTTPS calls to Google/Microsoft/GitHub token endpoints (confirmed: `grep -rl "CallbackController" tests/` finds nothing — there is no existing fake-IdP test harness in this codebase, and none of the existing `tests/Unit/Providers/*Test.php` files exercise `CallbackController` either, only the provider classes' URL-building/token-parsing in isolation). Building that harness from scratch is out of scope for this feature. So: automate everything reachable without a live IdP call (`SignupController::complete`/`completeSubmit`, which only need `$_SESSION['pending_signup']` to be set — exactly how `CallbackController` sets it), and cover the `CallbackController` redirect wiring itself with a manual QA check in Step 6 below, same as the codebase's existing (pre-this-feature) gap for that controller's success path.

- [ ] **Step 1: Write the failing test**

`tests/Integration/SignupOAuthCompletionTest.php`, using the same in-process controller pattern as `SignupControllerTest` (Task 5) — copy that file's `setUp()`/`tearDown()`/`rootPdo()` verbatim, then:
```php
    public function testCompleteRendersPrefilledFormFromPendingSession(): void
    {
        $_SESSION['pending_signup'] = [
            'provider' => 'google',
            'subject' => 'g-sub-1',
            'email' => 'oauth.newuser@example.com',
            'name' => 'OAuth New User',
            'avatar_url' => null,
            'username' => null,
            'raw_claims' => ['sub' => 'g-sub-1'],
        ];

        ob_start();
        (new \GrandpaSSOn\Http\Controllers\SignupController())->complete($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('value="OAuth New User"', $body);
        $this->assertStringContainsString('value="oauth.newuser@example.com"', $body);
    }

    public function testCompleteWithoutPendingSessionRedirectsToLogin(): void
    {
        ob_start();
        (new \GrandpaSSOn\Http\Controllers\SignupController())->complete($this->config, []);
        ob_get_clean();

        $this->assertSame(302, http_response_code());
        http_response_code(200);
    }

    public function testCompleteSubmitCreatesPendingUserAndClearsSession(): void
    {
        $_SESSION['pending_signup'] = [
            'provider' => 'google',
            'subject' => 'g-sub-2',
            'email' => 'oauth.complete@example.com',
            'name' => 'OAuth Complete',
            'avatar_url' => 'https://example.com/a.png',
            'username' => null,
            'raw_claims' => ['sub' => 'g-sub-2'],
        ];
        $_SESSION['oauth'] = ['provider' => 'google', 'client_id' => 'cid', 'redirect_uri' => 'https://app.example/cb', 'client_state' => 's'];

        $csrf = Csrf::token();
        $_POST = ['csrf' => $csrf, 'name' => 'OAuth Complete', 'justification' => 'I need dashboard access.'];
        ob_start();
        (new \GrandpaSSOn\Http\Controllers\SignupController())->completeSubmit($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('Request received', $body);
        $this->assertArrayNotHasKey('pending_signup', $_SESSION);
        $this->assertArrayNotHasKey('oauth', $_SESSION);

        $user = $this->pdo->query(
            "SELECT id, status FROM users WHERE primary_email = 'oauth.complete@example.com'"
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($user);
        $this->assertSame('pending', $user['status']);

        $request = $this->pdo->query(
            'SELECT source FROM signup_requests WHERE user_id = ' . $this->pdo->quote($user['id'])
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('google', $request['source']);

        $linked = $this->pdo->query(
            "SELECT provider, provider_subject FROM linked_identities WHERE user_id = " . $this->pdo->quote($user['id'])
        )->fetch(PDO::FETCH_ASSOC);
        $this->assertSame('google', $linked['provider']);
        $this->assertSame('g-sub-2', $linked['provider_subject']);
    }

    public function testCompleteSubmitRejectsEmptyJustification(): void
    {
        $_SESSION['pending_signup'] = [
            'provider' => 'github',
            'subject' => 'gh-sub-1',
            'email' => 'oauth.nojustify@example.com',
            'name' => 'No Justify',
            'avatar_url' => null,
            'username' => 'nojustify',
            'raw_claims' => [],
        ];

        $csrf = Csrf::token();
        $_POST = ['csrf' => $csrf, 'name' => 'No Justify', 'justification' => '  '];
        ob_start();
        (new \GrandpaSSOn\Http\Controllers\SignupController())->completeSubmit($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('required', $body);
        $count = (int) $this->pdo->query(
            "SELECT COUNT(*) FROM users WHERE primary_email = 'oauth.nojustify@example.com'"
        )->fetchColumn();
        $this->assertSame(0, $count);
    }
```
Add these four methods into a new class `SignupOAuthCompletionTest extends TestCase` in `tests/Integration/SignupOAuthCompletionTest.php`, with the same `setUp()`/`tearDown()`/`rootPdo()`/`$config` bodies as `SignupControllerTest` from Task 5 (copy verbatim — both files test the same controller from different entry points, matching the codebase's existing convention of one `TestCase` per behavior group rather than a shared abstract base).

- [ ] **Step 2: Run to verify it fails**

Run: `php vendor/bin/phpunit tests/Integration/SignupOAuthCompletionTest.php`
Expected: FAIL — `SignupController::complete()`/`completeSubmit()` don't exist yet.

- [ ] **Step 3: Add `complete`/`completeSubmit` to `SignupController`**

Add these three methods to `app/Http/Controllers/SignupController.php` (alongside the existing ones from Task 5):
```php
    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function complete(array $config, array $params = []): void
    {
        $pending = $_SESSION['pending_signup'] ?? null;
        if (!is_array($pending)) {
            Http::redirect(Html::basePath($config) . '/login');

            return;
        }

        $this->renderComplete($config, (string) ($pending['name'] ?? ''), (string) $pending['email']);
    }

    /** @param array<string, mixed> $config @param array<string, string> $params */
    public function completeSubmit(array $config, array $params = []): void
    {
        $pdo = Connection::get($config['db']);
        $pending = $_SESSION['pending_signup'] ?? null;
        if (!is_array($pending)) {
            Http::redirect(Html::basePath($config) . '/login');

            return;
        }

        if (!Csrf::validate((string) ($_POST['csrf'] ?? ''))) {
            $this->fail($config, 400, 'Your session expired. Please start again.');

            return;
        }

        $name = trim((string) ($_POST['name'] ?? (string) ($pending['name'] ?? '')));
        $justification = trim((string) ($_POST['justification'] ?? ''));

        if ($name === '' || $justification === '') {
            $this->renderComplete($config, $name, (string) $pending['email'], 'Name and justification are required.');

            return;
        }

        try {
            $identity = new NormalizedIdentity(
                provider: (string) $pending['provider'],
                subject: (string) $pending['subject'],
                email: (string) $pending['email'],
                emailVerified: true,
                name: $name,
                avatarUrl: $pending['avatar_url'] ?? null,
                username: $pending['username'] ?? null,
                rawClaims: is_array($pending['raw_claims'] ?? null) ? $pending['raw_claims'] : [],
            );
            $this->signupService($pdo, $config)->createPending($identity, $name, $justification, (string) $pending['provider']);

            unset($_SESSION['pending_signup'], $_SESSION['oauth']);
            $this->renderPending($config);
        } catch (ProviderException $e) {
            $this->renderComplete($config, $name, (string) $pending['email'], $e->getMessage());
        }
    }

    /** @param array<string, mixed> $config */
    private function renderComplete(array $config, string $name, string $email, ?string $error = null): void
    {
        header('Content-Type: text/html; charset=utf-8');
        $brokerName = (string) ($config['broker']['name'] ?? 'GrandpaSSOn');
        $action = Html::e(Html::basePath($config) . '/signup/complete');

        echo Html::pageStart($config, $brokerName . ' - Request access');
        echo '<div class="prose">';
        echo '<h1>Request access</h1>';
        echo '<p class="lead">Your email is verified. Tell us why you need access — an admin will review your request.</p>';
        if ($error !== null) {
            echo '<p class="text-small">' . Html::e($error) . '</p>';
        }
        echo '<form method="post" action="' . $action . '">';
        echo '<input type="hidden" name="csrf" value="' . Html::e(Csrf::token()) . '">';
        echo '<label for="name">Your name</label>';
        echo '<input type="text" id="name" name="name" value="' . Html::e($name) . '" required>';
        echo '<label>Email address</label>';
        echo '<input type="email" value="' . Html::e($email) . '" readonly disabled>';
        echo '<label for="justification">Why do you need access?</label>';
        echo '<textarea id="justification" name="justification" rows="3" required></textarea>';
        echo '<p><button type="submit" class="btn btn--primary">Request access</button></p>';
        echo '</form>';
        echo '</div>';
        echo Html::pageEnd();
    }
```
And add these imports at the top if not already present from Task 5: `use GrandpaSSOn\Infrastructure\Providers\NormalizedIdentity;` (already there) — no new imports needed beyond what Task 5 added.

- [ ] **Step 4: Route the unknown-identity case in `CallbackController`**

In `app/Http/Controllers/CallbackController.php`, add the import:
```php
use GrandpaSSOn\Infrastructure\Providers\AccountNotFoundException;
```
Then, in `handle()`, insert a new catch block **before** the existing `catch (ProviderException $e)` block:
```php
        } catch (AccountNotFoundException $e) {
            $_SESSION['pending_signup'] = [
                'provider' => $identity->provider,
                'subject' => $identity->subject,
                'email' => $identity->email,
                'name' => $identity->name,
                'avatar_url' => $identity->avatarUrl,
                'username' => $identity->username,
                'raw_claims' => $identity->rawClaims,
            ];
            Http::redirect(Html::basePath($config) . '/signup/complete');
        } catch (ProviderException $e) {
```
(`$identity` is already in scope at this point in the existing `try` block — it's assigned by `$provider->handleCallback(...)` a few lines before `$provisioner->resolve($identity)` is called.)

- [ ] **Step 5: Wire the two new routes**

In `app/Http/AppRoutes.php`, add after the four routes from Task 5:
```php
            ['GET', '/signup/complete', SignupController::class, 'complete'],
            ['POST', '/signup/complete', SignupController::class, 'completeSubmit'],
```

- [ ] **Step 6: Run to verify it passes**

Run: `php vendor/bin/phpunit tests/Integration/SignupOAuthCompletionTest.php`
Expected: PASS.

- [ ] **Step 7: Manual QA for the `CallbackController` redirect wiring (no automated coverage possible without a live IdP or a fake-provider test harness — see the Test scope note in Step 1)**

With `make up` running and at least one provider's `*_CLIENT_ID`/`*_CLIENT_SECRET`/`*_REDIRECT_URI` set in `.env` to real OAuth app credentials for a throwaway/test IdP app:
1. Visit `/login/google?client_id=<a seeded oauth_client>&redirect_uri=<its redirect_uri>&state=test123` in a browser.
2. Complete the Google login with an account whose email has never been seen by this GrandpaSSOn instance.
3. Confirm you land on `/signup/complete` with your name and email pre-filled (email read-only).
4. Submit a justification. Confirm you see "Request received".
5. Query the DB: `SELECT status FROM users WHERE primary_email = '<that email>'` → `pending`.

Note the result of this manual check in the PR description — it's the substitute for automated coverage of `CallbackController`'s new catch block specifically (the logic it calls — `UserProvisioner::resolve()` throwing `AccountNotFoundException`, and `SignupController::complete()`/`completeSubmit()` — is fully covered by Task 2 and this task's automated tests; only the four-line glue in `CallbackController::handle()` itself is manual-only).

- [ ] **Step 8: Run the full suite**

Run: `php vendor/bin/phpunit`
Expected: no new failures. The one known failure from Task 2 (`testStartIssuesCodeAndVerifyCompletesRoundTripToRp`) is still outstanding — fixed in Task 7.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/SignupController.php app/Http/Controllers/CallbackController.php app/Http/AppRoutes.php tests/Integration/SignupOAuthCompletionTest.php
git commit -m "feat: add OAuth signup-completion screen, route unknown identities into it"
```

---

## Task 7: Pending/rejected login messaging (both entry points)

**Files:**
- Modify: `app/Http/Controllers/CallbackController.php`
- Modify: `app/Http/Controllers/EmailOtpLoginController.php`
- Modify: `tests/Integration/EmailOtpLoginControllerTest.php` (fix the one pre-existing test broken by Task 2, plus add the two new pending/rejected cases)

**Interfaces:**
- No new public interfaces — this task only makes existing catch blocks in the two login controllers show the correct message for `AccountPendingException` / `AccountRejectedException` instead of falling through to the generic "Sign-in failed" / "Login failed" message.

- [ ] **Step 1: Fix the test that Task 2 broke**

`testStartIssuesCodeAndVerifyCompletesRoundTripToRp` in `tests/Integration/EmailOtpLoginControllerTest.php` currently asserts that verifying an OTP for a brand-new email auto-creates a user and redirects with a `code` — that behavior is gone. Replace its final section (from `$_SESSION['email_otp_id'] = $started['id'];` through the end of the method) so it asserts the new behavior instead:
```php
    public function testStartIssuesCodeThenVerifyForUnknownEmailShowsNoAccountFound(): void
    {
        $csrf = Csrf::token();
        $_POST = [
            'csrf' => $csrf,
            'client_id' => 'cid',
            'redirect_uri' => 'https://app.example/cb',
            'state' => 'client-state',
            'code_challenge' => str_repeat('a', 43),
            'code_challenge_method' => 'S256',
            'email' => 'newuser@example.com',
        ];

        ob_start();
        (new EmailOtpLoginController())->start($this->config, []);
        $startBody = (string) ob_get_clean();

        $this->assertStringContainsString('Check your email', $startBody);
        $this->assertArrayHasKey('email_otp_id', $_SESSION);
        $id = $_SESSION['email_otp_id'];

        $row = $this->pdo->query('SELECT code_hash FROM email_otp_codes WHERE id = ' . $this->pdo->quote($id))
            ->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($row);
        $this->assertStringNotContainsString((string) $row['code_hash'], $startBody);

        $started = (new EmailOtpService($this->pdo))->start(
            'newuser@example.com',
            [
                'client_id' => 'cid',
                'redirect_uri' => 'https://app.example/cb',
                'client_state' => 'client-state',
                'return_to' => null,
                'code_challenge' => null,
                'code_challenge_method' => null,
            ],
            600,
            5,
            6,
        );
        $_SESSION['email_otp_id'] = $started['id'];

        $csrf2 = Csrf::token();
        $_POST = ['csrf' => $csrf2, 'code' => $started['code']];

        ob_start();
        (new EmailOtpLoginController())->verify($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertSame(400, http_response_code());
        http_response_code(200);
        $this->assertArrayNotHasKey('email_otp_id', $_SESSION);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
        $count = (int) $this->pdo->query("SELECT COUNT(*) FROM users WHERE primary_email = 'newuser@example.com'")->fetchColumn();
        $this->assertSame(0, $count);
        $this->assertStringContainsString('Sign-in failed', $body);
    }
```
(Renamed from `testStartIssuesCodeAndVerifyCompletesRoundTripToRp` to `testStartIssuesCodeThenVerifyForUnknownEmailShowsNoAccountFound` since the assertion is now the opposite of a "round trip to RP" — it never reaches the RP.) This makes the file green again on its own; the two new pending/rejected tests in Step 2 add further coverage of the new catch blocks.

- [ ] **Step 2: Write the new failing tests**

Add to `tests/Integration/EmailOtpLoginControllerTest.php`:
```php
    public function testLoginBlockedForPendingUser(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
             VALUES (:id, :email, 1, :name, NULL, \'pending\', :now, :now)'
        )->execute(['id' => \GrandpaSSOn\Domain\Uuid::v4(), 'email' => 'pending.user@example.com', 'name' => 'Pending User', 'now' => $now]);

        $started = (new EmailOtpService($this->pdo))->start(
            'pending.user@example.com',
            [
                'client_id' => 'cid',
                'redirect_uri' => 'https://app.example/cb',
                'client_state' => 'client-state',
                'return_to' => null,
                'code_challenge' => null,
                'code_challenge_method' => null,
            ],
            600,
            5,
            6,
        );
        $_SESSION['email_otp_id'] = $started['id'];

        $csrf = Csrf::token();
        $_POST = ['csrf' => $csrf, 'code' => $started['code']];
        ob_start();
        (new EmailOtpLoginController())->verify($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertSame(403, http_response_code());
        http_response_code(200);
        $this->assertStringContainsString('awaiting admin approval', $body);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testLoginBlockedForRejectedUser(): void
    {
        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare(
            'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
             VALUES (:id, :email, 1, :name, NULL, \'rejected\', :now, :now)'
        )->execute(['id' => \GrandpaSSOn\Domain\Uuid::v4(), 'email' => 'rejected.user@example.com', 'name' => 'Rejected User', 'now' => $now]);

        $started = (new EmailOtpService($this->pdo))->start(
            'rejected.user@example.com',
            [
                'client_id' => 'cid',
                'redirect_uri' => 'https://app.example/cb',
                'client_state' => 'client-state',
                'return_to' => null,
                'code_challenge' => null,
                'code_challenge_method' => null,
            ],
            600,
            5,
            6,
        );
        $_SESSION['email_otp_id'] = $started['id'];

        $csrf = Csrf::token();
        $_POST = ['csrf' => $csrf, 'code' => $started['code']];
        ob_start();
        (new EmailOtpLoginController())->verify($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertSame(403, http_response_code());
        http_response_code(200);
        $this->assertStringContainsString('not approved', $body);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }
```

- [ ] **Step 3: Run to verify they fail**

Run: `php vendor/bin/phpunit tests/Integration/EmailOtpLoginControllerTest.php`
Expected: `testStartIssuesCodeThenVerifyForUnknownEmailShowsNoAccountFound` PASSes already (Task 2's `resolve()` already throws `AccountNotFoundException`, which the existing generic `catch (ProviderException $e)` already turns into "Sign-in failed" — this test change is a correction, not new behavior). `testLoginBlockedForPendingUser` and `testLoginBlockedForRejectedUser` FAIL — both currently get the generic 400 "Sign-in failed", not 403 with the specific copy, because `AccountPendingException`/`AccountRejectedException` aren't caught separately yet.

- [ ] **Step 4: Update `EmailOtpLoginController::verify()`**

In `app/Http/Controllers/EmailOtpLoginController.php`, add imports:
```php
use GrandpaSSOn\Infrastructure\Providers\AccountPendingException;
use GrandpaSSOn\Infrastructure\Providers\AccountRejectedException;
```
Then, in the `try { ... } catch (ProviderException $e) { ... }` block inside `verify()`, insert two new catches **before** the existing `catch (ProviderException $e)`:
```php
        } catch (AccountPendingException $e) {
            unset($_SESSION['email_otp_id']);
            $audit->log('login.failure', null, 'email_otp', Http::clientIp());
            $this->fail($config, 403, 'Your account is awaiting admin approval.');
        } catch (AccountRejectedException $e) {
            unset($_SESSION['email_otp_id']);
            $audit->log('login.failure', null, 'email_otp', Http::clientIp());
            $this->fail($config, 403, 'Your signup was not approved.');
        } catch (ProviderException $e) {
```

- [ ] **Step 5: Update `CallbackController::handle()`**

In `app/Http/Controllers/CallbackController.php`, add imports:
```php
use GrandpaSSOn\Infrastructure\Providers\AccountPendingException;
use GrandpaSSOn\Infrastructure\Providers\AccountRejectedException;
```
Then, right after the `catch (AccountNotFoundException $e) { ... }` block added in Task 6 and **before** the existing `catch (ProviderException $e)`, insert:
```php
        } catch (AccountPendingException $e) {
            $audit->log('login.failure', null, $providerName, Http::clientIp());
            $this->fail($config, 403, 'account_pending', 'Your account is awaiting admin approval.');
        } catch (AccountRejectedException $e) {
            $audit->log('login.failure', null, $providerName, Http::clientIp());
            $this->fail($config, 403, 'account_rejected', 'Your signup was not approved.');
        } catch (ProviderException $e) {
```

- [ ] **Step 6: Run to verify tests pass**

Run: `php vendor/bin/phpunit tests/Integration/EmailOtpLoginControllerTest.php`
Expected: PASS (all tests, including the fixed one from Step 1).

- [ ] **Step 7: Manual QA for `CallbackController`'s pending/rejected messages**

Same constraint as Task 6 Step 7 — no automated harness exists for a live OAuth callback. With a seeded pending or rejected user linked to a real test IdP account (flip a user's `status` to `pending` directly in the DB after doing a real signup, or after Task 9 lands, use `user:reject`), attempt to log in with that identity via `/login/google` end-to-end in a browser. Confirm the callback shows "Your account is awaiting admin approval." (pending) or "Your signup was not approved." (rejected) instead of the generic "Login failed."

- [ ] **Step 8: Run the full suite — this is the point where everything should be green again**

Run: `php vendor/bin/phpunit`
Expected: PASS, no remaining failures from Task 2's rewrite.

- [ ] **Step 9: Commit**

```bash
git add app/Http/Controllers/EmailOtpLoginController.php app/Http/Controllers/CallbackController.php tests/Integration/EmailOtpLoginControllerTest.php
git commit -m "fix: show specific messages for pending/rejected accounts on both login paths"
```

---

## Task 8: Entry-point "sign up" links on the existing login pages

**Files:**
- Modify: `app/Http/Controllers/LoginController.php` (`chooser()`)
- Modify: `app/Http/Controllers/EmailOtpLoginController.php` (`renderEmailForm()`)
- Create: `tests/Integration/LoginControllerTest.php` (check first with `find tests -iname "*LoginController*"` — if it already exists, add the one test case to it instead)
- Modify: `tests/Integration/EmailOtpLoginControllerTest.php`

**Interfaces:**
- No new interfaces — purely additive HTML on two existing pages, both of which already have `client_id`/`redirect_uri`/`state`/etc. in scope (as `$_GET` on the chooser, as local variables on the email form).

- [ ] **Step 1: Write the failing tests**

If `tests/Integration/LoginControllerTest.php` doesn't exist yet, create it with the same `setUp()`/`tearDown()`/`$config`/`rootPdo()` shape as `SignupControllerTest` (Task 5) — `LoginController::chooser()` doesn't touch the DB, so a full DB harness isn't strictly required, but matching the shared pattern keeps the file consistent with the rest of `tests/Integration/`. Minimal version:
```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Http\Controllers\LoginController;
use PHPUnit\Framework\TestCase;

final class LoginControllerTest extends TestCase
{
    public function testChooserIncludesSignupLink(): void
    {
        $_GET = ['client_id' => 'cid', 'redirect_uri' => 'https://app.example/cb', 'state' => 's'];
        $config = ['broker' => ['name' => 'GrandpaSSOn', 'base_url' => 'http://localhost:8080']];

        ob_start();
        (new LoginController())->chooser($config, []);
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('/signup?client_id=cid&amp;redirect_uri=', $body);
        $this->assertStringContainsString('Request access', $body);
    }
}
```
(`Html::e()` HTML-encodes `&` to `&amp;` in the href attribute — match that in the assertion, same as how `EmailOtpLoginControllerTest::testFormRendersEmailInputWithHiddenRpParams` asserts on encoded attribute values.)

Add to `tests/Integration/EmailOtpLoginControllerTest.php`:
```php
    public function testFormIncludesSignupLink(): void
    {
        $_GET = [
            'client_id' => 'cid',
            'redirect_uri' => 'https://app.example/cb',
            'state' => 'client-state',
        ];

        ob_start();
        (new EmailOtpLoginController())->form($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertStringContainsString('/signup?client_id=cid&amp;redirect_uri=', $body);
        $this->assertStringContainsString('Request access', $body);
    }
```

- [ ] **Step 2: Run to verify they fail**

Run: `php vendor/bin/phpunit tests/Integration/LoginControllerTest.php tests/Integration/EmailOtpLoginControllerTest.php --filter Signup`
Expected: FAIL — no signup link exists on either page yet.

- [ ] **Step 3: Add the link to `LoginController::chooser()`**

In `app/Http/Controllers/LoginController.php`, right after the existing:
```php
        $query = http_build_query($_GET);
        $emailHref = Html::e(Html::basePath($config) . '/login/email' . ($query !== '' ? '?' . $query : ''));
        echo '<p class="text-small"><a href="' . $emailHref . '">Or continue with email</a></p>';
```
add:
```php
        $signupHref = Html::e(Html::basePath($config) . '/signup' . ($query !== '' ? '?' . $query : ''));
        echo '<p class="text-small"><a href="' . $signupHref . '">New here? Request access</a></p>';
```

- [ ] **Step 4: Add the link to `EmailOtpLoginController::renderEmailForm()`**

In `app/Http/Controllers/EmailOtpLoginController.php`, inside `renderEmailForm()`, right before the closing `echo '</div>';` (after the `</form>` line), add:
```php
        $signupQuery = http_build_query(array_filter([
            'client_id' => $clientId,
            'redirect_uri' => $redirectUri,
            'state' => $clientState,
            'return_to' => $returnTo,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => $codeChallengeMethod,
        ]));
        $signupHref = Html::e(Html::basePath($config) . '/signup' . ($signupQuery !== '' ? '?' . $signupQuery : ''));
        echo '<p class="text-small"><a href="' . $signupHref . '">New here? Request access</a></p>';
```

- [ ] **Step 5: Run to verify they pass**

Run: `php vendor/bin/phpunit tests/Integration/LoginControllerTest.php tests/Integration/EmailOtpLoginControllerTest.php`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/LoginController.php app/Http/Controllers/EmailOtpLoginController.php \
        tests/Integration/LoginControllerTest.php tests/Integration/EmailOtpLoginControllerTest.php
git commit -m "feat: link to self-enrollment signup from both existing login screens"
```

---

## Task 9: Admin approval verbs (`user:list-pending`, `user:approve`, `user:reject`, `user:reopen`)

**Files:**
- Modify: `app/Infrastructure/Admin/AdminCommandRunner.php`
- Modify: `cron/admin.php` (help text only)
- Test: `tests/Integration/AdminCommandRunnerTest.php` (extend if it exists — check with `find tests -iname "*AdminCommandRunner*"`; otherwise the tests below assume it exists already since 15 verbs are already covered there)

**Interfaces:**
- Produces: `AdminCommandRunner::verbs()` gains `'user:list-pending'`, `'user:approve'`, `'user:reject'`, `'user:reopen'`. `AdminCommandRunner::__construct(..., private readonly array $config = [])` — new trailing constructor param carrying `mail` + `broker` config for the approval/rejection notification emails. `fromPdo(PDO $pdo, array $config = [])` now passes `$config` through as that last constructor arg (it already receives `$config` as a parameter — just wasn't storing it before).
- Consumes: `GrandpaSSOn\Infrastructure\Mail\MailerFactory`.

- [ ] **Step 1: Read the existing test file's harness first**

```bash
sed -n '1,60p' tests/Integration/AdminCommandRunnerTest.php
```
Match its exact `setUp()`/`tearDown()` pattern (same throwaway-database approach as `UserProvisionerTest`) and how it constructs `AdminCommandRunner::fromPdo($this->pdo, [...])` — note what `$config` shape it already passes, since you're adding `mail`/`broker` keys to that same array.

- [ ] **Step 2: Write the failing tests**

Add to `tests/Integration/AdminCommandRunnerTest.php` (adapt the `$this->pdo`/seed helpers to match the file's existing conventions — this shows the required assertions):
```php
public function testListPendingReturnsOnlyPendingSignups(): void
{
    $userId = $this->seedPendingUser('pending@example.com', 'Pending Person', 'I need access');
    $runner = AdminCommandRunner::fromPdo($this->pdo, $this->config());

    $result = $runner->run('user:list-pending', []);

    $this->assertTrue($result['ok']);
    $this->assertCount(1, $result['pending']);
    $this->assertSame('pending@example.com', $result['pending'][0]['email']);
    $this->assertSame($userId, $result['pending'][0]['user_id']);
}

public function testApprovePromotesUserToActive(): void
{
    $userId = $this->seedPendingUser('approve-me@example.com', 'Approve Me', 'Reason');
    $runner = AdminCommandRunner::fromPdo($this->pdo, $this->config());

    $result = $runner->run('user:approve', [$userId]);

    $this->assertTrue($result['ok']);
    $this->assertSame('active', $result['status']);
    $status = $this->pdo->query('SELECT status FROM users WHERE id = ' . $this->pdo->quote($userId))->fetchColumn();
    $this->assertSame('active', $status);
    $requestStatus = $this->pdo->query('SELECT status FROM signup_requests WHERE user_id = ' . $this->pdo->quote($userId))->fetchColumn();
    $this->assertSame('approved', $requestStatus);
}

public function testApproveRejectsNonPendingUser(): void
{
    $userId = $this->seedPendingUser('already-active@example.com', 'Already Active', 'Reason');
    $runner = AdminCommandRunner::fromPdo($this->pdo, $this->config());
    $runner->run('user:approve', [$userId]);

    $this->expectException(\InvalidArgumentException::class);
    $runner->run('user:approve', [$userId]);
}

public function testRejectStoresReasonAndBlocksLogin(): void
{
    $userId = $this->seedPendingUser('reject-me@example.com', 'Reject Me', 'Reason');
    $runner = AdminCommandRunner::fromPdo($this->pdo, $this->config());

    $result = $runner->run('user:reject', [$userId], ['reason' => 'Not a valid business need']);

    $this->assertTrue($result['ok']);
    $this->assertSame('rejected', $result['status']);
    $row = $this->pdo->query(
        'SELECT status, rejection_reason FROM signup_requests WHERE user_id = ' . $this->pdo->quote($userId)
    )->fetch(PDO::FETCH_ASSOC);
    $this->assertSame('rejected', $row['status']);
    $this->assertSame('Not a valid business need', $row['rejection_reason']);
}

public function testReopenMovesRejectedBackToPending(): void
{
    $userId = $this->seedPendingUser('reopen-me@example.com', 'Reopen Me', 'Reason');
    $runner = AdminCommandRunner::fromPdo($this->pdo, $this->config());
    $runner->run('user:reject', [$userId], ['reason' => 'try again later']);

    $result = $runner->run('user:reopen', [$userId]);

    $this->assertTrue($result['ok']);
    $this->assertSame('pending', $result['status']);
    $row = $this->pdo->query(
        'SELECT status, reviewed_by, rejection_reason FROM signup_requests WHERE user_id = ' . $this->pdo->quote($userId)
    )->fetch(PDO::FETCH_ASSOC);
    $this->assertSame('pending', $row['status']);
    $this->assertNull($row['reviewed_by']);
    $this->assertNull($row['rejection_reason']);
}

/** Adjust to match this file's existing config()/seed helpers if they already exist under different names. */
private function config(): array
{
    return [
        'jwt' => ['key_encryption_secret' => 'test-secret'],
        'app_env' => 'dev',
        'mail' => ['transport' => 'sendmail', 'from_address' => 'noreply@example.com', 'from_name' => 'Test'],
        'broker' => ['name' => 'Test Broker', 'base_url' => 'https://sso.example.com'],
    ];
}

private function seedPendingUser(string $email, string $name, string $justification): string
{
    $userId = \GrandpaSSOn\Domain\Uuid::v4();
    $now = gmdate('Y-m-d H:i:s');
    $this->pdo->prepare(
        'INSERT INTO users (id, primary_email, email_verified, display_name, avatar_url, status, created_at, updated_at)
         VALUES (:id, :email, 1, :name, NULL, \'pending\', :now, :now)'
    )->execute(['id' => $userId, 'email' => $email, 'name' => $name, 'now' => $now]);
    $this->pdo->prepare(
        'INSERT INTO signup_requests (id, user_id, email, display_name, justification, source, status, created_at, updated_at)
         VALUES (:sid, :uid, :email, :name, :justification, \'email\', \'pending\', :now, :now)'
    )->execute([
        'sid' => \GrandpaSSOn\Domain\Uuid::v4(),
        'uid' => $userId,
        'email' => $email,
        'name' => $name,
        'justification' => $justification,
        'now' => $now,
    ]);

    return $userId;
}
```
If `config()`/a seed helper with equivalent purpose already exists in the file under different names, reuse/extend those instead of duplicating — read the file fully before adding.

- [ ] **Step 3: Run to verify they fail**

Run: `php vendor/bin/phpunit tests/Integration/AdminCommandRunnerTest.php --filter "Pending|Approve|Reject|Reopen"`
Expected: FAIL — `Unknown verb: user:list-pending` (`\InvalidArgumentException`).

- [ ] **Step 4: Add the config param + new verbs to `AdminCommandRunner`**

In `app/Infrastructure/Admin/AdminCommandRunner.php`:

Add the import:
```php
use GrandpaSSOn\Infrastructure\Mail\MailerFactory;
```

Change the constructor to accept config:
```php
    public function __construct(
        private readonly PDO $pdo,
        private readonly TenantRepository $tenants,
        private readonly ServiceClientRepository $clients,
        private readonly AccessTokenRepository $tokens,
        private readonly AuditLogger $audit,
        private readonly PublishedSiteRepository $sites,
        private readonly JwtSigningKeyRepository $jwtKeys,
        private readonly array $config = [],
    ) {
    }

    public static function fromPdo(PDO $pdo, array $config = []): self
    {
        $jwt = is_array($config['jwt'] ?? null) ? $config['jwt'] : [];
        $secret = (string) ($jwt['key_encryption_secret'] ?? '');
        $appEnv = (string) ($config['app_env'] ?? 'dev');

        return new self(
            $pdo,
            new TenantRepository($pdo),
            new ServiceClientRepository($pdo),
            new AccessTokenRepository($pdo),
            new AuditLogger($pdo),
            new PublishedSiteRepository($pdo),
            new JwtSigningKeyRepository($pdo, $secret, $appEnv),
            $config,
        );
    }
```

Add the four verbs to `verbs()`:
```php
            'user:list-pending',
            'user:approve',
            'user:reject',
            'user:reopen',
```

Add the four match arms to `run()`:
```php
            'user:list-pending' => $this->userListPending(),
            'user:approve' => $this->userApprove($argv),
            'user:reject' => $this->userReject($argv, $flags),
            'user:reopen' => $this->userReopen($argv),
```

Add the implementations (place near `assertUserExists`, before `auditMutation`):
```php
    /** @return array<string, mixed> */
    private function userListPending(): array
    {
        $rows = $this->pdo->query(
            "SELECT u.id AS user_id, u.primary_email AS email, u.display_name, sr.justification, sr.source, sr.created_at
             FROM signup_requests sr
             INNER JOIN users u ON u.id = sr.user_id
             WHERE sr.status = 'pending'
             ORDER BY sr.created_at ASC"
        )->fetchAll(PDO::FETCH_ASSOC);

        return ['ok' => true, 'pending' => $rows];
    }

    /** @param list<string> $argv @return array<string, mixed> */
    private function userApprove(array $argv): array
    {
        $userId = (string) ($argv[0] ?? '');
        if ($userId === '') {
            throw new \InvalidArgumentException('Usage: user:approve <user_id>');
        }
        $user = $this->findPendingUser($userId);

        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare("UPDATE users SET status = 'active', updated_at = :now WHERE id = :id")
            ->execute(['now' => $now, 'id' => $userId]);
        $this->pdo->prepare(
            "UPDATE signup_requests SET status = 'approved', reviewed_by = :by, reviewed_at = :now, updated_at = :now WHERE user_id = :id"
        )->execute(['by' => 'cli', 'now' => $now, 'id' => $userId]);

        $this->auditMutation('user.approve', $userId);
        $this->notifyUser((string) $user['email'], 'Your account has been approved', $this->approvalEmailBody());

        return ['ok' => true, 'user_id' => $userId, 'status' => 'active'];
    }

    /** @param list<string> $argv @return array<string, mixed> */
    private function userReject(array $argv, array $flags): array
    {
        $userId = (string) ($argv[0] ?? '');
        $reason = (string) ($flags['reason'] ?? '');
        if ($userId === '') {
            throw new \InvalidArgumentException('Usage: user:reject <user_id> --reason="..."');
        }
        $user = $this->findPendingUser($userId);

        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare("UPDATE users SET status = 'rejected', updated_at = :now WHERE id = :id")
            ->execute(['now' => $now, 'id' => $userId]);
        $this->pdo->prepare(
            "UPDATE signup_requests SET status = 'rejected', reviewed_by = :by, reviewed_at = :now, rejection_reason = :reason, updated_at = :now WHERE user_id = :id"
        )->execute(['by' => 'cli', 'now' => $now, 'reason' => $reason !== '' ? $reason : null, 'id' => $userId]);

        $this->auditMutation('user.reject', $userId);
        $this->notifyUser((string) $user['email'], 'Your signup was not approved', $this->rejectionEmailBody());

        return ['ok' => true, 'user_id' => $userId, 'status' => 'rejected'];
    }

    /** @param list<string> $argv @return array<string, mixed> */
    private function userReopen(array $argv): array
    {
        $userId = (string) ($argv[0] ?? '');
        if ($userId === '') {
            throw new \InvalidArgumentException('Usage: user:reopen <user_id>');
        }
        $this->assertUserExists($userId);

        $now = gmdate('Y-m-d H:i:s');
        $this->pdo->prepare("UPDATE users SET status = 'pending', updated_at = :now WHERE id = :id")
            ->execute(['now' => $now, 'id' => $userId]);
        $this->pdo->prepare(
            "UPDATE signup_requests SET status = 'pending', reviewed_by = NULL, reviewed_at = NULL, rejection_reason = NULL, updated_at = :now WHERE user_id = :id"
        )->execute(['now' => $now, 'id' => $userId]);

        $this->auditMutation('user.reopen', $userId);

        return ['ok' => true, 'user_id' => $userId, 'status' => 'pending'];
    }

    /** @return array<string, mixed> */
    private function findPendingUser(string $userId): array
    {
        $stmt = $this->pdo->prepare('SELECT id, primary_email AS email, status FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row === false) {
            throw new \InvalidArgumentException('Unknown subject user_id: ' . $userId);
        }
        if ($row['status'] !== 'pending') {
            throw new \InvalidArgumentException('User is not pending: ' . $userId . ' (status=' . $row['status'] . ')');
        }

        return $row;
    }

    private function notifyUser(string $email, string $subject, string $body): void
    {
        $mailConfig = is_array($this->config['mail'] ?? null) ? $this->config['mail'] : [];
        if ($mailConfig === []) {
            return;
        }
        try {
            (new MailerFactory($mailConfig))->make()->send($email, $subject, $body);
        } catch (\Throwable $e) {
            error_log('signup decision notification mail failed: ' . $e->getMessage());
        }
    }

    private function approvalEmailBody(): string
    {
        $name = (string) ($this->config['broker']['name'] ?? 'GrandpaSSOn');
        $loginUrl = rtrim((string) ($this->config['broker']['base_url'] ?? ''), '/');

        return "Your {$name} account has been approved. You can now sign in: {$loginUrl}/login\n";
    }

    private function rejectionEmailBody(): string
    {
        $name = (string) ($this->config['broker']['name'] ?? 'GrandpaSSOn');

        return "Your signup request for {$name} was not approved.\n";
    }
```

- [ ] **Step 5: Update `cron/admin.php` help text**

In the header docblock comment and the printed `TXT` heredoc block in `cron/admin.php`, add these four lines after `jwt:key-retire <kid>`:
```
 *   user:list-pending
 *   user:approve <user_id>
 *   user:reject <user_id> --reason="..."
 *   user:reopen <user_id>
```
(and the matching plain-text lines in the `<<<'TXT'` block further down the file — find it with `grep -n "jwt:key-retire" cron/admin.php` and add right after both occurrences).

- [ ] **Step 6: Run to verify they pass**

Run: `php vendor/bin/phpunit tests/Integration/AdminCommandRunnerTest.php`
Expected: PASS (all previous + 5 new tests).

- [ ] **Step 7: Run the full suite**

Run: `php vendor/bin/phpunit`
Expected: PASS.

- [ ] **Step 8: Manually verify the CLI and HTML UI expose the new verbs**

Run: `php cron/admin.php help`
Expected: output includes the four new `user:*` lines.

With `make up` running and `ADMIN_API_TOKEN` set in `.env`, visit `/admin` in a browser and confirm `user:list-pending`, `user:approve`, `user:reject`, `user:reopen` appear in the verb `<select>` (they will — `AdminUiController::index()` iterates `AdminCommandRunner::verbs()` directly, no template change needed).

- [ ] **Step 9: Commit**

```bash
git add app/Infrastructure/Admin/AdminCommandRunner.php cron/admin.php tests/Integration/AdminCommandRunnerTest.php
git commit -m "feat: add user:list-pending/approve/reject/reopen admin verbs with decision emails"
```

---

## Task 10: End-to-end integration test + docs

**Files:**
- Create: `tests/Integration/SelfEnrollmentEndToEndTest.php`
- Modify: `README.md` (HTTP surface table + verbs list)
- Modify: `docs/deployment.md` (mention `ADMIN_NOTIFICATION_EMAILS` in the env checklist, if that doc lists env vars — check first)

**Interfaces:**
- None new — this task only proves the full chain works together and documents it.

**Scope note:** this covers the email-OTP path end-to-end, since it's fully testable in-process (same pattern as Tasks 5-7). The OAuth path's end-to-end coverage is the manual QA already done in Task 6 Step 7 and Task 7 Step 7 — no new automated OAuth E2E test is added here, for the same live-IdP-dependency reason explained in Task 6.

- [ ] **Step 1: Write the end-to-end test**

`tests/Integration/SelfEnrollmentEndToEndTest.php`, using the same `setUp()`/`tearDown()`/`$config`/`rootPdo()` shape as `SignupControllerTest` (Task 5):
```php
<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Integration;

use GrandpaSSOn\Http\Controllers\EmailOtpLoginController;
use GrandpaSSOn\Http\Controllers\SignupController;
use GrandpaSSOn\Infrastructure\Admin\AdminCommandRunner;
use GrandpaSSOn\Infrastructure\Auth\EmailOtpService;
use GrandpaSSOn\Infrastructure\Db\Connection;
use GrandpaSSOn\Support\Csrf;
use GrandpaSSOn\Support\RateLimitGate;
use PDO;
use PHPUnit\Framework\TestCase;

final class SelfEnrollmentEndToEndTest extends TestCase
{
    // Copy setUp()/tearDown()/$config/rootPdo() verbatim from SignupControllerTest (Task 5).

    public function testSignupApproveThenLoginSucceeds(): void
    {
        $email = 'e2e.approve@example.com';
        $this->runSignup($email, 'E2E Approve', 'I need reporting access.');

        $user = $this->pdo->query("SELECT id FROM users WHERE primary_email = '{$email}'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($user);

        $adminConfig = $this->config;
        $adminConfig['jwt'] = ['key_encryption_secret' => 'test-secret'];
        $result = AdminCommandRunner::fromPdo($this->pdo, $adminConfig)->run('user:approve', [$user['id']]);
        $this->assertTrue($result['ok']);
        $this->assertSame('active', $result['status']);

        $started = (new EmailOtpService($this->pdo))->start(
            $email,
            [
                'client_id' => 'cid',
                'redirect_uri' => 'https://app.example/cb',
                'client_state' => 'client-state',
                'return_to' => null,
                'code_challenge' => null,
                'code_challenge_method' => null,
            ],
            600,
            5,
            6,
        );
        $_SESSION = ['email_otp_id' => $started['id']];
        $csrf = Csrf::token();
        $_POST = ['csrf' => $csrf, 'code' => $started['code']];

        ob_start();
        (new EmailOtpLoginController())->verify($this->config, []);
        ob_get_clean();

        $this->assertSame(302, http_response_code());
        http_response_code(200);
        $this->assertArrayHasKey('user_id', $_SESSION);
        $this->assertSame($user['id'], $_SESSION['user_id']);
    }

    public function testSignupRejectThenLoginBlocked(): void
    {
        $email = 'e2e.reject@example.com';
        $this->runSignup($email, 'E2E Reject', 'Trying my luck.');

        $user = $this->pdo->query("SELECT id FROM users WHERE primary_email = '{$email}'")->fetch(PDO::FETCH_ASSOC);
        $this->assertNotFalse($user);

        $adminConfig = $this->config;
        $adminConfig['jwt'] = ['key_encryption_secret' => 'test-secret'];
        $result = AdminCommandRunner::fromPdo($this->pdo, $adminConfig)->run('user:reject', [$user['id']], ['reason' => 'not a valid case']);
        $this->assertTrue($result['ok']);
        $this->assertSame('rejected', $result['status']);

        $started = (new EmailOtpService($this->pdo))->start(
            $email,
            [
                'client_id' => 'cid',
                'redirect_uri' => 'https://app.example/cb',
                'client_state' => 'client-state',
                'return_to' => null,
                'code_challenge' => null,
                'code_challenge_method' => null,
            ],
            600,
            5,
            6,
        );
        $_SESSION = ['email_otp_id' => $started['id']];
        $csrf = Csrf::token();
        $_POST = ['csrf' => $csrf, 'code' => $started['code']];

        ob_start();
        (new EmailOtpLoginController())->verify($this->config, []);
        $body = (string) ob_get_clean();

        $this->assertSame(403, http_response_code());
        http_response_code(200);
        $this->assertStringContainsString('not approved', $body);
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    private function runSignup(string $email, string $name, string $justification): void
    {
        $_SESSION = [];
        $csrf = Csrf::token();
        $_POST = [
            'csrf' => $csrf,
            'client_id' => 'cid',
            'redirect_uri' => 'https://app.example/cb',
            'state' => 'client-state',
            'name' => $name,
            'email' => $email,
            'justification' => $justification,
        ];
        ob_start();
        (new SignupController())->start($this->config, []);
        ob_get_clean();

        $started = (new EmailOtpService($this->pdo))->start(
            $email,
            [
                'client_id' => 'cid',
                'redirect_uri' => 'https://app.example/cb',
                'client_state' => 'client-state',
                'return_to' => null,
                'code_challenge' => null,
                'code_challenge_method' => null,
            ],
            600,
            5,
            6,
        );
        $_SESSION['signup_otp_id'] = $started['id'];
        $_SESSION['signup_profile'] = ['name' => $name, 'justification' => $justification];
        $csrf2 = Csrf::token();
        $_POST = ['csrf' => $csrf2, 'code' => $started['code']];

        ob_start();
        (new SignupController())->verify($this->config, []);
        $body = (string) ob_get_clean();
        $this->assertStringContainsString('Request received', $body);

        $_SESSION = [];
        $_POST = [];
    }
}
```

- [ ] **Step 2: Run to verify it passes**

Run: `php vendor/bin/phpunit tests/Integration/SelfEnrollmentEndToEndTest.php`
Expected: PASS — given Tasks 1-9 are already implemented at this point, this should pass on the first real run. If it doesn't, that means one of the earlier tasks has a gap; debug against the actual failure (don't adjust this test's assertions to force a pass).

- [ ] **Step 3: Update `README.md`**

In the `## HTTP surface (v0 + v1 P0)` table, add rows for the new routes:
```
| `GET` | `/signup`, `/signup/complete` | Self-enrollment: request access (email OTP form, or OAuth-completion screen) |
| `POST` | `/signup/start`, `/signup/verify`, `/signup/complete` | Self-enrollment: submit request / verify email / finish OAuth signup |
```
And in the `Admin (CLI, ships in zip):` line's surrounding prose (or wherever the verb families are summarized — check the current text), mention: `user:list-pending`, `user:approve`, `user:reject`, `user:reopen` — self-enrollment approval.

- [ ] **Step 4: Update `docs/deployment.md` if it enumerates env vars**

Run: `grep -n "ALLOWED_EMAIL_DOMAINS\|ADMIN_API_TOKEN" docs/deployment.md`
If it lists specific env vars to set at deploy time, add a line for `ADMIN_NOTIFICATION_EMAILS` next to `ALLOWED_EMAIL_DOMAINS` with a one-line note: "leave empty to disable admin email alerts; approvals still work via CLI/`/admin`." If the doc doesn't enumerate env vars at that level of detail, skip this step (don't invent a section that doesn't fit the doc's existing structure).

- [ ] **Step 5: Commit**

```bash
git add tests/Integration/SelfEnrollmentEndToEndTest.php README.md docs/deployment.md
git commit -m "test: add end-to-end self-enrollment coverage; document signup routes and admin verbs"
```

---

## Deployment note (not a task — flag to the user before merging)

Production's `.env` (`/home/u250556264/domains/hub.taskconnect.com.br/public_html/grandpasson/.env` on the Hostinger host, per `.env.production` in this workspace) does not currently set `ALLOWED_EMAIL_DOMAINS`, and `APP_ENV=prod`. After this change ships, `SignupService::assertEmailAllowed()` will refuse **every** signup in production until `ALLOWED_EMAIL_DOMAINS` is set (mirrors today's already-shipped `UserProvisioner::assertMayAutoCreate` behavior for the same reason — this isn't a regression, but it means self-enrollment is inert until that var is set). Decide the allowed domain list with the user before/at deploy time, and add `ADMIN_NOTIFICATION_EMAILS` too so someone actually gets pinged when a signup request lands.
