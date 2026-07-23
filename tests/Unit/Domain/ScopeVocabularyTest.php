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

    public function testMachineScopesIncludeTaskConnect(): void
    {
        $machine = ScopeVocabulary::machineScopes();
        $this->assertContains('tasks:callback', $machine);
        $this->assertContains('tasks:write', $machine);
        $this->assertNotContains('openid', $machine);
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

    public function testDisallowedForSelfServiceFlagsReservedAndUnknownScopes(): void
    {
        $this->assertSame([], ScopeVocabulary::disallowedForSelfService(['kb:read', 'tenant:read']));
        $this->assertSame(
            ['kb:write', 'nope:scope'],
            ScopeVocabulary::disallowedForSelfService(['kb:read', 'kb:write', 'nope:scope'])
        );
    }
}
