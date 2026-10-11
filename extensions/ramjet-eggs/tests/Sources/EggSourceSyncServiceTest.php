<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Tests\Sources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Ramjet\Eggs\Registries\DockerRegistry;
use Ramjet\Eggs\Registries\EggRegistries;
use Ramjet\Eggs\Rules\EggRules;
use Ramjet\Eggs\Rules\RuleSet;
use Ramjet\Eggs\Sources\EggSource;
use Ramjet\Eggs\Sources\EggSourceClient;
use Ramjet\Eggs\Sources\EggSourceEgg;
use Ramjet\Eggs\Sources\EggSourceSyncService;
use Ramjet\Eggs\Sources\HostResolver;

require_once __DIR__.'/../Support/enables-ramjet-eggs.php';

uses(IntegrationTestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    ramjetEggsEnable();

    // Names resolve from the stub, never from DNS. Private hosts are refused by default; a test opts in explicitly.
    app()->instance(HostResolver::class, Fixtures::resolver());
    $this->app->make(ExtensionRepository::class)->settings('ramjet-eggs')->set('allow_private_hosts', false);
});

test('GitHub eggs are imported with the tag, registry, rules and token', function (): void {
    $registry = DockerRegistry::query()->create([
        'name' => 'Private',
        'registry' => 'ghcr.io/acme',
        'username' => 'robot',
        'password' => 'secret',
    ]);

    $source = Fixtures::source([
        'token' => 'ghp_test_token',
        'docker_registry_id' => $registry->id,
        'tag' => 'minecraft',
    ]);

    Http::fake([
        Fixtures::GITHUB_TREE => Http::response([
            'truncated' => false,
            'tree' => [
                ['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob'],
                ['path' => 'eggs/minecraft/README.md', 'type' => 'blob'],
                ['path' => 'other/egg-c.json', 'type' => 'blob'],
                ['path' => 'eggs/minecraft', 'type' => 'tree'],
            ],
        ]),
        Fixtures::GITHUB_FILE_A => Http::response(Fixtures::eggJson('Minecraft')),
    ]);

    $result = $this->app->make(EggSourceSyncService::class)->handle($source);

    expect($result['error'])->toBeNull()
        ->and($result['files'])->toHaveCount(1)
        ->and($result['files'][0]['status'])->toBe('created');

    $egg = Fixtures::importedEgg($source, 'eggs/minecraft/egg-a.json');
    expect($egg->name)->toBe('Minecraft')
        ->and($this->app->make(EggRules::class)->get($egg)->protected)->toBe(['/plugins/Licensed.jar'])
        ->and($this->app->make(EggRules::class)->get($egg)->hidden)->toBe(['*.key'])
        ->and($this->app->make(EggRegistries::class)->registryFor($egg)?->id)->toBe($registry->id)
        ->and($egg->tags()->pluck('slug')->all())->toBe(['minecraft']);

    Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer ghp_test_token'));

    $source->refresh();
    expect($source->last_synced_at)->not->toBeNull()
        ->and($source->last_sync_error)->toBeNull();
});

test('a second sync updates the same egg instead of creating a duplicate', function (): void {
    $source = Fixtures::source();

    Http::fake([
        Fixtures::GITHUB_TREE => Http::response([
            'truncated' => false,
            'tree' => [['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob']],
        ]),
        Fixtures::GITHUB_FILE_A => Http::response(Fixtures::eggJson('Minecraft')),
    ]);

    $this->app->make(EggSourceSyncService::class)->handle($source);
    $egg = Fixtures::importedEgg($source, 'eggs/minecraft/egg-a.json');

    // Swap the HTTP factory so the new stubs replace the previous ones.
    Http::swap(new HttpFactory());
    Http::fake([
        Fixtures::GITHUB_TREE => Http::response([
            'truncated' => false,
            'tree' => [['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob']],
        ]),
        Fixtures::GITHUB_FILE_A => Http::response(Fixtures::eggJson('Minecraft Renamed')),
    ]);

    $result = $this->app->make(EggSourceSyncService::class)->handle($source);

    expect($result['files'][0]['status'])->toBe('updated')
        ->and(EggSourceEgg::query()->where('source_id', $source->id)->count())->toBe(1)
        ->and($egg->refresh()->name)->toBe('Minecraft Renamed');
});

