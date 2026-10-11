<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Services\Servers\ServerConfigurationStructureServiceTest;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Log;
use Pterodactyl\Contracts\Servers\ContributesToServerConfiguration;
use Pterodactyl\Models\Server;
use Pterodactyl\Services\Servers\ServerConfigurationStructureService;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use RuntimeException;
use stdClass;

uses(IntegrationTestCase::class, DatabaseTransactions::class);

/**
 * Binds the contributor under a unique key and tags it so the service resolves it.
 */
function tagContributor(Application $app, string $key, object $contributor): void
{
    $app->instance($key, $contributor);
    $app->tag([$key], ServerConfigurationStructureService::CONTRIBUTORS_TAG);
}

function appendingContributor(string $label): ContributesToServerConfiguration
{
    return new readonly class($label) implements ContributesToServerConfiguration
    {
        public function __construct(private string $label) {}

        public function contribute(Server $server, array $configuration): array
        {
            $configuration['order'][] = $this->label;
            $configuration['seen_uuid'] = $server->uuid;

            return $configuration;
        }
    };
}

test('default format is unchanged when no contributors are tagged', function (): void {
    $server = $this->createServerModel();

    $configuration = $this->app->make(ServerConfigurationStructureService::class)->handle($server);

    expect($configuration)->toHaveKeys(['uuid', 'meta', 'suspended', 'environment', 'invocation', 'skip_egg_scripts', 'build', 'container', 'allocations', 'mounts', 'egg'])
        ->and($configuration['uuid'])->toBe($server->uuid)
        ->and($configuration['egg'])->toHaveKey('file_denylist')
        ->and($configuration)->not->toHaveKey('order');
});

test('contributors run in registration order and receive the output of the previous one', function (): void {
    $server = $this->createServerModel();
    tagContributor($this->app, 'test.contributor.first', appendingContributor('first'));
    tagContributor($this->app, 'test.contributor.second', appendingContributor('second'));

    $configuration = $this->app->make(ServerConfigurationStructureService::class)->handle($server);

    expect($configuration['order'])->toBe(['first', 'second'])
        ->and($configuration['seen_uuid'])->toBe($server->uuid)
        ->and($configuration['uuid'])->toBe($server->uuid);
});

test('a contributor can replace the egg file denylist sent to wings', function (): void {
    $server = $this->createServerModel();
    tagContributor($this->app, 'test.contributor.denylist', new class implements ContributesToServerConfiguration
    {
        public function contribute(Server $server, array $configuration): array
        {
            $configuration['egg']['file_denylist'] = ['protected.yml'];

            return $configuration;
        }
    });

    $configuration = $this->app->make(ServerConfigurationStructureService::class)->handle($server);

    expect($configuration['egg']['file_denylist'])->toBe(['protected.yml']);
});

test('tagged services that do not implement the contract are skipped and logged', function (): void {
    Log::spy();
    $server = $this->createServerModel();
    tagContributor($this->app, 'test.contributor.invalid', new stdClass());
    tagContributor($this->app, 'test.contributor.valid', appendingContributor('valid'));

    $configuration = $this->app->make(ServerConfigurationStructureService::class)->handle($server);

    expect($configuration['order'])->toBe(['valid'])
        ->and($configuration['uuid'])->toBe($server->uuid);
    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => $context['tag'] === ServerConfigurationStructureService::CONTRIBUTORS_TAG
        && $context['expected'] === ContributesToServerConfiguration::class
        && $context['received'] === 'stdClass')->once();
});

test('exceptions thrown by a contributor propagate', function (): void {
    $server = $this->createServerModel();
    tagContributor($this->app, 'test.contributor.failing', new class implements ContributesToServerConfiguration
    {
        public function contribute(Server $server, array $configuration): array
        {
            throw new RuntimeException('contributor failed');
        }
    });

    $this->app->make(ServerConfigurationStructureService::class)->handle($server);
})->throws(RuntimeException::class, 'contributor failed');
