<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Validator;
use Pterodactyl\Models\Egg;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Ramjet\Eggs\Registries\DockerRegistry;
use Ramjet\Eggs\Registries\EggRegistries;
use Ramjet\Eggs\Registries\EggRegistryAssignment;
use Ramjet\Eggs\Registries\EggRegistryFields;

require_once __DIR__.'/../Support/enables-ramjet-eggs.php';

uses(IntegrationTestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    Http::fake(['*' => Http::response()]);

    $this->egg = Egg::factory()->create();
    $this->registry = DockerRegistry::query()->create([
        'name' => 'Private',
        'registry' => 'ghcr.io/acme',
        'username' => 'robot',
        'password' => 'token-one',
    ]);
    $this->registries = $this->app->make(EggRegistries::class);
});

test('an egg without an assignment has no registry', function () {
    expect($this->registries->registryFor($this->egg))->toBeNull();
});

test('an assigned registry is returned with its decrypted password', function () {
    $this->registries->assign($this->egg, $this->registry->id);

    $registry = $this->registries->registryFor($this->egg);

    expect($registry)->toBeInstanceOf(DockerRegistry::class)
        ->and($registry->id)->toBe($this->registry->id)
        ->and($registry->password)->toBe('token-one');
});

test('the password is encrypted at rest', function () {
    $raw = DB::table('ramjet_docker_registries')->where('id', $this->registry->id)->value('password');

    expect($raw)->not->toBe('token-one')
        ->and($raw)->not->toBeEmpty();
});

test('a registry is never serialized with its password', function () {
    expect($this->registry->toArray())->not->toHaveKey('password')
        ->and($this->registry->toJson())->not->toContain('token-one');
});

test('assigning a registry re-syncs the servers of the egg once the change is committed', function () {
    $server = $this->createServerModel(['egg_id' => $this->egg->id]);

    $this->registries->assign($this->egg, $this->registry->id);

    Http::assertSent(fn ($request): bool => $request->method() === 'POST'
        && str_ends_with($request->url(), "/api/servers/{$server->uuid}/sync"));
});

test('assigning the same registry again does not re-sync servers', function () {
    $this->createServerModel(['egg_id' => $this->egg->id]);
    $this->registries->assign($this->egg, $this->registry->id);
    Http::fake(['*' => Http::response()]);

    $this->registries->assign($this->egg, $this->registry->id);

    Http::assertNothingSent();
});

test('clearing an assignment removes the registry and re-syncs servers', function () {
    $this->registries->assign($this->egg, $this->registry->id);
    $this->createServerModel(['egg_id' => $this->egg->id]);
    Http::fake(['*' => Http::response()]);

    $this->registries->assign($this->egg, null);

    expect($this->registries->registryFor($this->egg))->toBeNull()
        ->and(EggRegistryAssignment::query()->find($this->egg->id)?->registry_id)->toBeNull();
    Http::assertSentCount(1);
});

test('the assignment is not re-synced when it is rolled back', function () {
    $this->createServerModel(['egg_id' => $this->egg->id]);

    try {
        DB::transaction(function (): void {
            $this->registries->assign($this->egg, $this->registry->id);

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    expect($this->registries->registryFor($this->egg))->toBeNull();
    Http::assertNothingSent();
});

test('deleting the registry clears the assignment of its eggs', function () {
    $this->registries->assign($this->egg, $this->registry->id);

    $this->registry->delete();

    expect(EggRegistryAssignment::query()->find($this->egg->id)?->registry_id)->toBeNull()
        ->and($this->registries->registryFor($this->egg))->toBeNull();
});

test('the registry field accepts an existing registry or none', function () {
    $fields = new EggRegistryFields();

    expect(ramjetRegistryFieldPasses([$this->registry->id], $fields))->toBeTrue()
        ->and(ramjetRegistryFieldPasses([null], $fields))->toBeTrue()
        ->and(ramjetRegistryFieldPasses([999999], $fields))->toBeFalse()
        ->and(ramjetRegistryFieldPasses(['not-a-number'], $fields))->toBeFalse();
});

test('a payload without the registry field leaves the assignment alone', function () {
    $this->registries->assign($this->egg, $this->registry->id);
    $fields = new EggRegistryFields();

    $fields->save($this->egg, ['file_hidden' => 'x'], $this->registries);

    expect($this->registries->registryFor($this->egg)?->id)->toBe($this->registry->id);
});

test('the registry field reports the current registry and saves a new one', function () {
    $fields = new EggRegistryFields();

    expect($fields->values($this->egg, $this->registries))->toBe(['registry_id' => null]);

    $fields->save($this->egg, ['registry_id' => $this->registry->id], $this->registries);

    expect($fields->values($this->egg, $this->registries))->toBe(['registry_id' => $this->registry->id]);

    $fields->save($this->egg, ['registry_id' => null], $this->registries);

    expect($this->registries->registryFor($this->egg))->toBeNull();
});

/** @param list<mixed> $value */
function ramjetRegistryFieldPasses(array $value, EggRegistryFields $fields): bool
{
    return Validator::make(['registry_id' => $value[0]], $fields->rules())->passes();
}