test('a sync adds to the file rules already on an egg and never removes any of them', function (): void {
    $source = Fixtures::source();

    Http::fake([
        Fixtures::GITHUB_TREE => Http::response([
            'truncated' => false,
            'tree' => [['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob']],
        ]),
        Fixtures::GITHUB_FILE_A => Http::response(Fixtures::eggJson('Minecraft')),
    ]);

    $this->app->make(EggSourceSyncService::class)->handle($source);
    $egg = Fixtures::importedEgg($source, 'eggs/minecraft/egg-a.json');

    // An admin adds rules in the panel, then the repository ships a different set.
    $this->app->make(EggRules::class)->set($egg, new RuleSet(
        ['*.key', '/keep-hidden/'],
        ['/plugins/Licensed.jar', '/panel-only/'],
    ));

    Http::swap(new HttpFactory());
    Http::fake([
        Fixtures::GITHUB_TREE => Http::response([
            'truncated' => false,
            'tree' => [['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob']],
        ]),
        Fixtures::GITHUB_FILE_A => Http::response(Fixtures::eggJson('Minecraft', ['/plugins/New.jar', '/plugins/Licensed.jar'], ['*.pem'])),
    ]);

    $this->app->make(EggSourceSyncService::class)->handle($source);

    $rules = $this->app->make(EggRules::class)->get($egg->refresh());
    expect($rules->protected)->toBe(['/plugins/Licensed.jar', '/panel-only/', '/plugins/New.jar'])
        ->and($rules->hidden)->toBe(['*.key', '/keep-hidden/', '*.pem']);
});

test('a truncated GitHub tree is reported and nothing is imported', function (): void {
    $source = Fixtures::source(['path' => '']);

    Http::fake([
        Fixtures::GITHUB_TREE => Http::response(['truncated' => true, 'tree' => []]),
    ]);

    $result = $this->app->make(EggSourceSyncService::class)->handle($source);

    expect((string) $result['error'])->toContain('truncated')
        ->and($result['files'])->toBe([])
        ->and(EggSourceEgg::query()->where('source_id', $source->id)->count())->toBe(0);

    $source->refresh();
    expect($source->last_synced_at)->not->toBeNull()
        ->and((string) $source->last_sync_error)->toContain('truncated');
});

test('a failure for one file does not stop the other files from being imported', function (): void {
    $source = Fixtures::source();

    Http::fake([
        Fixtures::GITHUB_TREE => Http::response([
            'truncated' => false,
            'tree' => [
                ['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob'],
                ['path' => 'eggs/minecraft/egg-b.json', 'type' => 'blob'],
            ],
        ]),
        Fixtures::GITHUB_FILE_A => Http::response(Fixtures::eggJson('Minecraft')),
        Fixtures::GITHUB_FILE_B => Http::response('not found', 404),
    ]);

    $result = $this->app->make(EggSourceSyncService::class)->handle($source);

    $statuses = array_column($result['files'], 'status', 'path');
    expect($statuses['eggs/minecraft/egg-a.json'])->toBe('created')
        ->and($statuses['eggs/minecraft/egg-b.json'])->toBe('failed')
        ->and((string) $result['error'])->toContain('1 of 2 file(s) failed')
        ->and(EggSourceEgg::query()->where('source_id', $source->id)->count())->toBe(1);
});

