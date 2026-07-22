<?php

declare(strict_types=1);

namespace GrandpaSSOn\Infrastructure\Admin;

use GrandpaSSOn\Domain\AccessToken;
use GrandpaSSOn\Domain\Tenant;
use GrandpaSSOn\Infrastructure\Audit\AuditLogger;
use GrandpaSSOn\Infrastructure\Db\AccessTokenRepository;
use GrandpaSSOn\Infrastructure\Db\ServiceClientRepository;
use GrandpaSSOn\Infrastructure\Db\TenantRepository;
use PDO;

/**
 * CLI admin verbs (spec §6.7). Returns structured results for tests/CLI output.
 */
final class AdminCommandRunner
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly TenantRepository $tenants,
        private readonly ServiceClientRepository $clients,
        private readonly AccessTokenRepository $tokens,
        private readonly AuditLogger $audit,
    ) {
    }

    public static function fromPdo(PDO $pdo): self
    {
        return new self(
            $pdo,
            new TenantRepository($pdo),
            new ServiceClientRepository($pdo),
            new AccessTokenRepository($pdo),
            new AuditLogger($pdo),
        );
    }

    /**
     * @param list<string> $argv Subcommand args after verb (e.g. slug name)
     * @return array<string, mixed>
     */
    public function run(string $verb, array $argv, array $flags = []): array
    {
        return match ($verb) {
            'tenant:create' => $this->tenantCreate($argv),
            'tenant:add-member' => $this->tenantAddMember($argv),
            'group:create' => $this->groupCreate($argv),
            'group:add-member' => $this->groupAddMember($argv),
            'client:create-service' => $this->clientCreateService($argv, $flags),
            'client:rotate-secret' => $this->clientRotateSecret($argv),
            'token:list' => $this->tokenList($flags),
            'token:revoke' => $this->tokenRevoke($argv, $flags),
            'pat:create' => $this->patCreate($argv, $flags),
            'pat:list' => $this->patList($flags),
            'pat:revoke' => $this->patRevoke($argv, $flags),
            default => throw new \InvalidArgumentException('Unknown verb: ' . $verb),
        };
    }

    /** @param list<string> $argv @return array<string, mixed> */
    private function tenantCreate(array $argv): array
    {
        $slug = (string) ($argv[0] ?? '');
        $name = (string) ($argv[1] ?? '');
        if ($slug === '' || $name === '') {
            throw new \InvalidArgumentException('Usage: tenant:create <slug> <name>');
        }
        $tenant = $this->tenants->create($slug, $name);
        $this->auditMutation('tenant.create', $tenant->slug);

        return ['ok' => true, 'tenant_id' => $tenant->id, 'slug' => $tenant->slug, 'name' => $tenant->name];
    }

    /** @param list<string> $argv @return array<string, mixed> */
    private function tenantAddMember(array $argv): array
    {
        $tenantRef = (string) ($argv[0] ?? '');
        $subject = (string) ($argv[1] ?? '');
        $role = (string) ($argv[2] ?? Tenant::ROLE_MEMBER);
        if ($tenantRef === '' || $subject === '') {
            throw new \InvalidArgumentException('Usage: tenant:add-member <tenant> <subject> <role>');
        }
        if (!in_array($role, Tenant::ROLES, true)) {
            throw new \InvalidArgumentException('role must be owner|admin|member');
        }
        $tenant = $this->resolveTenant($tenantRef);
        $this->assertUserExists($subject);
        $this->tenants->addMember($tenant->id, $subject, $role);
        $this->auditMutation('tenant.add_member', $tenant->slug . ':' . $subject);

        return ['ok' => true, 'tenant_id' => $tenant->id, 'user_id' => $subject, 'role' => $role];
    }

    /** @param list<string> $argv @return array<string, mixed> */
    private function groupCreate(array $argv): array
    {
        $tenantRef = (string) ($argv[0] ?? '');
        $slug = (string) ($argv[1] ?? '');
        $name = (string) ($argv[2] ?? $slug);
        if ($tenantRef === '' || $slug === '') {
            throw new \InvalidArgumentException('Usage: group:create <tenant> <slug> [name]');
        }
        $tenant = $this->resolveTenant($tenantRef);
        $group = $this->tenants->createGroup($tenant->id, $slug, $name !== '' ? $name : $slug);
        $this->auditMutation('group.create', $tenant->slug . ':' . $group->slug);

        return ['ok' => true, 'group_id' => $group->id, 'tenant_id' => $tenant->id, 'slug' => $group->slug];
    }

    /** @param list<string> $argv @return array<string, mixed> */
    private function groupAddMember(array $argv): array
    {
        $tenantRef = (string) ($argv[0] ?? '');
        $groupSlug = (string) ($argv[1] ?? '');
        $subject = (string) ($argv[2] ?? '');
        if ($tenantRef === '' || $groupSlug === '' || $subject === '') {
            throw new \InvalidArgumentException('Usage: group:add-member <tenant> <group> <subject>');
        }
        $tenant = $this->resolveTenant($tenantRef);
        $group = $this->tenants->findGroupByTenantAndSlug($tenant->id, $groupSlug);
        if ($group === null) {
            throw new \InvalidArgumentException('Unknown group slug: ' . $groupSlug);
        }
        $this->assertUserExists($subject);
        $this->tenants->addGroupMember($group->id, $subject);
        $this->auditMutation('group.add_member', $tenant->slug . ':' . $groupSlug . ':' . $subject);

        return ['ok' => true, 'group_id' => $group->id, 'user_id' => $subject];
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $flags
     * @return array<string, mixed>
     */
    private function clientCreateService(array $argv, array $flags): array
    {
        $name = (string) ($argv[0] ?? '');
        if ($name === '') {
            throw new \InvalidArgumentException('Usage: client:create-service <name> --scopes=… [--aud=…] [--client-id=…]');
        }
        $scopesRaw = (string) ($flags['scopes'] ?? 'kb:read');
        $scopes = array_values(array_filter(array_map('trim', explode(',', str_replace(' ', ',', $scopesRaw)))));
        if ($scopes === []) {
            throw new \InvalidArgumentException('--scopes is required');
        }
        $aud = isset($flags['aud']) && $flags['aud'] !== '' ? (string) $flags['aud'] : null;
        $clientId = (string) ($flags['client-id'] ?? ('svc_' . bin2hex(random_bytes(6))));
        $secret = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $client = $this->clients->create($clientId, $name, $secret, $scopes, $aud, true);
        $this->auditMutation('client.create_service', $client->clientId, $client->clientId);

        return [
            'ok' => true,
            'client_id' => $client->clientId,
            'name' => $client->name,
            'scopes' => $client->allowedScopes,
            'aud' => $client->defaultAudience,
            'client_secret' => $secret,
        ];
    }

    /** @param list<string> $argv @return array<string, mixed> */
    private function clientRotateSecret(array $argv): array
    {
        $clientId = (string) ($argv[0] ?? '');
        if ($clientId === '') {
            throw new \InvalidArgumentException('Usage: client:rotate-secret <client_id>');
        }
        $secret = $this->clients->rotateSecret($clientId);
        $this->auditMutation('client.rotate_secret', $clientId, $clientId);

        return ['ok' => true, 'client_id' => $clientId, 'client_secret' => $secret];
    }

    /**
     * @param array<string, string> $flags
     * @return array<string, mixed>
     */
    private function tokenList(array $flags): array
    {
        $client = isset($flags['client']) ? (string) $flags['client'] : null;
        $subject = isset($flags['subject']) ? (string) $flags['subject'] : null;
        $kind = isset($flags['kind']) ? (string) $flags['kind'] : null;
        $rows = $this->tokens->listActive($client, $subject, $kind);
        $list = [];
        foreach ($rows as $t) {
            $list[] = $this->tokenRow($t);
        }

        return ['ok' => true, 'tokens' => $list, 'count' => count($list)];
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $flags
     * @return array<string, mixed>
     */
    private function patCreate(array $argv, array $flags): array
    {
        $subject = (string) ($argv[0] ?? '');
        if ($subject === '') {
            throw new \InvalidArgumentException(
                'Usage: pat:create <subject_user_id> --scopes=… [--label=…] [--aud=…] [--ttl-days=365]'
            );
        }
        $this->assertUserExists($subject);
        $scopesRaw = (string) ($flags['scopes'] ?? '');
        $scopes = array_values(array_filter(array_map('trim', explode(',', str_replace(' ', ',', $scopesRaw)))));
        if ($scopes === []) {
            throw new \InvalidArgumentException('--scopes is required (e.g. kb:read)');
        }
        $ttlDays = (int) ($flags['ttl-days'] ?? 365);
        if ($ttlDays < 1 || $ttlDays > 3650) {
            throw new \InvalidArgumentException('--ttl-days must be 1..3650');
        }
        $aud = isset($flags['aud']) && $flags['aud'] !== '' ? (string) $flags['aud'] : null;
        $label = isset($flags['label']) && $flags['label'] !== '' ? (string) $flags['label'] : null;
        $issued = $this->tokens->issuePat(
            $subject,
            implode(' ', $scopes),
            $aud,
            $ttlDays * 86400,
            $label,
        );
        $this->auditMutation('pat.create', $issued['record']->id);

        return [
            'ok' => true,
            'token_id' => $issued['record']->id,
            'kind' => AccessToken::KIND_PAT,
            'subject_user_id' => $subject,
            'scope' => $issued['record']->scope,
            'aud' => $issued['record']->aud,
            'label' => $issued['record']->label,
            'expires_at' => $issued['record']->expiresAt,
            'expires_in' => $issued['expires_in'],
            'token' => $issued['token'],
        ];
    }

    /**
     * @param array<string, string> $flags
     * @return array<string, mixed>
     */
    private function patList(array $flags): array
    {
        $subject = isset($flags['subject']) ? (string) $flags['subject'] : null;
        $rows = $this->tokens->listActive(null, $subject, AccessToken::KIND_PAT);
        $list = [];
        foreach ($rows as $t) {
            $list[] = $this->tokenRow($t);
        }

        return ['ok' => true, 'tokens' => $list, 'count' => count($list)];
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $flags
     * @return array<string, mixed>
     */
    private function patRevoke(array $argv, array $flags): array
    {
        $tokenId = (string) ($argv[0] ?? '');
        $subject = (string) ($flags['subject'] ?? '');
        if ($tokenId === '' && $subject === '') {
            throw new \InvalidArgumentException('Usage: pat:revoke <token_id> | --subject=ID');
        }
        if ($tokenId !== '' && $subject !== '') {
            throw new \InvalidArgumentException('Usage: pat:revoke <token_id> | --subject=ID');
        }
        if ($tokenId !== '') {
            $existing = $this->tokens->findById($tokenId);
            if ($existing !== null && !$existing->isPat()) {
                throw new \InvalidArgumentException('Not a PAT token_id (use token:revoke for access tokens)');
            }
            $count = $this->tokens->revokeById($tokenId);
            $target = 'token_id:' . $tokenId;
        } else {
            $pats = $this->tokens->listActive(null, $subject, AccessToken::KIND_PAT);
            $count = 0;
            foreach ($pats as $pat) {
                $count += $this->tokens->revokeById($pat->id);
            }
            $target = 'subject:' . $subject;
        }
        $this->auditMutation('pat.revoke', $target);

        return ['ok' => true, 'revoked' => $count, 'target' => $target];
    }

    /** @return array<string, mixed> */
    private function tokenRow(AccessToken $t): array
    {
        return [
            'id' => $t->id,
            'kind' => $t->kind,
            'label' => $t->label,
            'client_id' => $t->clientId,
            'subject_user_id' => $t->subjectUserId,
            'scope' => $t->scope,
            'aud' => $t->aud,
            'expires_at' => $t->expiresAt,
            'last_used_at' => $t->lastUsedAt,
        ];
    }

    /**
     * @param list<string> $argv
     * @param array<string, string> $flags
     * @return array<string, mixed>
     */
    private function tokenRevoke(array $argv, array $flags): array
    {
        $tokenId = (string) ($argv[0] ?? '');
        $client = (string) ($flags['client'] ?? '');
        $subject = (string) ($flags['subject'] ?? '');
        $modes = array_filter([$tokenId !== '', $client !== '', $subject !== '']);
        if (count($modes) !== 1) {
            throw new \InvalidArgumentException('Usage: token:revoke <token_id> | --client=ID | --subject=ID');
        }
        if ($tokenId !== '') {
            $count = $this->tokens->revokeById($tokenId);
            $target = 'token_id:' . $tokenId;
            $auditClient = null;
        } elseif ($client !== '') {
            $count = $this->tokens->revokeByClientId($client);
            $target = 'client:' . $client;
            $auditClient = $client;
        } else {
            $count = $this->tokens->revokeBySubjectId($subject);
            $target = 'subject:' . $subject;
            $auditClient = null;
        }
        $this->auditMutation('token.revoke.admin', $target, $auditClient);

        return ['ok' => true, 'revoked' => $count, 'target' => $target];
    }

    private function resolveTenant(string $ref): \GrandpaSSOn\Domain\Tenant
    {
        // Prefer UUID when the ref looks like one, so a slug equal to another id cannot shadow.
        if (preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $ref) === 1) {
            $byId = $this->tenants->findById($ref);
            if ($byId !== null) {
                return $byId;
            }
        }
        $bySlug = $this->tenants->findBySlug($ref);
        if ($bySlug !== null) {
            return $bySlug;
        }
        $byId = $this->tenants->findById($ref);
        if ($byId !== null) {
            return $byId;
        }
        throw new \InvalidArgumentException('Unknown tenant: ' . $ref);
    }

    private function assertUserExists(string $userId): void
    {
        $stmt = $this->pdo->prepare('SELECT 1 FROM users WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $userId]);
        if ($stmt->fetchColumn() === false) {
            throw new \InvalidArgumentException('Unknown subject user_id: ' . $userId);
        }
    }

    private function auditMutation(string $action, string $target, ?string $clientId = null): void
    {
        $this->audit->record(
            action: $action,
            result: AuditLogger::RESULT_SUCCESS,
            actorType: AuditLogger::ACTOR_ADMIN,
            actorId: 'cli',
            target: $target,
            clientId: $clientId,
        );
    }
}
