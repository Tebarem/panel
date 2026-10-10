<?php

namespace Pterodactyl\Tests\Unit\Services\Eggs;

use Pterodactyl\Models\Egg;
use Pterodactyl\Tests\TestCase;
use Pterodactyl\Services\Eggs\EggParserService;

class EggParserServiceTest extends TestCase
{
    /**
     * Test that hidden file rules are imported when present in the file.
     */
    public function testHiddenFileRulesAreImported()
    {
        $egg = (new EggParserService())->fillFromParsed(new Egg(), [
            'name' => 'Test',
            'file_hidden' => ['/secrets/', '', '*.key'],
            'file_denylist' => ['/plugins/Licensed.jar'],
        ]);

        $this->assertSame(['/secrets/', '*.key'], $egg->file_hidden);
        $this->assertSame(['/plugins/Licensed.jar'], $egg->file_denylist);
    }

    /**
     * Test that exports made before file_hidden existed do not clear the hidden rules of an
     * egg that is being updated from them.
     */
    public function testMissingHiddenFileRulesDoNotClearExistingRules()
    {
        $egg = new Egg(['file_hidden' => ['/secrets/']]);

        $egg = (new EggParserService())->fillFromParsed($egg, [
            'name' => 'Test',
            'file_denylist' => [],
        ]);

        $this->assertSame(['/secrets/'], $egg->file_hidden);
    }
}
