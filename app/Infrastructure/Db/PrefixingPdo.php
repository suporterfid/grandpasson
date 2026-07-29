<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Db;

use PDO;
use PDOStatement;

/**
 * PDO subclass that transparently rewrites this app's known table names with a
 * configured prefix in every statement it runs.
 *
 * GrandpaSSOn shares one MySQL database/schema with sibling apps (Jotter, TaskConnect)
 * on shared hosting, distinguished only by table-name prefix (DB_PREFIX). Every
 * repository, cleanup job, and the Migrator route SQL through prepare()/exec()/query()
 * on a single PDO instance, so rewriting here is the one choke point that covers both
 * DDL (migrations, which run verbatim unprefixed .sql files) and DML without touching
 * any call site.
 */
final class PrefixingPdo extends PDO
{
    /**
     * Every table this app creates or queries. Keep in sync with
     * app/Infrastructure/Db/Migrations/*.sql and the ad-hoc `schema_migrations`
     * table created by Migrator.
     */
    private const TABLES = [
        'schema_migrations',
        'users',
        'linked_identities',
        'oauth_clients',
        'sessions',
        'auth_codes',
        'audit_events',
        'tenants',
        'tenant_members',
        'groups',
        'group_members',
        'audit_log',
        'service_clients',
        'access_tokens',
        'rate_limit_counters',
        'published_sites',
        'reader_sessions',
        'jwt_signing_keys',
        'user_active_tenant',
    ];

    private readonly string $prefix;

    /**
     * @param array<int, mixed>|null $options
     */
    public function __construct(string $dsn, ?string $username, ?string $password, ?array $options, string $prefix)
    {
        parent::__construct($dsn, $username, $password, $options);
        $this->prefix = $prefix;
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        return parent::prepare($this->applyPrefix($query), $options);
    }

    public function exec(string $statement): int|false
    {
        return parent::exec($this->applyPrefix($statement));
    }

    public function query(string $query, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        if ($fetchMode === null) {
            return parent::query($this->applyPrefix($query));
        }

        return parent::query($this->applyPrefix($query), $fetchMode, ...$fetchModeArgs);
    }

    public function applyPrefix(string $sql): string
    {
        if ($this->prefix === '') {
            return $sql;
        }

        foreach (self::TABLES as $table) {
            // Backtick-quoted occurrences first (e.g. `groups`, a reserved word),
            // then bare word-boundary occurrences. Underscore is a \w character, so
            // \b never matches inside a compound identifier like tenant_members —
            // each table name in TABLES is matched as a whole token, never a substring.
            $sql = preg_replace('/`' . preg_quote($table, '/') . '`/', '`' . $this->prefix . $table . '`', $sql);
            $sql = preg_replace('/\b' . preg_quote($table, '/') . '\b/', $this->prefix . $table, $sql);
        }

        return $sql;
    }
}
