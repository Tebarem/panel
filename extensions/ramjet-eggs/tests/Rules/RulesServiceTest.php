<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Contracts\Eggs\ExportsEggs;
use Pterodactyl\Contracts\Eggs\ImportsEggs;
use Pterodactyl\Contracts\Eggs\UpdatesEggsFromImports;
use Pterodactyl\Exceptions\Service\InvalidFileUploadException;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Servers\ServerConfigurationStructureService;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Ramjet\Eggs\Registries\DockerRegistry;
use Ramjet\Eggs\Registries\EggRegistries;
use Ramjet\Eggs\Rules\EggRules;
use Ramjet\Eggs\Rules\RuleSet;

require_once __DIR__.'/../Support/enables-ramjet-eggs.php';

uses(IntegrationTestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    ramjetEggsEnable();

    // Changing rules or registries asks Wings to re-sync; the requests go to the node's daemon.
    Http::fake(['*' => Http::response(status: 204)]);
});

/** A child egg that copies its configuration from the given parent, as the admin form creates it. */
function ramjetEggsChildOf(Egg $parent): Egg
{
    return Egg::factory()->create([
        'config_from' => $parent->id,
        'config_files' => '{}',
        'config_startup' => '{}',
        'config_logs' => null,
    ]);
}

/** Exports an egg with the given rules, the way an administrator would download it. */
function ramjetEggsExportWith(RuleSet $rules): string
{
    $egg = Egg::factory()->create();
    app(EggRules::class)->set($egg, $rules);

    return app(ExportsEggs::class)->export($egg->refresh());
}

test('set stores normalized lists and keeps the protected list in the core denylist', function (): void {
    $egg = Egg::factory()->create();
    $rules = app(EggRules::class);

    $rules->set($egg, new RuleSet([' a ', 'a', '', 'b'], ['eula.txt', ' ']));

    $stored = $rules->get($egg->refresh());
    expect($stored->hidden)->toBe(['a', 'b'])
        ->and($stored->protected)->toBe(['eula.txt'])
        ->and($egg->file_denylist)->toBe(['eula.txt']);
});

test('effective rules follow config_from like the core denylist', function (): void {
    $parent = Egg::factory()->create();
    $child = ramjetEggsChildOf($parent);
    $rules = app(EggRules::class);

    $rules->set($parent, new RuleSet(['secret.txt'], ['eula.txt']));
    $rules->set($child, new RuleSet(['own.txt'], ['own-protected.txt']));

    $child->refresh();
    expect($rules->effective($child)->hidden)->toBe(['secret.txt'])
        ->and($rules->effective($child)->protected)->toBe(['eula.txt'])
        ->and($rules->get($child)->hidden)->toBe(['own.txt']);
});

test('merge unions rules keeping their order and removing duplicates', function (): void {
    $egg = Egg::factory()->create();
    $rules = app(EggRules::class);

    $rules->set($egg, new RuleSet(['a', 'b'], ['x']));
    $rules->merge($egg, new RuleSet(['b', 'c'], ['x', 'y']));

    $merged = $rules->get($egg->refresh());
    expect($merged->hidden)->toBe(['a', 'b', 'c'])
        ->and($merged->protected)->toBe(['x', 'y']);
});

test('a changed rule set re-syncs the servers of the egg and of its children, and an unchanged one does not', function (): void {
    $server = $this->createServerModel();
    $child = ramjetEggsChildOf($server->egg);
    $this->createServerModel(['egg_id' => $child->id]);
    $rules = app(EggRules::class);

    $rules->set($server->egg, new RuleSet(['a'], []));
    ramjetEggsSettle();
    Http::assertSentCount(2);

    $rules->set($server->egg->refresh(), new RuleSet(['a'], []));
    ramjetEggsSettle();
    Http::assertSentCount(2);
});

test('the server configuration carries the effective rules and an empty registry_auth', function (): void {
    $server = $this->createServerModel();
    app(EggRules::class)->set($server->egg, new RuleSet(['hidden.txt'], ['eula.txt']));

    $configuration = app(ServerConfigurationStructureService::class)->handle(Server::query()->findOrFail($server->id));

    expect($configuration['egg']['file_denylist'])->toBe(['eula.txt'])
        ->and($configuration['egg']['file_hidden'])->toBe(['hidden.txt'])
        ->and($configuration['container']['registry_auth'])->toBe([]);
});

test('the registry credentials of the egg are sent to Wings', function (): void {
    $server = $this->createServerModel();
    $registry = DockerRegistry::query()->create([
        'name' => 'GHCR',
        'registry' => 'ghcr.io',
        'username' => 'bot',
        'password' => 'hunter2',
    ]);
    app(EggRegistries::class)->assign($server->egg, $registry->id);

    $configuration = app(ServerConfigurationStructureService::class)->handle(Server::query()->findOrFail($server->id));

    expect($configuration['container']['registry_auth'])->toBe([
        ['registry' => 'ghcr.io', 'username' => 'bot', 'password' => 'hunter2'],
    ]);
});

test('an undecryptable registry password sends no credentials and is logged without the value', function (): void {
    Log::spy();
    $server = $this->createServerModel();
    $registry = DockerRegistry::query()->create([
        'name' => 'GHCR',
        'registry' => 'ghcr.io',
        'username' => 'bot',
        'password' => 'hunter2',
    ]);
    app(EggRegistries::class)->assign($server->egg, $registry->id);
    DB::table('ramjet_docker_registries')->where('id', $registry->id)->update(['password' => 'not-encrypted-at-all']);

    $configuration = app(ServerConfigurationStructureService::class)->handle(Server::query()->findOrFail($server->id));

    expect($configuration['container']['registry_auth'])->toBe([]);
    Log::shouldHaveReceived('error')->once();
});

