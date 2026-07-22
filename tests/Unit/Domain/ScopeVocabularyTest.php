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
}
