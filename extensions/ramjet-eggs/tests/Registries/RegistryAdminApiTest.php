<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Events\ActivityLogged;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\User;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;
use Ramjet\Eggs\Registries\DockerRegistry;
use Ramjet\Eggs\Registries\EggRegistries;

require_once __DIR__.'/../Support/enables-ramjet-eggs.php';

uses(AdminApiIntegrationTestCase::class);

const REGISTRIES_URL = '/api/admin/extensions/ramjet-eggs/registries';

beforeEach(function (): void {
    ramjetEggsEnable();
    Http::fake(['*' => Http::response()]);
});

/** Creates a registry directly in the table, as the admin API would. */
function registryFixture(array $attributes = []): DockerRegistry
{
    return DockerRegistry::query()->create($attributes + [
        'name' => 'Private',
        'registry' => 'ghcr.io/acme',
        'username' => 'robot',
        'password' => 'old-secret',
    ]);
}

/** Whether Wings was asked to sync the given server. */
function syncSentFor(string $serverUuid): bool
{
    return collect(Http::recorded())->contains(fn (array $pair): bool => str_ends_with($pair[0]->url(), "/api/servers/{$serverUuid}/sync"));
}

test('only root administrators can manage registries', function () {
    $this->actingAs(User::factory()->create(['root_admin' => false]));

    $this->getJson(REGISTRIES_URL)->assertForbidden();
    $this->postJson(REGISTRIES_URL, [])->assertForbidden();
});

test('registries are listed without their passwords, with their egg count', function () {
    $registry = registryFixture();
    $egg = Egg::factory()->create();
    app(EggRegistries::class)->assign($egg, $registry->id);

    $response = $this->getJson(REGISTRIES_URL)->assertOk();

    $response->assertJsonPath('data.0.id', $registry->id)
        ->assertJsonPath('data.0.egg_count', 1)
        ->assertJsonPath('data.0.registry', 'ghcr.io/acme');
    expect($response->json('data.0'))->not->toHaveKey('password')
        ->and($response->getContent())->not->toContain('old-secret');
});

test('creating a registry stores the password encrypted and never returns it', function () {
    $response = $this->postJson(REGISTRIES_URL, [
        'name' => 'GHCR',
        'registry' => '  GHCR.io/ACME  ',
        'username' => 'robot',
        'password' => 'new-secret',
    ])->assertCreated();

    $response->assertJsonPath('data.registry', 'ghcr.io/acme')
        ->assertJsonPath('data.egg_count', 0)
        ->assertJsonPath('data.warnings', []);
    expect($response->getContent())->not->toContain('new-secret');

    $stored = DockerRegistry::query()->sole();
    expect($stored->password)->toBe('new-secret')
        ->and(DB::table('ramjet_docker_registries')->value('password'))->not->toBe('new-secret');
});

test('registry changes are logged with identifying properties only', function () {
    $this->postJson(REGISTRIES_URL, [
        'name' => 'GHCR',
        'registry' => 'ghcr.io/acme',
        'username' => 'robot',
        'password' => 'logged-secret',
    ])->assertCreated();
    $id = DockerRegistry::query()->sole()->id;

    $this->patchJson(REGISTRIES_URL.'/'.$id, [
        'name' => 'GHCR renamed',
        'registry' => 'ghcr.io/acme',
        'username' => 'robot',
        'password' => '',
    ])->assertOk();

    $this->deleteJson(REGISTRIES_URL.'/'.$id)->assertNoContent();

    $this->assertActivityLogged('admin:docker-registry.create');
    $this->assertActivityLogged('admin:docker-registry.update');
    $this->assertActivityLogged('admin:docker-registry.delete');

    Event::assertDispatched(ActivityLogged::class, function (ActivityLogged $event): bool {
        return $event->is('admin:docker-registry.create')
            && array_keys($event->model->properties->all()) === ['id', 'name', 'registry']
            && $event->model->properties->get('name') === 'GHCR';
    });

    Event::assertNotDispatched(ActivityLogged::class, function (ActivityLogged $event): bool {
        $encoded = json_encode($event->model->properties->all()) ?: '';

        return str_contains($encoded, 'logged-secret') || str_contains($encoded, 'robot');
    });
});

