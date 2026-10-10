<?php

namespace Pterodactyl\Tests\Integration\Services\Eggs\Sources;

use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\EggSource;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\DockerRegistry;
use Illuminate\Http\Client\Factory as HttpFactory;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Services\Eggs\Sources\EggSourceSyncService;

class EggSourceSyncServiceTest extends IntegrationTestCase
{
    private const GITHUB_TREE = 'https://api.github.com/repos/acme/eggs/git/trees/main*';
    private const GITHUB_FILE_A = 'https://api.github.com/repos/acme/eggs/contents/eggs/minecraft/egg-a.json*';
    private const GITHUB_FILE_B = 'https://api.github.com/repos/acme/eggs/contents/eggs/minecraft/egg-b.json*';

    /**
     * Test that GitHub JSON files are imported, that the token is sent, and that a second sync
     * updates the existing egg instead of creating a duplicate.
     */
    public function testGitHubSourceImportsAndUpdatesEggs()
    {
        $registry = DockerRegistry::query()->create([
            'name' => 'Private',
            'registry' => 'ghcr.io/acme',
            'username' => 'robot',
            'password' => 'secret',
        ]);

        $source = $this->createSource([
            'type' => EggSource::TYPE_GITHUB,
            'repository' => 'acme/eggs',
            'branch' => 'main',
            'path' => 'eggs/minecraft',
            'token' => 'ghp_test_token',
            'docker_registry_id' => $registry->id,
        ]);

        Http::fake([
            self::GITHUB_TREE => Http::response([
                'truncated' => false,
                'tree' => [
                    ['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob'],
                    ['path' => 'eggs/minecraft/README.md', 'type' => 'blob'],
                    ['path' => 'other/egg-c.json', 'type' => 'blob'],
                    ['path' => 'eggs/minecraft', 'type' => 'tree'],
                ],
            ]),
            self::GITHUB_FILE_A => Http::response($this->eggJson('Minecraft')),
        ]);

        $result = $this->getService()->handle($source);

        $this->assertNull($result['error']);
        $this->assertCount(1, $result['files']);
        $this->assertSame('created', $result['files'][0]['status']);

        $egg = Egg::query()->where('egg_source_id', $source->id)->firstOrFail();
        $this->assertSame('eggs/minecraft/egg-a.json', $egg->egg_source_path);
        $this->assertSame($source->nest_id, $egg->nest_id);
        $this->assertSame($registry->id, $egg->docker_registry_id);
        $this->assertSame(['/plugins/Licensed.jar'], $egg->file_denylist);
        $this->assertSame(['*.key'], $egg->file_hidden);

        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ghp_test_token'));

        $source->refresh();
        $this->assertNotNull($source->last_synced_at);
        $this->assertNull($source->last_sync_error);

        // Second sync with a changed file: the same egg must be updated. Swap the HTTP factory so the
        // new stubs replace the previous ones.
        Http::swap(new HttpFactory());
        Http::fake([
            self::GITHUB_TREE => Http::response([
                'truncated' => false,
                'tree' => [['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob']],
            ]),
            self::GITHUB_FILE_A => Http::response($this->eggJson('Minecraft Renamed')),
        ]);

        $result = $this->getService()->handle($source);

        $this->assertSame('updated', $result['files'][0]['status']);
        $this->assertSame(1, Egg::query()->where('egg_source_id', $source->id)->count());
        $this->assertSame('Minecraft Renamed', $egg->refresh()->name);
    }

    /**
     * Test that a sync adds to the file rules already on an egg and never removes any of them.
     */
    public function testSyncUnionsFileRulesWithExistingRules()
    {
        $source = $this->createSource([
            'type' => EggSource::TYPE_GITHUB,
            'repository' => 'acme/eggs',
            'branch' => 'main',
            'path' => 'eggs/minecraft',
        ]);

        Http::fake([
            self::GITHUB_TREE => Http::response([
                'truncated' => false,
                'tree' => [['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob']],
            ]),
            self::GITHUB_FILE_A => Http::response($this->eggJson('Minecraft')),
        ]);

        $this->getService()->handle($source);

        $egg = Egg::query()->where('egg_source_id', $source->id)->firstOrFail();

        // An admin adds rules in the panel, then the repository ships a different set.
        $egg->forceFill([
            'file_denylist' => ['/plugins/Licensed.jar', '/panel-only/'],
            'file_hidden' => ['*.key', '/keep-hidden/'],
        ])->save();

        Http::swap(new HttpFactory());
        Http::fake([
            self::GITHUB_TREE => Http::response([
                'truncated' => false,
                'tree' => [['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob']],
            ]),
            self::GITHUB_FILE_A => Http::response($this->eggJson('Minecraft', ['/plugins/New.jar', '/plugins/Licensed.jar'], ['*.pem'])),
        ]);

        $this->getService()->handle($source);

        $egg->refresh();
        $this->assertSame(['/plugins/Licensed.jar', '/panel-only/', '/plugins/New.jar'], $egg->file_denylist);
        $this->assertSame(['*.key', '/keep-hidden/', '*.pem'], $egg->file_hidden);
    }

