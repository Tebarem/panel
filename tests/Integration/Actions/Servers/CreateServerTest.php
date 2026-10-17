<?php

declare(strict_types=1);

namespace Pterodactyl\Tests\Pest\Integration\Actions\Servers\CreateServerTest;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Contracts\Servers\CreatesServers;
use Pterodactyl\Events\Server\OperationCompleted;
use Pterodactyl\Exceptions\Http\Connection\DaemonConnectionException;
use Pterodactyl\Exceptions\Service\Deployment\NoViableAllocationException;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Location;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Objects\DeploymentObject;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\User;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Pterodactyl\Tests\Support\Fakes\FakeDaemonServer;

use function pterodactylTestCase;

uses(IntegrationTestCase::class, DatabaseTransactions::class);
uses(WithFaker::class);
/**
 * Stub the calls to Wings so that we don't actually hit those API endpoints.
 */
beforeEach(function (): void {
    /* @noinspection PhpFieldAssignmentTypeMismatchInspection */
    Event::fake([OperationCompleted::class]);
    $this->bungeecord = Egg::query()->where('author', 'support@pterodactyl.io')->where('name', 'Bungeecord')->firstOrFail();
    $this->daemonServerRepository = new FakeDaemonServer;
});
test('server is created with deployment object', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    /** @var Location $location */
    $location = Location::factory()->create();
    /** @var Node $node */
    $node = Node::factory()->create(['location_id' => $location->id]);
    /** @var Allocation[]|Collection $allocations */
    $allocations = Allocation::factory()->times(5)->create(['node_id' => $node->id]);
    $deployment = (new DeploymentObject())->setDedicated(true)->setLocations([$node->location_id])->setPorts([$allocations[0]->port]);
    $egg = $this->cloneEggAndVariables($this->bungeecord);
    // We want to make sure that the validator service runs as an admin, and not as a regular
    // user when saving variables.
    $egg->variables()->first()->update(['user_editable' => false]);
    $data = ['name' => $this->faker->name, 'description' => $this->faker->sentence, 'owner_id' => $user->id, 'memory' => 256, 'swap' => 128, 'disk' => 100, 'io' => 500, 'cpu' => 0, 'startup' => 'java server2.jar', 'image' => 'java:8', 'egg_id' => $egg->id, 'allocation_additional' => [$allocations[4]->id], 'environment' => ['BUNGEE_VERSION' => '123', 'SERVER_JARFILE' => 'server2.jar'], 'start_on_completion' => true];
    try {
        getService()->create(array_merge($data, ['environment' => ['BUNGEE_VERSION' => '', 'SERVER_JARFILE' => 'server2.jar']]), $deployment);
        $this->fail('This execution pathway should not be reached.');
    } catch (ValidationException $validationException) {
        expect($validationException->errors())->toHaveCount(1);
        expect($validationException->errors())->toHaveKey('environment.BUNGEE_VERSION');
        expect($validationException->errors()['environment.BUNGEE_VERSION'][0])->toBe('The Bungeecord Version variable field is required.');
    }

    $response = getService()->create($data, $deployment);
    $this->daemonServerRepository->assertCreatedWith(true);
    Event::assertDispatched(OperationCompleted::class, fn (OperationCompleted $event): bool => $event->serverUuid === $response->uuid && $event->operation === 'provision' && $event->successful);
    expect($response)->toBeInstanceOf(Server::class);
    expect($response->uuid)->not->toBeNull();
    expect(mb_substr($response->uuid, 0, 8))->toBe($response->uuidShort);
    expect($response->egg_id)->toBe($egg->id);
    expect($response->variables)->toHaveCount(2);
    expect($response->variables[0]->server_value)->toBe('123');
    expect($response->variables[1]->server_value)->toBe('server2.jar');
    foreach ($data as $key => $value) {
        if (in_array($key, ['allocation_additional', 'environment', 'start_on_completion'], true)) {
            continue;
        }

        expect($response->{$key})->toBe($value, "Failed asserting equality of '{$key}' in server response. Got: [{$response->{$key}}] Expected: [{$value}]");
    }

    expect($response->allocations)->toHaveCount(2);
    expect($response->allocations[0]->id)->toBe($response->allocation_id);
    expect($response->allocations[0]->id)->toBe($allocations[0]->id);
    expect($response->allocations[1]->id)->toBe($allocations[4]->id);
    expect($response->isSuspended())->toBeFalse();
    expect($response->oom_disabled)->toBeTrue();
    expect($response->database_limit)->toBe(0);
    expect($response->allocation_limit)->toBe(0);
    expect($response->backup_limit)->toBe(0);
});
test('error encountered by wings causes server to be deleted', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    /** @var Location $location */
    $location = Location::factory()->create();
    /** @var Node $node */
    $node = Node::factory()->create(['location_id' => $location->id]);
    /** @var Allocation $allocation */
    $allocation = Allocation::factory()->create(['node_id' => $node->id]);
    $data = ['name' => $this->faker->name, 'description' => $this->faker->sentence, 'owner_id' => $user->id, 'allocation_id' => $allocation->id, 'node_id' => $allocation->node_id, 'memory' => 256, 'swap' => 128, 'disk' => 100, 'io' => 500, 'cpu' => 0, 'startup' => 'java server2.jar', 'image' => 'java:8', 'egg_id' => $this->bungeecord->id, 'environment' => ['BUNGEE_VERSION' => '123', 'SERVER_JARFILE' => 'server2.jar']];
    $this->daemonServerRepository->throwable = new DaemonConnectionException(Http::failedRequest([], 500));
    try {
        getService()->create($data);
        $this->fail('Expected DaemonConnectionException to be thrown.');
    } catch (DaemonConnectionException) {
    }

    $this->daemonServerRepository->assertCreated();
    $this->daemonServerRepository->assertDeleted();
    Event::assertNotDispatched(OperationCompleted::class, fn (OperationCompleted $event): bool => $event->operation === 'provision');
    Event::assertDispatched(OperationCompleted::class, fn (OperationCompleted $event): bool => $event->operation === 'delete' && $event->successful);
    $this->assertDatabaseMissing('servers', ['owner_id' => $user->id]);
});
test('an allocation taken by another server before it is claimed rejects the create and persists nothing', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    /** @var Node $node */
    $node = Node::factory()->create(['location_id' => Location::factory()->create()->id]);
    $primary = Allocation::factory()->create(['node_id' => $node->id]);
    $stolen = Allocation::factory()->create(['node_id' => $node->id]);
    $other = $this->createServerModel();
    $serversBefore = Server::query()->count();

    // Simulate a concurrent claim: after the new server row is inserted but before the allocation
    // claim runs, another server takes the additional allocation.
    Event::listen('eloquent.created: '.Server::class, function (Server $created) use ($user, $stolen, $other): void {
        if ($created->owner_id === $user->id) {
            Allocation::query()->whereKey($stolen->id)->update(['server_id' => $other->id]);
        }
    });

    $data = ['name' => $this->faker->name, 'description' => $this->faker->sentence, 'owner_id' => $user->id, 'allocation_id' => $primary->id, 'allocation_additional' => [$stolen->id], 'node_id' => $node->id, 'memory' => 256, 'swap' => 128, 'disk' => 100, 'io' => 500, 'cpu' => 0, 'startup' => 'java server2.jar', 'image' => 'java:8', 'egg_id' => $this->bungeecord->id, 'environment' => ['BUNGEE_VERSION' => '123', 'SERVER_JARFILE' => 'server2.jar']];

    expect(fn (): Server => getService()->create($data))->toThrow(NoViableAllocationException::class, 'One of the selected allocations was assigned to another server; try again.');

    $this->assertDatabaseMissing('servers', ['owner_id' => $user->id]);
    expect(Server::query()->count())->toBe($serversBefore);
    expect($primary->refresh()->server_id)->toBeNull();
});

test('allocations that are still unassigned are claimed by the new server', function (): void {
    /** @var User $user */
    $user = User::factory()->create();
    /** @var Node $node */
    $node = Node::factory()->create(['location_id' => Location::factory()->create()->id]);
    $primary = Allocation::factory()->create(['node_id' => $node->id]);
    $additional = Allocation::factory()->create(['node_id' => $node->id]);

    $server = getService()->create(['name' => $this->faker->name, 'description' => $this->faker->sentence, 'owner_id' => $user->id, 'allocation_id' => $primary->id, 'allocation_additional' => [$additional->id], 'node_id' => $node->id, 'memory' => 256, 'swap' => 128, 'disk' => 100, 'io' => 500, 'cpu' => 0, 'startup' => 'java server2.jar', 'image' => 'java:8', 'egg_id' => $this->bungeecord->id, 'environment' => ['BUNGEE_VERSION' => '123', 'SERVER_JARFILE' => 'server2.jar']]);

    expect($primary->refresh()->server_id)->toBe($server->id);
    expect($additional->refresh()->server_id)->toBe($server->id);
});

function getService(): CreatesServers
{
    return (fn () => $this->app->make(CreatesServers::class))->call(pterodactylTestCase());
}
