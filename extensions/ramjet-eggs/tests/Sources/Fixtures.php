<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Tests\Sources;

use Pterodactyl\Models\Egg;
use Ramjet\Eggs\Sources\EggSource;
use Ramjet\Eggs\Sources\EggSourceEgg;

/**
 * Shared helpers for the egg source tests.
 */
final class Fixtures
{
    public const GITHUB_TREE = 'https://api.github.com/repos/acme/eggs/git/trees/main*';

    public const GITHUB_FILE_A = 'https://api.github.com/repos/acme/eggs/contents/eggs/minecraft/egg-a.json*';

    public const GITHUB_FILE_B = 'https://api.github.com/repos/acme/eggs/contents/eggs/minecraft/egg-b.json*';

    /**
     * The name resolver of the tests: fixed addresses for the names the tests use, one public address for the rest.
     */
    public static function resolver(): StubHostResolver
    {
        return new StubHostResolver([
            'localhost' => ['127.0.0.1'],
            'rebind.test' => [StubHostResolver::PUBLIC_ADDRESS, '169.254.169.254'],
            'nip.test' => ['169.254.169.254'],
            'private.test' => ['10.0.0.5'],
            'nxdomain.test' => [],
        ]);
    }

    /**
     * Returns the egg imported from a source file.
     */
    public static function importedEgg(EggSource $source, string $path): Egg
    {
        $link = EggSourceEgg::query()->where('source_id', $source->id)->where('path', $path)->firstOrFail();

        return Egg::query()->findOrFail($link->egg_id);
    }

    /**
     * Creates an egg source with sensible defaults, overridden by the given attributes.
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function source(array $attributes = []): EggSource
    {
        /** @var EggSource $source */
        $source = EggSource::query()->create(array_merge([
            'name' => 'Test Source',
            'type' => EggSource::TYPE_GITHUB,
            'repository' => 'acme/eggs',
            'branch' => 'main',
            'path' => 'eggs/minecraft',
        ], $attributes));

        return $source;
    }

    /**
     * Returns the JSON for a valid PTDL_v2 egg export with the given name.
     *
     * @param  list<string>  $denylist
     * @param  list<string>  $hidden
     */
    public static function eggJson(string $name, array $denylist = ['/plugins/Licensed.jar'], array $hidden = ['*.key']): string
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
}
