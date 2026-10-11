<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Tests\Sources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pterodactyl\Models\ActivityLog;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;
use Ramjet\Eggs\Sources\EggSource;
use Ramjet\Eggs\Sources\HostResolver;
use Ramjet\Eggs\Sources\Jobs\SyncEggSourceJob;

require_once __DIR__.'/../Support/enables-ramjet-eggs.php';

uses(AdminApiIntegrationTestCase::class, DatabaseTransactions::class);

const RAMJET_EGG_SOURCES_API = '/api/admin/extensions/ramjet-eggs/sources';

beforeEach(function (): void {
    // The real provider routes, admin middleware and session guard are in use: only the name resolver is replaced.
    ramjetEggsEnable();
    app()->instance(HostResolver::class, Fixtures::resolver());
});

test('non-administrators are refused by the admin API', function (): void {
    $this->actingAsNonAdmin();

    $this->getJson(RAMJET_EGG_SOURCES_API)->assertForbidden();
    $this->postJson(RAMJET_EGG_SOURCES_API, ['name' => 'x', 'type' => EggSource::TYPE_URL, 'path' => 'https://example.test/x.json'])
        ->assertForbidden();
});

test('an administrator creates a source; the token is never returned and the change is logged without it', function (): void {
    $response = $this->postJson(RAMJET_EGG_SOURCES_API, [
        'name' => 'Minecraft',
        'type' => EggSource::TYPE_GITHUB,
        'repository' => 'acme/eggs',
        'branch' => 'main',
        'path' => 'eggs/minecraft/',
        'token' => 'ghp_secret',
        'tag' => 'minecraft',
    ]);

    $response->assertCreated()
        ->assertJsonPath('data.path', 'eggs/minecraft')
        ->assertJsonPath('data.has_token', true)
        ->assertJsonMissingPath('data.token');
    expect($response->getContent())->not->toContain('ghp_secret');

    $log = ActivityLog::query()->where('event', 'egg-source:create')->firstOrFail();
    $properties = json_decode((string) json_encode($log->properties), true);
    expect(json_encode($log->properties))->not->toContain('ghp_secret')
        ->and($properties['name'])->toBe('Minecraft')
        ->and($properties['has_token'])->toBeTrue();
});

test('an access token cannot be sent to an http address', function (): void {
    $this->postJson(RAMJET_EGG_SOURCES_API, [
        'name' => 'Plain',
        'type' => EggSource::TYPE_GITLAB,
        'base_url' => 'http://gitlab.example.com',
        'repository' => 'acme/eggs',
        'branch' => 'main',
        'token' => 'glpat-secret',
    ])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'base_url');

    $this->postJson(RAMJET_EGG_SOURCES_API, [
        'name' => 'Plain URL',
        'type' => EggSource::TYPE_URL,
        'path' => 'http://example.com/egg.json',
        'token' => 'secret',
    ])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'path');

    expect(EggSource::query()->count())->toBe(0);
});

test('URLs with a user name or password, or a token in the query, are refused', function (): void {
    $this->postJson(RAMJET_EGG_SOURCES_API, [
        'name' => 'Credentials',
        'type' => EggSource::TYPE_URL,
        'path' => 'https://user:pass@example.com/egg.json',
    ])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'path');

    $this->postJson(RAMJET_EGG_SOURCES_API, [
        'name' => 'Query token',
        'type' => EggSource::TYPE_URL,
        'path' => 'https://example.com/egg.json?access_token=abc',
    ])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'path');
});

test('per-type rules are enforced for repositories, branches and directories', function (): void {
    $this->postJson(RAMJET_EGG_SOURCES_API, [
        'name' => 'Bad GitHub',
        'type' => EggSource::TYPE_GITHUB,
        'repository' => 'acme/eggs/extra',
        'branch' => 'main',
    ])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'repository');

    $this->postJson(RAMJET_EGG_SOURCES_API, [
        'name' => 'No branch',
        'type' => EggSource::TYPE_GITLAB,
        'repository' => 'group/project',
    ])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'branch');

    $this->postJson(RAMJET_EGG_SOURCES_API, [
        'name' => 'Bad branch',
        'type' => EggSource::TYPE_GITHUB,
        'repository' => 'acme/eggs',
        'branch' => 'main?x',
    ])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'branch');

    $this->postJson(RAMJET_EGG_SOURCES_API, [
        'name' => 'Dot segment',
        'type' => EggSource::TYPE_GITHUB,
        'repository' => 'acme/..',
        'branch' => 'main',
    ])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'repository');

    $this->postJson(RAMJET_EGG_SOURCES_API, [
        'name' => 'Dot directory',
        'type' => EggSource::TYPE_GITHUB,
        'repository' => 'acme/eggs',
        'branch' => 'main',
        'path' => 'eggs/../other',
    ])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'path');

    $this->postJson(RAMJET_EGG_SOURCES_API, [
        'name' => 'GitLab subgroups',
        'type' => EggSource::TYPE_GITLAB,
        'repository' => 'group/subgroup/project',
        'branch' => 'main',
    ])->assertCreated();
});

test('a numeric tag is refused', function (): void {
    $this->postJson(RAMJET_EGG_SOURCES_API, [
        'name' => 'Numeric tag',
        'type' => EggSource::TYPE_GITHUB,
        'repository' => 'acme/eggs',
        'branch' => 'main',
        'tag' => '42',
    ])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'tag');
});

