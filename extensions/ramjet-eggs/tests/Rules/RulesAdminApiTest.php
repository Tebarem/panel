<?php

declare(strict_types=1);

use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Egg;
use Pterodactyl\Tests\Integration\Api\Admin\AdminApiIntegrationTestCase;
use Ramjet\Eggs\Rules\EggRules;
use Ramjet\Eggs\Rules\RuleSet;

require_once __DIR__.'/../Support/enables-ramjet-eggs.php';

uses(AdminApiIntegrationTestCase::class);

beforeEach(function (): void {
    ramjetEggsEnable();
    Http::fake(['*' => Http::response(status: 204)]);
});

/** A complete egg payload, as the admin egg form sends it, without the extension fields. */
function ramjetEggsEggPayload(): array
{
    return [
        'name' => 'Rules Egg',
        'description' => 'Egg with file rules.',
        'docker_images' => "Java 17|ghcr.io/pterodactyl/yolks:java_17\nJava 11|ghcr.io/pterodactyl/yolks:java_11",
        'startup' => 'java -jar server.jar',
        'config_stop' => 'stop',
        'config_startup' => '{"done": ["Done"]}',
        'config_logs' => '{}',
        'config_files' => '{}',
        'features' => ['eula', 'fastdl'],
    ];
}

test('the egg form saves hidden and protected rules and returns them', function (): void {
    $egg = Egg::factory()->create();

    $response = $this->putJson(route('api.admin.eggs.update', ['egg' => $egg->id]), [
        ...ramjetEggsEggPayload(),
        'extensions' => ['ramjet-eggs' => ['file_hidden' => "secret.txt\n\n*.key", 'file_denylist' => 'eula.txt']],
    ]);

    $response->assertStatus(Response::HTTP_OK);
    $rules = app(EggRules::class)->get($egg->refresh());
    expect($rules->hidden)->toBe(['secret.txt', '*.key'])
        ->and($rules->protected)->toBe(['eula.txt']);

    $view = $this->getJson(route('api.admin.eggs.view', ['egg' => $egg->id]));
    $view->assertStatus(Response::HTTP_OK);
    expect($view->json('attributes.extensions.ramjet-eggs.file_hidden'))->toBe("secret.txt\n*.key")
        ->and($view->json('attributes.extensions.ramjet-eggs.file_denylist'))->toBe('eula.txt');
});

test('an egg created from the form gets its rules and registry assignment', function (): void {
    $response = $this->postJson(route('api.admin.eggs.store'), [
        ...ramjetEggsEggPayload(),
        'author' => 'rules@example.com',
        'extensions' => ['ramjet-eggs' => ['file_hidden' => 'hidden.txt', 'file_denylist' => '', 'registry_id' => null]],
    ]);

    $response->assertStatus(Response::HTTP_CREATED);
    $egg = Egg::query()->findOrFail($response->json('attributes.id'));
    expect(app(EggRules::class)->get($egg)->hidden)->toBe(['hidden.txt']);
});

test('over-long or over-numerous rules are refused with a validation error on the field', function (string $field, string $value): void {
    $egg = Egg::factory()->create();
    $before = app(EggRules::class)->get($egg);

    $response = $this->putJson(route('api.admin.eggs.update', ['egg' => $egg->id]), [
        ...ramjetEggsEggPayload(),
        'extensions' => ['ramjet-eggs' => [$field => $value]],
    ]);

    $response->assertStatus(Response::HTTP_UNPROCESSABLE_ENTITY);
    expect(json_encode($response->json('errors')))->toContain('extensions.ramjet-eggs.'.$field);
    expect(app(EggRules::class)->get($egg->refresh()))->toEqual($before);
})->with([
    'too many hidden patterns' => ['file_hidden', implode("\n", array_map(fn (int $i): string => "file-{$i}.txt", range(1, 501)))],
    'a pattern longer than the limit' => ['file_denylist', str_repeat('a', 256)],
]);

test('rule changes made through the form re-sync the servers of the egg', function (): void {
    $server = $this->createServerModel();

    $this->putJson(route('api.admin.eggs.update', ['egg' => $server->egg_id]), [
        ...ramjetEggsEggPayload(),
        'extensions' => ['ramjet-eggs' => ['file_hidden' => 'x.txt', 'file_denylist' => '']],
    ])->assertStatus(Response::HTTP_OK);

    ramjetEggsSettle();
    Http::assertSent(fn ($request): bool => str_contains($request->url(), "/api/servers/{$server->uuid}/sync"));
});

test('rules set in code are visible through the rules service', function (): void {
    $egg = Egg::factory()->create();
    app(EggRules::class)->set($egg, new RuleSet(['a.txt'], ['b.txt']));

    expect(app(EggRules::class)->effective($egg->refresh())->hidden)->toBe(['a.txt']);
});

test('a request carrying only the hidden field leaves the protected list as it was', function (): void {
    $egg = Egg::factory()->create();
    app(EggRules::class)->set($egg, new RuleSet(['old.txt'], ['eula.txt']));

    $this->putJson(route('api.admin.eggs.update', ['egg' => $egg->id]), [
        ...ramjetEggsEggPayload(),
        'extensions' => ['ramjet-eggs' => ['file_hidden' => 'new.txt']],
    ])->assertStatus(Response::HTTP_OK);

    $rules = app(EggRules::class)->get($egg->refresh());
    expect($rules->hidden)->toBe(['new.txt'])
        ->and($rules->protected)->toBe(['eula.txt']);
});

test('a request carrying only the protected field leaves the hidden list as it was', function (): void {
    $egg = Egg::factory()->create();
    app(EggRules::class)->set($egg, new RuleSet(['keep.txt'], ['old.txt']));

    $this->putJson(route('api.admin.eggs.update', ['egg' => $egg->id]), [
        ...ramjetEggsEggPayload(),
        'extensions' => ['ramjet-eggs' => ['file_denylist' => 'eula.txt']],
    ])->assertStatus(Response::HTTP_OK);

    $rules = app(EggRules::class)->get($egg->refresh());
    expect($rules->hidden)->toBe(['keep.txt'])
        ->and($rules->protected)->toBe(['eula.txt']);
});

test('creating an egg with only one rule field stores only that list', function (): void {
    $response = $this->postJson(route('api.admin.eggs.store'), [
        ...ramjetEggsEggPayload(),
        'author' => 'rules@example.com',
        'extensions' => ['ramjet-eggs' => ['file_hidden' => 'only-hidden.txt']],
    ]);

    $response->assertStatus(Response::HTTP_CREATED);
    $rules = app(EggRules::class)->get(Egg::query()->findOrFail($response->json('attributes.id')));
    expect($rules->hidden)->toBe(['only-hidden.txt'])
        ->and($rules->protected)->toBe([]);
});
