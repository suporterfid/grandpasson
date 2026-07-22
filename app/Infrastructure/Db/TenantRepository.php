<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Db;

use GrandpaSSOn\Domain\Group;
use GrandpaSSOn\Domain\Tenant;
use GrandpaSSOn\Domain\TenantMembership;
use GrandpaSSOn\Domain\Uuid;
use PDO;
use PDOException;

final class TenantRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(string $slug, string $name, string $status = Tenant::STATUS_ACTIVE): Tenant
    {
        $slug = $this->normalizeSlug($slug);
        if ($slug === '') {
            throw new \InvalidArgumentException('tenant slug is required');
        }
        if ($name === '') {
            throw new \InvalidArgumentException('tenant name is required');
        }
        if (!in_array($status, [Tenant::STATUS_ACTIVE, Tenant::STATUS_DISABLED], true)) {
            throw new \InvalidArgumentException('invalid tenant status');
        }

        $id = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO tenants (id, slug, name, status, created_at, updated_at)
                 VALUES (:id, :slug, :name, :status, :created_at, :updated_at)'
            );
            $stmt->execute([
                'id' => $id,
                'slug' => $slug,
                'name' => $name,
                'status' => $status,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        } catch (PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                throw new \InvalidArgumentException('tenant slug already exists: ' . $slug, 0, $e);
            }
            throw $e;
        }

        return new Tenant($id, $slug, $name, $status);
    }

    public function findById(string $id): ?Tenant
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tenants WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapTenant($row);
    }

    public function findBySlug(string $slug): ?Tenant
    {
        $stmt = $this->pdo->prepare('SELECT * FROM tenants WHERE slug = :slug LIMIT 1');
        $stmt->execute(['slug' => $this->normalizeSlug($slug)]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapTenant($row);
    }

    public function addMember(string $tenantId, string $userId, string $role): TenantMembership
    {
        if (!in_array($role, Tenant::ROLES, true)) {
            throw new \InvalidArgumentException('Invalid tenant role: ' . $role);
        }
        if ($this->findById($tenantId) === null) {
            throw new \InvalidArgumentException('Unknown tenant_id');
        }

        $now = gmdate('Y-m-d H:i:s');
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO tenant_members (tenant_id, user_id, role, created_at)
                 VALUES (:tenant_id, :user_id, :role, :created_at)'
            );
            $stmt->execute([
                'tenant_id' => $tenantId,
                'user_id' => $userId,
                'role' => $role,
                'created_at' => $now,
            ]);
        } catch (PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                throw new \InvalidArgumentException('user is already a member of this tenant', 0, $e);
            }
            throw $e;
        }

        return new TenantMembership($tenantId, $userId, $role);
    }

    /**
     * @return list<TenantMembership>
     */
    public function listMembershipsForUser(string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT tm.tenant_id, tm.user_id, tm.role, t.slug AS tenant_slug, t.name AS tenant_name
             FROM tenant_members tm
             INNER JOIN tenants t ON t.id = tm.tenant_id
             WHERE tm.user_id = :user_id
             ORDER BY t.slug ASC'
        );
        $stmt->execute(['user_id' => $userId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $row) {
            $out[] = new TenantMembership(
                (string) $row['tenant_id'],
                (string) $row['user_id'],
                (string) $row['role'],
                (string) $row['tenant_slug'],
                (string) $row['tenant_name'],
            );
        }

        return $out;
    }

    public function createGroup(string $tenantId, string $slug, string $name): Group
    {
        $slug = $this->normalizeSlug($slug);
        if ($slug === '' || $name === '') {
            throw new \InvalidArgumentException('group slug and name are required');
        }
        if ($this->findById($tenantId) === null) {
            throw new \InvalidArgumentException('Unknown tenant_id');
        }

        $id = Uuid::v4();
        $now = gmdate('Y-m-d H:i:s');
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO `groups` (id, tenant_id, slug, name, created_at)
                 VALUES (:id, :tenant_id, :slug, :name, :created_at)'
            );
            $stmt->execute([
                'id' => $id,
                'tenant_id' => $tenantId,
                'slug' => $slug,
                'name' => $name,
                'created_at' => $now,
            ]);
        } catch (PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                throw new \InvalidArgumentException('group slug already exists in tenant: ' . $slug, 0, $e);
            }
            throw $e;
        }

        return new Group($id, $tenantId, $slug, $name);
    }

    public function findGroupByTenantAndSlug(string $tenantId, string $slug): ?Group
    {
        $stmt = $this->pdo->prepare(
            'SELECT * FROM `groups` WHERE tenant_id = :tenant_id AND slug = :slug LIMIT 1'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'slug' => $this->normalizeSlug($slug),
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapGroup($row);
    }

    public function addGroupMember(string $groupId, string $userId): void
    {
        $group = $this->findGroupById($groupId);
        if ($group === null) {
            throw new \InvalidArgumentException('Unknown group_id');
        }

        $now = gmdate('Y-m-d H:i:s');
        try {
            $stmt = $this->pdo->prepare(
                'INSERT INTO group_members (group_id, user_id, created_at)
                 VALUES (:group_id, :user_id, :created_at)'
            );
            $stmt->execute([
                'group_id' => $groupId,
                'user_id' => $userId,
                'created_at' => $now,
            ]);
        } catch (PDOException $e) {
            if ($this->isDuplicateKey($e)) {
                throw new \InvalidArgumentException('user is already a member of this group', 0, $e);
            }
            throw $e;
        }
    }

    /**
     * @return list<string> Group slugs for the user within a tenant
     */
    public function listGroupSlugsForUserInTenant(string $tenantId, string $userId): array
    {
        $stmt = $this->pdo->prepare(
            'SELECT g.slug
             FROM group_members gm
             INNER JOIN `groups` g ON g.id = gm.group_id
             WHERE g.tenant_id = :tenant_id AND gm.user_id = :user_id
             ORDER BY g.slug ASC'
        );
        $stmt->execute([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
        ]);
        $slugs = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return array_values(array_map('strval', $slugs ?: []));
    }

    public function findGroupById(string $id): ?Group
    {
        $stmt = $this->pdo->prepare('SELECT * FROM `groups` WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->mapGroup($row);
    }

    private function normalizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));

        return preg_replace('/[^a-z0-9_-]+/', '-', $slug) ?? '';
    }

    private function isDuplicateKey(PDOException $e): bool
    {
        return $e->getCode() === '23000' || str_contains($e->getMessage(), 'Duplicate');
    }

    /** @param array<string, mixed> $row */
    private function mapTenant(array $row): Tenant
    {
        return new Tenant(
            (string) $row['id'],
            (string) $row['slug'],
            (string) $row['name'],
            (string) $row['status'],
        );
    }

    /** @param array<string, mixed> $row */
    private function mapGroup(array $row): Group
    {
        return new Group(
            (string) $row['id'],
            (string) $row['tenant_id'],
            (string) $row['slug'],
            (string) $row['name'],
        );
    }
}