    /**
     * Test that a truncated GitHub tree is reported as an error and nothing is imported.
     */
    public function testTruncatedGitHubTreeIsReported()
    {
        $source = $this->createSource([
            'type' => EggSource::TYPE_GITHUB,
            'repository' => 'acme/eggs',
            'branch' => 'main',
            'path' => '',
        ]);

        Http::fake([
            self::GITHUB_TREE => Http::response(['truncated' => true, 'tree' => []]),
        ]);

        $result = $this->getService()->handle($source);

        $this->assertStringContainsString('truncated', (string) $result['error']);
        $this->assertSame([], $result['files']);
        $this->assertSame(0, Egg::query()->where('egg_source_id', $source->id)->count());

        $source->refresh();
        $this->assertNotNull($source->last_synced_at);
        $this->assertStringContainsString('truncated', (string) $source->last_sync_error);
    }

    /**
     * Test that a failure for one file does not stop the other files from being imported.
     */
    public function testPerFileFailuresAreCollected()
    {
        $source = $this->createSource([
            'type' => EggSource::TYPE_GITHUB,
            'repository' => 'acme/eggs',
            'branch' => 'main',
            'path' => 'eggs/minecraft',
        ]);

        Http::fake([
            self::GITHUB_TREE => Http::response([
                'truncated' => false,
                'tree' => [
                    ['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob'],
                    ['path' => 'eggs/minecraft/egg-b.json', 'type' => 'blob'],
                ],
            ]),
            self::GITHUB_FILE_A => Http::response($this->eggJson('Minecraft')),
            self::GITHUB_FILE_B => Http::response('not found', 404),
        ]);

        $result = $this->getService()->handle($source);

        $statuses = array_column($result['files'], 'status', 'path');
        $this->assertSame('created', $statuses['eggs/minecraft/egg-a.json']);
        $this->assertSame('failed', $statuses['eggs/minecraft/egg-b.json']);
        $this->assertStringContainsString('1 of 2 file(s) failed', (string) $result['error']);
        $this->assertSame(1, Egg::query()->where('egg_source_id', $source->id)->count());
    }

    /**
     * Test that JSON files which are not egg exports are skipped rather than reported as errors.
     */
    public function testNonEggJsonFilesAreSkipped()
    {
        $source = $this->createSource([
            'type' => EggSource::TYPE_GITHUB,
            'repository' => 'acme/eggs',
            'branch' => 'main',
            'path' => 'eggs/minecraft',
        ]);

        Http::fake([
            self::GITHUB_TREE => Http::response([
                'truncated' => false,
                'tree' => [
                    ['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob'],
                    ['path' => 'eggs/minecraft/egg-b.json', 'type' => 'blob'],
                ],
            ]),
            self::GITHUB_FILE_A => Http::response($this->eggJson('Minecraft')),
            self::GITHUB_FILE_B => Http::response(['name' => 'not an egg']),
        ]);

        $result = $this->getService()->handle($source);

        $statuses = array_column($result['files'], 'status', 'path');
        $this->assertSame('created', $statuses['eggs/minecraft/egg-a.json']);
        $this->assertSame('skipped', $statuses['eggs/minecraft/egg-b.json']);
        $this->assertNull($result['error']);
    }

    /**
     * Test that redirects are not followed, so the token is never sent to another host.
     */
    public function testRedirectsAreNotFollowed()
    {
        $source = $this->createSource([
            'type' => EggSource::TYPE_GITHUB,
            'repository' => 'acme/eggs',
            'branch' => 'main',
            'path' => '',
            'token' => 'ghp_test_token',
        ]);

        Http::fake([
            self::GITHUB_TREE => Http::response('', 302, ['Location' => 'https://evil.test/steal']),
        ]);

        $result = $this->getService()->handle($source);

        $this->assertStringContainsString('redirect', (string) $result['error']);
        $this->assertSame(0, Egg::query()->where('egg_source_id', $source->id)->count());
        Http::assertSentCount(1);
    }

    /**
     * Test that a location with too many JSON files is refused.
     */
    public function testTooManyFilesAreRefused()
    {
        $source = $this->createSource([
            'type' => EggSource::TYPE_GITHUB,
            'repository' => 'acme/eggs',
            'branch' => 'main',
            'path' => 'eggs',
        ]);

        $tree = [];
        for ($i = 0; $i <= EggSourceSyncService::MAX_FILES; ++$i) {
            $tree[] = ['path' => "eggs/egg-$i.json", 'type' => 'blob'];
        }

        Http::fake([
            self::GITHUB_TREE => Http::response(['truncated' => false, 'tree' => $tree]),
        ]);

        $result = $this->getService()->handle($source);

        $this->assertStringContainsString('limit', (string) $result['error']);
        $this->assertSame([], $result['files']);
        $this->assertSame(0, Egg::query()->where('egg_source_id', $source->id)->count());
    }

