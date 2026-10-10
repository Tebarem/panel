<?php

namespace Pterodactyl\Tests\Unit\Models;

use Pterodactyl\Tests\TestCase;
use Pterodactyl\Models\DockerRegistry;
use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;

class DockerRegistryValidationTest extends TestCase
{
    /**
     * Registry values that are a host, optionally with a port and a namespace path.
     */
    public static function validRegistryProvider(): array
    {
        return [
            ['ghcr.io'],
            ['ghcr.io/myorg'],
            ['ghcr.io/myorg/team'],
            ['docker.io/myorg'],
            ['registry.example.com:5000'],
            ['localhost:5000/team/images'],
            ['registry.example.com/my-org/sub_team.images'],
        ];
    }

    /**
     * Registry values that contain a scheme, trailing slashes, spaces, upper case or are otherwise malformed.
     */
    public static function invalidRegistryProvider(): array
    {
        return [
            [''],
            ['https://ghcr.io'],
            ['ghcr.io/'],
            ['ghcr.io//my-org'],
            ['ghcr.io/my org'],
            [' ghcr.io'],
            ['ghcr.io:port'],
            ['myorg'],
            ['myorg/team'],
            ["ghcr.io\n"],
            ['GHCR.io/MyOrg'],
        ];
    }

    #[DataProvider('validRegistryProvider')]
    public function testValidRegistryValuesPass(string $registry)
    {
        $this->assertTrue($this->passes($registry));
    }

    #[DataProvider('invalidRegistryProvider')]
    public function testInvalidRegistryValuesFail(string $registry)
    {
        $this->assertFalse($this->passes($registry));
    }

    /**
     * Runs the registry field rules of the model against the given value.
     */
    private function passes(string $registry): bool
    {
        return Validator::make(
            ['registry' => $registry],
            ['registry' => DockerRegistry::getRulesForField('registry')],
        )->passes();
    }
}