test('a stored token is kept when the form leaves it blank, and removed on request', function (): void {
    $source = Fixtures::source(['token' => 'ghp_keep']);

    $this->patchJson(RAMJET_EGG_SOURCES_API.'/'.$source->id, [
        'name' => 'Renamed',
        'type' => EggSource::TYPE_GITHUB,
        'repository' => 'acme/eggs',
        'branch' => 'main',
        'path' => 'eggs/minecraft',
        'token' => '',
    ])->assertOk()->assertJsonPath('data.has_token', true)->assertJsonPath('data.name', 'Renamed');

    expect($source->refresh()->token)->toBe('ghp_keep');

    $this->patchJson(RAMJET_EGG_SOURCES_API.'/'.$source->id, [
        'name' => 'Renamed',
        'type' => EggSource::TYPE_GITHUB,
        'repository' => 'acme/eggs',
        'branch' => 'main',
        'path' => 'eggs/minecraft',
        'clear_token' => true,
    ])->assertOk()->assertJsonPath('data.has_token', false);

    expect($source->refresh()->hasToken())->toBeFalse();
});

test('changing the origin of a URL source requires the token to be entered again; the same origin keeps it', function (): void {
    $source = Fixtures::source([
        'type' => EggSource::TYPE_URL,
        'path' => 'https://example.com/a.json',
        'repository' => null,
        'branch' => null,
        'token' => 'secret-token',
    ]);

    // A different port is a different destination for the token.
    $this->patchJson(RAMJET_EGG_SOURCES_API.'/'.$source->id, [
        'name' => 'Test Source',
        'type' => EggSource::TYPE_URL,
        'path' => 'https://example.com:8443/a.json',
    ])->assertUnprocessable()->assertJsonPath('errors.0.meta.source_field', 'token');

    expect($source->refresh()->token)->toBe('secret-token');

    // Another file on the same origin keeps the token.
    $this->patchJson(RAMJET_EGG_SOURCES_API.'/'.$source->id, [
        'name' => 'Test Source',
        'type' => EggSource::TYPE_URL,
        'path' => 'https://example.com/b.json',
    ])->assertOk()->assertJsonPath('data.has_token', true);
});

test('a sync is queued in the background, marked as running, and its results are read back from the source', function (): void {
    Queue::fake();
    $source = Fixtures::source();

    $this->postJson(RAMJET_EGG_SOURCES_API.'/'.$source->id.'/sync')
        ->assertAccepted()
        ->assertJsonPath('data.queued', true)
        ->assertJsonPath('data.source.syncing', true);

    Queue::assertPushed(SyncEggSourceJob::class, fn (SyncEggSourceJob $job): bool => $job->sourceId === $source->id);
    expect(Cache::has(SyncEggSourceJob::busyKey($source->id)))->toBeTrue();
});

test('the queued sync imports the files and the admin screen reads the per-file results from the source', function (): void {
    Http::fake([
        Fixtures::GITHUB_TREE => Http::response([
            'truncated' => false,
            'tree' => [
                ['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob'],
                ['path' => 'eggs/minecraft/egg-b.json', 'type' => 'blob'],
            ],
        ]),
        Fixtures::GITHUB_FILE_A => Http::response(Fixtures::eggJson('Minecraft')),
        Fixtures::GITHUB_FILE_B => Http::response('missing', 404),
    ]);
    $source = Fixtures::source();

    // The queue runs synchronously in the test environment, so the job runs as the request dispatches it.
    $this->postJson(RAMJET_EGG_SOURCES_API.'/'.$source->id.'/sync')->assertAccepted();

    $list = $this->getJson(RAMJET_EGG_SOURCES_API)->assertOk();
    $list->assertJsonPath('data.0.syncing', false)
        ->assertJsonPath('data.0.eggs_count', 1)
        ->assertJsonPath('data.0.last_sync_results.0.status', 'created')
        ->assertJsonPath('data.0.last_sync_results.1.status', 'failed');

    expect((string) $list->json('data.0.last_sync_error'))->toContain('1 of 2 file(s) failed');
    expect(Cache::has(SyncEggSourceJob::busyKey($source->id)))->toBeFalse();
});

test('deleting a source keeps its eggs, removes their link and is logged', function (): void {
    Http::fake([
        Fixtures::GITHUB_TREE => Http::response([
            'truncated' => false,
            'tree' => [['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob']],
        ]),
        Fixtures::GITHUB_FILE_A => Http::response(Fixtures::eggJson('Minecraft')),
    ]);
    $source = Fixtures::source();
    $this->postJson(RAMJET_EGG_SOURCES_API.'/'.$source->id.'/sync')->assertAccepted();
    $egg = Fixtures::importedEgg($source, 'eggs/minecraft/egg-a.json');

    $this->deleteJson(RAMJET_EGG_SOURCES_API.'/'.$source->id)->assertNoContent();

    expect(EggSource::query()->find($source->id))->toBeNull()
        ->and($egg->fresh())->not->toBeNull()
        ->and(ActivityLog::query()->where('event', 'egg-source:delete')->exists())->toBeTrue();
});
