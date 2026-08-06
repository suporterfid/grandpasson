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
