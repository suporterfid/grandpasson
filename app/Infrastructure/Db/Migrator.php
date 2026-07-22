<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Db;

use PDO;

final class Migrator
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsDir,
    ) {
    }

    /**
     * Apply pending *.sql files in lexical order.
     *
     * @return list<string> Basenames newly applied on this run
     */
    public function migrate(): array
    {
        $this->ensureMigrationsTable();

        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        sort($files);

        $newlyApplied = [];
        foreach ($files as $file) {
            $name = basename($file);
            if ($this->isApplied($name)) {
                continue;
            }

            $sql = file_get_contents($file);
            if ($sql === false) {
                throw new \RuntimeException('Unable to read migration: ' . $file);
            }

            // MySQL DDL implicitly commits; do not wrap migrations in a transaction.
            try {
                $this->pdo->exec($sql);
                $stmt = $this->pdo->prepare(
                    'INSERT INTO schema_migrations (migration, applied_at) VALUES (:migration, :applied_at)'
                );
                $stmt->execute([
                    'migration' => $name,
                    'applied_at' => gmdate('Y-m-d H:i:s'),
                ]);
                $newlyApplied[] = $name;
            } catch (\Throwable $e) {
                throw new \RuntimeException('Failed applying migration ' . $name . ': ' . $e->getMessage(), 0, $e);
            }
        }

        return $newlyApplied;
    }

    /**
     * @return array{applied: list<string>, pending: list<string>}
     */
    public function status(): array
    {
        $this->ensureMigrationsTable();

        $files = glob($this->migrationsDir . '/*.sql') ?: [];
        sort($files);
        $names = array_map('basename', $files);

        $applied = [];
        $pending = [];
        foreach ($names as $name) {
            if ($this->isApplied($name)) {
                $applied[] = $name;
            } else {
                $pending[] = $name;
            }
        }

        return ['applied' => $applied, 'pending' => $pending];
    }

    private function ensureMigrationsTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
              migration VARCHAR(255) NOT NULL PRIMARY KEY,
              applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB'
        );
    }

    private function isApplied(string $name): bool
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM schema_migrations WHERE migration = :migration LIMIT 1');
        $stmt->execute(['migration' => $name]);

        return (bool) $stmt->fetchColumn();
    }
}