test('JSON files that are not egg exports are skipped rather than reported as errors', function (): void {
    $source = Fixtures::source();

    Http::fake([
        Fixtures::GITHUB_TREE => Http::response([
            'truncated' => false,
            'tree' => [
                ['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob'],
                ['path' => 'eggs/minecraft/egg-b.json', 'type' => 'blob'],
            ],
        ]),
        Fixtures::GITHUB_FILE_A => Http::response(Fixtures::eggJson('Minecraft')),
        Fixtures::GITHUB_FILE_B => Http::response(['name' => 'not an egg']),
    ]);

    $result = $this->app->make(EggSourceSyncService::class)->handle($source);

    $statuses = array_column($result['files'], 'status', 'path');
    expect($statuses['eggs/minecraft/egg-a.json'])->toBe('created')
        ->and($statuses['eggs/minecraft/egg-b.json'])->toBe('skipped')
        ->and($result['error'])->toBeNull();
});

test('redirects are not followed, so the token is never sent to another host', function (): void {
    $source = Fixtures::source(['path' => '', 'token' => 'ghp_test_token']);

    Http::fake([
        Fixtures::GITHUB_TREE => Http::response('', 302, ['Location' => 'https://evil.test/steal']),
    ]);

    $result = $this->app->make(EggSourceSyncService::class)->handle($source);

    expect((string) $result['error'])->toContain('redirect')
        ->and(EggSourceEgg::query()->where('source_id', $source->id)->count())->toBe(0);
    Http::assertSentCount(1);
});

test('a location with too many JSON files is refused', function (): void {
    $source = Fixtures::source(['path' => 'eggs']);

    $tree = [];
    for ($i = 0; $i <= EggSourceClient::MAX_FILES; $i++) {
        $tree[] = ['path' => "eggs/egg-$i.json", 'type' => 'blob'];
    }

    Http::fake([
        Fixtures::GITHUB_TREE => Http::response(['truncated' => false, 'tree' => $tree]),
    ]);

    $result = $this->app->make(EggSourceSyncService::class)->handle($source);

    expect((string) $result['error'])->toContain('limit')
        ->and($result['files'])->toBe([])
        ->and(EggSourceEgg::query()->where('source_id', $source->id)->count())->toBe(0);
});

test('files above the size limit are not imported', function (): void {
    $url = 'https://example.com/eggs/huge.json';
    $source = Fixtures::source(['type' => EggSource::TYPE_URL, 'path' => $url, 'repository' => null, 'branch' => null]);

    Http::fake([
        $url => Http::response(str_repeat('a', EggSourceClient::MAX_FILE_BYTES + 1)),
    ]);

    $result = $this->app->make(EggSourceSyncService::class)->handle($source);

    expect($result['files'][0]['status'])->toBe('failed')
        ->and((string) $result['files'][0]['message'])->toContain('1 MiB')
        ->and(EggSourceEgg::query()->where('source_id', $source->id)->count())->toBe(0);
});

test('the link-local metadata address is never requested, even when private hosts are allowed', function (): void {
    $this->app->make(ExtensionRepository::class)
        ->settings('ramjet-eggs')
        ->set('allow_private_hosts', true);

    $source = Fixtures::source([
        'type' => EggSource::TYPE_URL,
        'path' => 'http://169.254.169.254/latest/meta-data/egg.json',
        'repository' => null,
        'branch' => null,
    ]);

    $result = $this->app->make(EggSourceSyncService::class)->handle($source);

    expect($result['error'])->not->toBeNull();
    Http::assertNothingSent();
});

test('private addresses are refused by default', function (): void {
    $source = Fixtures::source([
        'type' => EggSource::TYPE_URL,
        'path' => 'https://10.0.0.5/egg.json',
        'repository' => null,
        'branch' => null,
    ]);

    $result = $this->app->make(EggSourceSyncService::class)->handle($source);

    expect((string) $result['error'])->toContain('Private');
    Http::assertNothingSent();
});

test('private addresses are reachable when the extension setting allows them', function (): void {
    $this->app->make(ExtensionRepository::class)
        ->settings('ramjet-eggs')
        ->set('allow_private_hosts', true);

    Http::fake([
        'https://10.0.0.5/egg.json' => Http::response(Fixtures::eggJson('Internal GitLab')),
    ]);

    $source = Fixtures::source([
        'type' => EggSource::TYPE_URL,
        'path' => 'https://10.0.0.5/egg.json',
        'repository' => null,
        'branch' => null,
    ]);

    $result = $this->app->make(EggSourceSyncService::class)->handle($source);

    expect($result['error'])->toBeNull()
        ->and($result['files'][0]['status'])->toBe('created');
});