test('a registry that is only a host is accepted with a warning', function () {
    $this->postJson(REGISTRIES_URL, [
        'name' => 'GHCR',
        'registry' => 'ghcr.io',
        'username' => 'robot',
        'password' => 'secret',
    ])->assertCreated()
        ->assertJsonCount(1, 'data.warnings');
});

test('creating a registry validates its host and requires a password', function (array $overrides, string $field) {
    $this->postJson(REGISTRIES_URL, $overrides + [
        'name' => 'GHCR',
        'registry' => 'ghcr.io/acme',
        'username' => 'robot',
        'password' => 'secret',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', $field);
    expect(DockerRegistry::query()->count())->toBe(0);
})->with([
    'a scheme' => [['registry' => 'https://ghcr.io/acme'], 'registry'],
    'a trailing slash' => [['registry' => 'ghcr.io/acme/'], 'registry'],
    'a missing password' => [['password' => null], 'password'],
]);

test('updating a registry without a password keeps the stored one', function () {
    $registry = registryFixture();

    $this->patchJson(REGISTRIES_URL.'/'.$registry->id, [
        'name' => 'Renamed',
        'registry' => 'ghcr.io/acme',
        'username' => 'robot',
        'password' => '',
    ])->assertOk()->assertJsonPath('data.name', 'Renamed');

    expect($registry->refresh()->password)->toBe('old-secret');
    Http::assertNothingSent();
});

test('changing the host of a registry requires the password to be entered again', function () {
    $registry = registryFixture();

    $this->patchJson(REGISTRIES_URL.'/'.$registry->id, [
        'name' => 'Private',
        'registry' => 'registry.example.com/acme',
        'username' => 'robot',
    ])->assertUnprocessable()
        ->assertJsonPath('errors.0.meta.source_field', 'password');

    expect($registry->refresh()->registry)->toBe('ghcr.io/acme')
        ->and($registry->password)->toBe('old-secret');
});

test('changing the host with a new password updates the registry and re-syncs its servers', function () {
    $registry = registryFixture();
    $egg = Egg::factory()->create();
    $server = $this->createServerModel(['egg_id' => $egg->id]);
    app(EggRegistries::class)->assign($egg, $registry->id);
    Http::fake(['*' => Http::response()]);

    $this->patchJson(REGISTRIES_URL.'/'.$registry->id, [
        'name' => 'Private',
        'registry' => 'registry.example.com/acme',
        'username' => 'robot',
        'password' => 'fresh-secret',
    ])->assertOk()->assertJsonPath('data.registry', 'registry.example.com/acme');

    $registry->refresh();
    expect($registry->password)->toBe('fresh-secret');
    expect(syncSentFor($server->uuid))->toBeTrue();
});

test('updating only the name does not re-sync servers', function () {
    $registry = registryFixture();
    $egg = Egg::factory()->create();
    $this->createServerModel(['egg_id' => $egg->id]);
    app(EggRegistries::class)->assign($egg, $registry->id);
    Http::fake(['*' => Http::response()]);

    $this->patchJson(REGISTRIES_URL.'/'.$registry->id, [
        'name' => 'Renamed',
        'registry' => 'ghcr.io/acme',
        'username' => 'robot',
    ])->assertOk();

    Http::assertNothingSent();
});

test('deleting a registry clears it from its eggs and re-syncs their servers', function () {
    $registry = registryFixture();
    $egg = Egg::factory()->create();
    $server = $this->createServerModel(['egg_id' => $egg->id]);
    app(EggRegistries::class)->assign($egg, $registry->id);
    Http::fake(['*' => Http::response()]);

    $this->deleteJson(REGISTRIES_URL.'/'.$registry->id)->assertNoContent();

    expect(DockerRegistry::query()->count())->toBe(0)
        ->and(app(EggRegistries::class)->registryFor($egg))->toBeNull();
    expect(syncSentFor($server->uuid))->toBeTrue();
});

test('an unknown registry is not found', function () {
    $this->patchJson(REGISTRIES_URL.'/999999', [
        'name' => 'Missing',
        'registry' => 'ghcr.io/acme',
        'username' => 'robot',
        'password' => 'secret',
    ])->assertNotFound();

    $this->deleteJson(REGISTRIES_URL.'/999999')->assertNotFound();
});
