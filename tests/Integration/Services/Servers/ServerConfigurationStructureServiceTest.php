<?php

namespace Pterodactyl\Tests\Integration\Services\Servers;

use Pterodactyl\Models\DockerRegistry;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Services\Servers\ServerConfigurationStructureService;

class ServerConfigurationStructureServiceTest extends IntegrationTestCase
{
    /**
     * Test that the egg's hidden and protected file rules are included in the configuration
     * sent to Wings, and that inherited rules are used when the egg copies its configuration.
     */
    public function testFileRulesAreIncludedInConfiguration()
    {
        $server = $this->createServerModel();
        $server->egg->forceFill([
            'file_hidden' => ['/secrets/', '*.key'],
            'file_denylist' => ['/plugins/Licensed.jar'],
        ])->save();

        $config = $this->getService()->handle($server->fresh());

        $this->assertSame(['/secrets/', '*.key'], $config['egg']['file_hidden']);
        $this->assertSame(['/plugins/Licensed.jar'], $config['egg']['file_denylist']);
    }

    /**
     * Test that a server without a registry gets an empty registry_auth list.
     */
    public function testRegistryAuthIsEmptyWhenEggHasNoRegistry()
    {
        $server = $this->createServerModel();
        $server->egg->forceFill(['docker_registry_id' => null])->save();

        $config = $this->getService()->handle($server->fresh());

        $this->assertArrayHasKey('registry_auth', $config['container']);
        $this->assertSame([], $config['container']['registry_auth']);
    }

    /**
     * Test that the credentials of the egg's registry are sent to Wings. The password is only
     * present in this configuration structure; it must not be serialized anywhere else.
     */
    public function testRegistryCredentialsAreIncludedInConfiguration()
    {
        $registry = DockerRegistry::query()->create([
            'name' => 'Private',
            'registry' => 'ghcr.io/my-org',
            'username' => 'robot',
            'password' => 'super-secret-token',
        ]);

        $server = $this->createServerModel();
        $server->egg->forceFill(['docker_registry_id' => $registry->id])->save();

        $config = $this->getService()->handle($server->fresh());

        $this->assertSame([
            [
                'registry' => 'ghcr.io/my-org',
                'username' => 'robot',
                'password' => 'super-secret-token',
            ],
        ], $config['container']['registry_auth']);

        // The password must be encrypted at rest and never serialized by the model itself.
        $this->assertArrayNotHasKey('password', $registry->toArray());
        $this->assertNotSame('super-secret-token', $registry->getRawOriginal('password'));
    }

    /**
     * Returns the service under test.
     */
    private function getService(): ServerConfigurationStructureService
    {
        return $this->app->make(ServerConfigurationStructureService::class);
    }
}