test('GitLab trees are paginated and files are downloaded with the private token header', function (): void {
    $source = Fixtures::source([
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
        'https://gitlab.example.com/api/v4/projects/acme%2Feggs/repository/files/*' => Http::response(Fixtures::eggJson('GitLab Egg')),
    ]);

    $result = $this->app->make(EggSourceSyncService::class)->handle($source);

    expect($result['error'])->toBeNull()
        ->and($result['files'])->toHaveCount(2)
        ->and(EggSourceEgg::query()->where('source_id', $source->id)->count())->toBe(2);

    Http::assertSent(fn ($request) => $request->hasHeader('PRIVATE-TOKEN', 'glpat-test'));
});

test('the URL type imports a single egg file', function (): void {
    $url = 'https://example.com/eggs/minecraft.json';
    $source = Fixtures::source(['type' => EggSource::TYPE_URL, 'path' => $url, 'repository' => null, 'branch' => null, 'tag' => 'minecraft']);

    Http::fake([
        $url => Http::response(Fixtures::eggJson('Single File')),
    ]);

    $result = $this->app->make(EggSourceSyncService::class)->handle($source);

    expect($result['error'])->toBeNull()
        ->and($result['files'][0]['status'])->toBe('created');

    $egg = Fixtures::importedEgg($source, $url);
    expect($egg->name)->toBe('Single File')
        ->and($egg->tags()->pluck('slug')->all())->toBe(['minecraft']);
});

test('a token is never sent to a plain http address', function (): void {
    $source = Fixtures::source([
        'type' => EggSource::TYPE_URL,
        'path' => 'http://example.com/eggs/minecraft.json',
        'repository' => null,
        'branch' => null,
        'token' => 'secret-token',
    ]);

    $result = $this->app->make(EggSourceSyncService::class)->handle($source);

    expect((string) $result['error'])->toContain('https')
        ->and(EggSourceEgg::query()->where('source_id', $source->id)->count())->toBe(0);
    Http::assertNothingSent();
});

test('addresses with a user name or password are refused', function (): void {
    $source = Fixtures::source([
        'type' => EggSource::TYPE_URL,
        'path' => 'https://user:pass@example.com/eggs/minecraft.json',
        'repository' => null,
        'branch' => null,
    ]);

    $result = $this->app->make(EggSourceSyncService::class)->handle($source);

    expect($result['files'])->toBe([])
        ->and((string) $result['error'])->toContain('user name or password');
    Http::assertNothingSent();
});

test('stored error messages never contain the query string of a request', function (): void {
    $source = Fixtures::source();

    Http::fake([
        Fixtures::GITHUB_TREE => Http::response([
            'truncated' => false,
            'tree' => [['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob']],
        ]),
        Fixtures::GITHUB_FILE_A => Http::response('missing', 404),
    ]);

    $result = $this->app->make(EggSourceSyncService::class)->handle($source);

    $message = (string) $result['files'][0]['message'];
    expect($result['files'][0]['status'])->toBe('failed')
        ->and($message)->not->toContain('?')
        ->and($message)->not->toContain('ref=main');

    $source->refresh();
    expect((string) $source->last_sync_error)->not->toContain('ref=main');
});

test('a JSON file without meta.version is skipped without being imported', function (): void {
    $source = Fixtures::source(['path' => 'eggs']);

    Http::fake([
        Fixtures::GITHUB_TREE => Http::response([
            'truncated' => false,
            'tree' => [['path' => 'eggs/package.json', 'type' => 'blob']],
        ]),
        'https://api.github.com/repos/acme/eggs/contents/eggs/package.json*' => Http::response(['meta' => ['name' => 'x']]),
    ]);

    $result = $this->app->make(EggSourceSyncService::class)->handle($source);

    expect($result['files'][0]['status'])->toBe('skipped')
        ->and($result['error'])->toBeNull();
});
