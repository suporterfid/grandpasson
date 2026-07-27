<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Db;

use GrandpaSSOn\Domain\PublishedSite;
use PDO;

final class PublishedSiteRepository
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function create(
        string $siteId,
        string $name,
        string $visibility = PublishedSite::VIS_PUBLIC,
        ?string $tenantId = null,
        bool $enabled = true,
    ): PublishedSite {
        if (!in_array($visibility, PublishedSite::VISIBILITIES, true)) {
            throw new \InvalidArgumentException('visibility must be public|authenticated|private');
        }
        $createdAt = gmdate('Y-m-d H:i:s');
        $stmt = $this->pdo->prepare(
            'INSERT INTO published_sites (site_id, name, visibility, tenant_id, enabled, created_at)
             VALUES (:site_id, :name, :visibility, :tenant_id, :enabled, :created_at)'
        );
        $stmt->execute([
            'site_id' => $siteId,
            'name' => $name,
            'visibility' => $visibility,
            'tenant_id' => $tenantId,
            'enabled' => $enabled ? 1 : 0,
            'created_at' => $createdAt,
        ]);

        return new PublishedSite($siteId, $name, $visibility, $tenantId, $enabled, $createdAt);
    }

    public function findBySiteId(string $siteId): ?PublishedSite
    {
        $stmt = $this->pdo->prepare('SELECT * FROM published_sites WHERE site_id = :id LIMIT 1');
        $stmt->execute(['id' => $siteId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->map($row);
    }

    public function setVisibility(string $siteId, string $visibility): void
    {
        if (!in_array($visibility, PublishedSite::VISIBILITIES, true)) {
            throw new \InvalidArgumentException('visibility must be public|authenticated|private');
        }
        $stmt = $this->pdo->prepare(
            'UPDATE published_sites SET visibility = :v WHERE site_id = :id'
        );
        $stmt->execute(['v' => $visibility, 'id' => $siteId]);
        if ($stmt->rowCount() === 0 && $this->findBySiteId($siteId) === null) {
            throw new \InvalidArgumentException('Unknown site_id: ' . $siteId);
        }
    }

    /** @param array<string, mixed> $row */
    private function map(array $row): PublishedSite
    {
        return new PublishedSite(
            (string) $row['site_id'],
            (string) $row['name'],
            (string) $row['visibility'],
            $row['tenant_id'] !== null ? (string) $row['tenant_id'] : null,
            (int) $row['enabled'] === 1,
            (string) $row['created_at'],
        );
    }
}
