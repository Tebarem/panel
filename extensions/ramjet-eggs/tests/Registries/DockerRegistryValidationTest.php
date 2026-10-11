<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Validator;
use Pterodactyl\Tests\TestCase;
use Ramjet\Eggs\Registries\DockerRegistry;

require_once __DIR__.'/../Support/enables-ramjet-eggs.php';

uses(TestCase::class);

/** Registry values that are a host, optionally with a port and a namespace path. */
$validRegistries = [
    'ghcr.io',
    'ghcr.io/myorg',
    'ghcr.io/myorg/team',
    'docker.io/myorg',
    'registry.example.com:5000',
    'localhost:5000/team/images',
    'registry.example.com/my-org/sub_team.images',
];

/** Registry values with a scheme, trailing slashes, spaces, upper case or otherwise malformed. */
$invalidRegistries = [
    '',
    'https://ghcr.io',
    'ghcr.io/',
    'ghcr.io//my-org',
    'ghcr.io/my org',
    ' ghcr.io',
    'ghcr.io:port',
    'myorg',
    'myorg/team',
    "ghcr.io\n",
    'GHCR.io/MyOrg',
];

$passes = fn (string $registry): bool => Validator::make(
    ['registry' => $registry],
    ['registry' => DockerRegistry::validationRules()['registry']],
)->passes();

test('registry values that are a host with an optional port and namespace are accepted', function (string $registry) use ($passes) {
    expect($passes($registry))->toBeTrue();
})->with($validRegistries);

test('registry values with a scheme, slashes, spaces or upper case are rejected', function (string $registry) use ($passes) {
    expect($passes($registry))->toBeFalse();
})->with($invalidRegistries);

test('a registry without a namespace is flagged as matching the whole host', function (string $registry, bool $wholeHost) {
    expect((new DockerRegistry(['registry' => $registry]))->matchesWholeHost())->toBe($wholeHost);
})->with([
    ['ghcr.io', true],
    ['ghcr.io/myorg', false],
]);
