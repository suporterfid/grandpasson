<?php

declare(strict_types=1);

namespace GrandpaSSOn\Tests\Unit\Domain;

use GrandpaSSOn\Domain\ScopeVocabulary;
use PHPUnit\Framework\TestCase;

final class ScopeVocabularyTest extends TestCase
{
    public function testIncludesTasksWriteAndCallback(): void
    {
        $all = ScopeVocabulary::all();
        $this->assertContains(ScopeVocabulary::TASKS_CALLBACK, $all);
        $this->assertContains(ScopeVocabulary::TASKS_WRITE, $all);
        $this->assertTrue(ScopeVocabulary::isKnown('tasks:write'));
        $this->assertSame(['nope:scope'], ScopeVocabulary::unknown(['tasks:write', 'nope:scope']));
    }

    public function testRecognizesStatusConnectScopes(): void
    {
        $scopes = ['status:read', 'status:write', 'status:callback'];

        $this->assertSame([], ScopeVocabulary::unknown($scopes));
        $this->assertSame(
            ['status:unknown'],
            ScopeVocabulary::unknown([...$scopes, 'status:unknown']),
        );
    }

    public function testMachineScopesIncludeTaskConnect(): void
    {
        $machine = ScopeVocabulary::machineScopes();
        $this->assertContains('tasks:callback', $machine);
        $this->assertContains('tasks:write', $machine);
        $this->assertNotContains('openid', $machine);
    }

    public function testMachineScopesIncludeStatusConnect(): void
    {
        $machine = ScopeVocabulary::machineScopes();

        $this->assertContains(ScopeVocabulary::STATUS_READ, $machine);
        $this->assertContains(ScopeVocabulary::STATUS_WRITE, $machine);
        $this->assertContains(ScopeVocabulary::STATUS_CALLBACK, $machine);
    }

    public function testSelfServiceScopesExcludeTrustedServiceOnlyScopes(): void
    {
        $selfService = ScopeVocabulary::selfServiceScopes();
        $this->assertContains(ScopeVocabulary::KB_READ, $selfService);
        $this->assertContains(ScopeVocabulary::TENANT_READ, $selfService);
        $this->assertNotContains(ScopeVocabulary::KB_WRITE, $selfService);
        $this->assertNotContains(ScopeVocabulary::TASKS_CALLBACK, $selfService);
        $this->assertNotContains(ScopeVocabulary::TASKS_WRITE, $selfService);
    }

    public function testSelfServiceScopesExcludeStatusConnectMachineScopes(): void
    {
        $selfService = ScopeVocabulary::selfServiceScopes();

        $this->assertNotContains(ScopeVocabulary::STATUS_READ, $selfService);
        $this->assertNotContains(ScopeVocabulary::STATUS_WRITE, $selfService);
        $this->assertNotContains(ScopeVocabulary::STATUS_CALLBACK, $selfService);
        $this->assertSame(
            ['status:write'],
            ScopeVocabulary::disallowedForSelfService(['tenant:read', 'status:write']),
        );
    }

    public function testDisallowedForSelfServiceFlagsReservedAndUnknownScopes(): void
    {
        $this->assertSame([], ScopeVocabulary::disallowedForSelfService(['kb:read', 'tenant:read']));
        $this->assertSame(
            ['kb:write', 'nope:scope'],
            ScopeVocabulary::disallowedForSelfService(['kb:read', 'kb:write', 'nope:scope'])
        );
    }
}