    /**
     * Test that files above the size limit are not imported.
     */
    public function testOversizedFilesAreRefused()
    {
        $url = 'https://example.com/eggs/huge.json';
        $source = $this->createSource([
            'type' => EggSource::TYPE_URL,
            'path' => $url,
        ]);

        Http::fake([
            $url => Http::response(str_repeat('a', EggSourceSyncService::MAX_FILE_BYTES + 1)),
        ]);

        $result = $this->getService()->handle($source);

        $this->assertSame('failed', $result['files'][0]['status']);
        $this->assertStringContainsString('1 MiB', (string) $result['files'][0]['message']);
        $this->assertSame(0, Egg::query()->where('egg_source_id', $source->id)->count());
    }

    /**
     * Test that the link-local metadata address is never requested, even when private hosts are allowed.
     */
    public function testMetadataAddressIsBlocked()
    {
        config()->set('pterodactyl.egg_sources.allow_private_hosts', true);

        $source = $this->createSource([
            'type' => EggSource::TYPE_URL,
            'path' => 'http://169.254.169.254/latest/meta-data/egg.json',
        ]);

        $result = $this->getService()->handle($source);

        $this->assertNotNull($result['error']);
        Http::assertNothingSent();
    }

    /**
     * Test that private addresses are refused when the setting disables them.
     */
    public function testPrivateAddressesAreRefusedWhenDisabled()
    {
        config()->set('pterodactyl.egg_sources.allow_private_hosts', false);

        $source = $this->createSource([
            'type' => EggSource::TYPE_URL,
            'path' => 'https://10.0.0.5/egg.json',
        ]);

        $result = $this->getService()->handle($source);

        $this->assertStringContainsString('Private', (string) $result['error']);
        Http::assertNothingSent();
    }

    /**
     * Test that GitLab trees are paginated and files are downloaded with the private token header.
     */
    public function testGitLabSourceFollowsPagination()
    {
        $source = $this->createSource([
            'type' => EggSource::TYPE_GITLAB,
            'base_url' => 'https://gitlab.example.com',
            'repository' => 'acme/eggs',
            'branch' => 'main',
            'path' => 'eggs',
            'token' => 'glpat-test',
        ]);

        Http::fake([
            'https://gitlab.example.com/api/v4/projects/acme%2Feggs/repository/tree*' => Http::sequence()
                ->push([['path' => 'eggs/egg-a.json', 'type' => 'blob']], 200, ['X-Next-Page' => '2'])
                ->push([['path' => 'eggs/egg-b.json', 'type' => 'blob']], 200, ['X-Next-Page' => '']),
            'https://gitlab.example.com/api/v4/projects/acme%2Feggs/repository/files/*' => Http::response($this->eggJson('GitLab Egg')),
        ]);

        $result = $this->getService()->handle($source);

        $this->assertNull($result['error']);
        $this->assertCount(2, $result['files']);
        $this->assertSame(2, Egg::query()->where('egg_source_id', $source->id)->count());

        Http::assertSent(fn ($request) => $request->hasHeader('PRIVATE-TOKEN', 'glpat-test'));
    }

    /**
     * Test that the URL type imports a single egg file.
     */
    public function testUrlSourceImportsSingleFile()
    {
        $url = 'https://example.com/eggs/minecraft.json';
        $source = $this->createSource([
            'type' => EggSource::TYPE_URL,
            'path' => $url,
        ]);

        Http::fake([
            $url => Http::response($this->eggJson('Single File')),
        ]);

        $result = $this->getService()->handle($source);

        $this->assertNull($result['error']);
        $this->assertSame('created', $result['files'][0]['status']);
        $this->assertDatabaseHas('eggs', ['egg_source_id' => $source->id, 'egg_source_path' => $url, 'name' => 'Single File']);
    }

    /**
     * Creates an egg source with sensible defaults, overridden by the given attributes.
     */
    private function createSource(array $attributes): EggSource
    {
        /** @var EggSource $source */
        $source = EggSource::query()->create(array_merge([
            'name' => 'Test Source',
            'type' => EggSource::TYPE_GITHUB,
            'nest_id' => Nest::query()->firstOrFail()->id,
        ], $attributes));

        return $source;
    }

    /**
     * Returns the JSON for a valid PTDL_v2 egg with the given name.
     *
     * @param string[] $denylist
     * @param string[] $hidden
     */
    private function eggJson(string $name, array $denylist = ['/plugins/Licensed.jar'], array $hidden = ['*.key']): string
    {
        return json_encode([
            'meta' => ['version' => 'PTDL_v2', 'update_url' => null],
            'name' => $name,
            'author' => 'eggs@example.com',
            'description' => 'Synced from an egg source',
            'features' => null,
            'docker_images' => ['ghcr.io/test/yolk' => 'ghcr.io/test/yolk'],
            'file_denylist' => $denylist,
            'file_hidden' => $hidden,
            'startup' => 'java -jar server.jar',
            'config' => [
                'files' => '{}',
                'startup' => '{}',
                'logs' => '{}',
                'stop' => 'stop',
            ],
            'scripts' => [
                'installation' => [
                    'script' => '#!/bin/bash',
                    'container' => 'alpine:3.18',
                    'entrypoint' => 'bash',
                ],
            ],
            'variables' => [],
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * Returns the service under test.
     */
    private function getService(): EggSourceSyncService
    {
        return $this->app->make(EggSourceSyncService::class);
    }
}