test('exports carry the effective hidden rules right after the protected list', function (): void {
    $parent = Egg::factory()->create();
    app(EggRules::class)->set($parent, new RuleSet(['hidden.txt'], ['eula.txt']));

    $data = json_decode(app(ExportsEggs::class)->export($parent->refresh()), true, flags: JSON_THROW_ON_ERROR);

    $keys = array_keys($data);
    expect($data['file_hidden'])->toBe(['hidden.txt'])
        ->and($data['file_denylist'])->toBe(['eula.txt'])
        ->and(array_search('file_hidden', $keys, true))->toBe(array_search('file_denylist', $keys, true) + 1);
});

test('importing an egg file applies its hidden rules', function (): void {
    $json = ramjetEggsExportWith(new RuleSet(['exported.txt'], ['eula.txt']));

    $imported = app(ImportsEggs::class)->import(UploadedFile::fake()->createWithContent('egg.json', $json));

    expect(app(EggRules::class)->get($imported)->hidden)->toBe(['exported.txt'])
        ->and($imported->file_denylist)->toBe(['eula.txt']);
});

test('updating an egg from a file replaces its hidden rules only when the file has them', function (): void {
    $target = Egg::factory()->create();
    $rules = app(EggRules::class);
    $rules->set($target, new RuleSet(['keep.txt'], []));

    $withKey = ramjetEggsExportWith(new RuleSet(['new.txt'], []));
    $updated = app(UpdatesEggsFromImports::class)->update($target, UploadedFile::fake()->createWithContent('egg.json', $withKey));
    expect($rules->get($updated)->hidden)->toBe(['new.txt']);

    $withoutKey = json_decode($withKey, true, flags: JSON_THROW_ON_ERROR);
    unset($withoutKey['file_hidden']);
    $updated = app(UpdatesEggsFromImports::class)->update($target->refresh(), UploadedFile::fake()->createWithContent('egg.json', json_encode($withoutKey, JSON_THROW_ON_ERROR)));
    expect($rules->get($updated)->hidden)->toBe(['new.txt']);
});

test('a file with too many hidden patterns is refused before anything is imported', function (): void {
    $count = Egg::query()->count();
    $data = json_decode(ramjetEggsExportWith(new RuleSet([], [])), true, flags: JSON_THROW_ON_ERROR);
    $data['file_hidden'] = array_map(fn (int $i): string => "file-{$i}.txt", range(1, 501));

    $file = UploadedFile::fake()->createWithContent('egg.json', json_encode($data, JSON_THROW_ON_ERROR));

    expect(fn () => app(ImportsEggs::class)->import($file))->toThrow(InvalidFileUploadException::class);
    expect(Egg::query()->count())->toBe($count + 1);
});

test('updating an egg from a file that changes its protected list re-syncs its servers once', function (): void {
    $server = $this->createServerModel();
    $target = $server->egg;
    app(EggRules::class)->set($target, new RuleSet([], ['old.txt']));
    ramjetEggsSettle();
    Http::assertSentCount(1);

    $file = json_decode(ramjetEggsExportWith(new RuleSet([], ['new.txt'])), true, flags: JSON_THROW_ON_ERROR);
    unset($file['file_hidden']);
    app(UpdatesEggsFromImports::class)->update($target->refresh(), UploadedFile::fake()->createWithContent('egg.json', json_encode($file, JSON_THROW_ON_ERROR)));
    ramjetEggsSettle();

    Http::assertSentCount(2);
});

test('building the configuration for many servers sharing an egg reads its rules and registry once', function (): void {
    $first = $this->createServerModel();
    $egg = $first->egg;
    $registry = DockerRegistry::query()->create(['name' => 'GHCR', 'registry' => 'ghcr.io', 'username' => 'bot', 'password' => 'hunter2']);
    app(EggRegistries::class)->assign($egg, $registry->id);
    app(EggRules::class)->set($egg, new RuleSet(['hidden.txt'], ['eula.txt']));

    $servers = [$first];
    foreach (range(1, 9) as $_) {
        $servers[] = $this->createServerModel(['egg_id' => $egg->id]);
    }

    $service = app(ServerConfigurationStructureService::class);
    DB::flushQueryLog();
    DB::enableQueryLog();
    $configurations = array_map(
        fn (Server $server): array => $service->handle(Server::query()->findOrFail($server->id)),
        $servers,
    );
    $log = DB::getQueryLog();
    DB::disableQueryLog();

    // One read of the hidden rules, one of the registry assignment and one of the registry itself, whatever the server count.
    $extensionQueries = array_filter($log, fn (array $query): bool => str_contains($query['query'], 'ramjet_'));
    expect(count($extensionQueries))->toBeLessThanOrEqual(3)->toBeGreaterThan(0);

    foreach ($configurations as $configuration) {
        expect($configuration['egg']['file_hidden'])->toBe(['hidden.txt'])
            ->and($configuration['container']['registry_auth'])->toHaveCount(1);
    }
});

test('a rule change made in the same request is seen by the next configuration', function (): void {
    $server = $this->createServerModel();
    $service = app(ServerConfigurationStructureService::class);

    $before = $service->handle(Server::query()->findOrFail($server->id));
    app(EggRules::class)->set($server->egg, new RuleSet(['new.txt'], []));
    $after = $service->handle(Server::query()->findOrFail($server->id));

    expect($before['egg']['file_hidden'])->toBe([])
        ->and($after['egg']['file_hidden'])->toBe(['new.txt']);
});
