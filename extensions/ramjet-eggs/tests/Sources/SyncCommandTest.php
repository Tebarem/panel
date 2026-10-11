<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Tests\Sources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Ramjet\Eggs\Sources\EggSource;
use Ramjet\Eggs\Sources\HostResolver;

require_once __DIR__.'/../Support/enables-ramjet-eggs.php';

uses(IntegrationTestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    ramjetEggsEnable();
    app()->instance(HostResolver::class, Fixtures::resolver());
});

test('the sync command succeeds when every file of every source imports', function (): void {
    Fixtures::source();

    Http::fake([
        Fixtures::GITHUB_TREE => Http::response([
            'truncated' => false,
            'tree' => [['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob']],
        ]),
        Fixtures::GITHUB_FILE_A => Http::response(Fixtures::eggJson('Minecraft')),
    ]);

    $this->artisan('ramjet-eggs:sync')
        ->expectsOutputToContain('[created] eggs/minecraft/egg-a.json')
        ->assertExitCode(0);
});

test('the sync command fails when a source cannot be listed', function (): void {
    $source = Fixtures::source(['path' => '']);

    Http::fake([
        Fixtures::GITHUB_TREE => Http::response(['truncated' => true, 'tree' => []]),
    ]);

    $this->artisan('ramjet-eggs:sync', ['--id' => $source->id])
        ->assertExitCode(1);
});

test('the sync command with an unknown id fails', function (): void {
    $this->artisan('ramjet-eggs:sync', ['--id' => 987654])
        ->assertExitCode(1);
});

test('the sync command with no sources succeeds when no id is given', function (): void {
    EggSource::query()->delete();

    $this->artisan('ramjet-eggs:sync')->assertExitCode(0);
});
