<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Testing\TestResponse;
use Pterodactyl\Contracts\Servers\CreatesServers;
use Pterodactyl\Http\Middleware\RequireTwoFactorAuthentication;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\ApiKey;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\EggVariable;
use Pterodactyl\Models\ExtensionSetting;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\Server;
use Pterodactyl\Models\ServerVariable;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Ramjet\ProxyProvisioning\Models\UserProxy;
use Ramjet\ProxyProvisioning\ProxyConfig;
use Ramjet\ProxyProvisioning\ProxyProvisioner;
use Ramjet\ProxyProvisioning\ProxySettings;
use Ramjet\ProxyProvisioning\Tests\ProxyTestCase;
use Ramjet\ProxyProvisioning\Tests\Support\FlakyCreatesServers;

require_once __DIR__.'/../Support/enables-ramjet-proxy.php';

uses(ProxyTestCase::class);

const PROXY_STATUS_URL = '/api/client/extensions/ramjet-proxy-provisioning/status';
const PROXY_CREATE_URL = '/api/client/extensions/ramjet-proxy-provisioning/create';

beforeEach(function (): void {
    ramjetProxyEnable();
    proxyWings();

    $this->egg = Egg::factory()->create([
        'name' => ProxySettings::EGG_NAME,
        'startup' => './proxy --bind 0.0.0.0',
        'docker_images' => ['Ramjet Proxy' => '127.0.0.1:5000/ramjet/proxy:stable'],
    ]);
    EggVariable::factory()->create([
        'egg_id' => $this->egg->id,
        'env_variable' => 'PROXY_MODE',
        'default_value' => 'velocity',
        'rules' => 'required|string',
    ]);

    $this->node = Node::factory()->withLocation()->create();

    // Off by default, so every test that expects a working feature switches it on with a node and a port range.
    setProxySetting('enabled', true);
    setProxySetting('node', $this->node->id);
    setProxySetting('port_min', 25000);
    setProxySetting('port_max', 31000);

    // A second connection, so a test can hold a named lock the way a parallel request would.
    config(['database.connections.probe' => config('database.connections.mysql')]);
});

/** A free allocation on the test node. */
function proxyAllocation(Node $node, int $port): Allocation
{
    return Allocation::factory()->create(['node_id' => $node->id, 'port' => $port]);
}

function proxySettings(): ProxyConfig
{
    return ProxyConfig::load(app(ExtensionRepository::class)->settings(ProxySettings::EXTENSION_ID));
}

function setProxySetting(string $key, mixed $value): void
{
    app(ExtensionRepository::class)->settings(ProxySettings::EXTENSION_ID)->set($key, $value);
}

/** Replaces the HTTP fake for the test, so every Wings call answers with the given status. */
function proxyWings(int $status = 200, string $body = ''): void
{
    $factory = new Factory;
    Http::swap($factory);
    $factory->fake(['*' => $factory->response($body, $status)]);
}

/** Makes every Wings call fail to connect at all. */
function proxyWingsUnreachable(): void
{
    $factory = new Factory;
    Http::swap($factory);
    $factory->fake(fn () => throw new ConnectionException('connection refused'));
}

/** The error message of an API error envelope. */
function proxyError(TestResponse $response): string
{
    return (string) $response->json('errors.0.detail');
}

test('guests cannot read the status or create a proxy', function () {
    $this->getJson(PROXY_STATUS_URL)->assertUnauthorized();
    $this->postJson(PROXY_CREATE_URL, [])->assertUnauthorized();

    expect(Server::query()->count())->toBe(0)
        ->and(UserProxy::query()->count())->toBe(0);
});

test('status offers a proxy to a customer who has none', function () {
    proxyAllocation($this->node, 25565);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson(PROXY_STATUS_URL)->assertOk()
        ->assertExactJson([
            'enabled' => true,
            'has_proxy' => false,
            'server' => null,
            'can_create' => true,
        ]);
});

test('create builds the proxy from the admin settings, the egg and the lowest free allocation in the port range', function () {
    $user = User::factory()->create(['username' => 'alice']);
    $low = proxyAllocation($this->node, 25565);
    $high = proxyAllocation($this->node, 25570);

    $response = $this->actingAs($user)->postJson(PROXY_CREATE_URL, ['confirm' => true])->assertCreated();

    $server = Server::query()->where('owner_id', $user->id)->sole();

    expect($server->name)->toBe('alice-proxy')
        ->and($server->egg_id)->toBe($this->egg->id)
        ->and($server->node_id)->toBe($this->node->id)
        ->and($server->allocation_id)->toBe($low->id)
        ->and($server->image)->toBe('127.0.0.1:5000/ramjet/proxy:stable')
        ->and($server->startup)->toBe('./proxy --bind 0.0.0.0')
        ->and([$server->memory, $server->cpu, $server->disk, $server->swap, $server->io])->toEqual([128, 50, 512, 0, 500])
        ->and([$server->backup_limit, $server->database_limit, $server->allocation_limit])->toEqual([1, 0, 0])
        ->and($server->oom_disabled)->toBeFalse();

    expect($low->refresh()->server_id)->toBe($server->id)
        ->and($high->refresh()->server_id)->toBeNull()
        ->and(ServerVariable::query()->where('server_id', $server->id)->value('variable_value'))->toBe('velocity');

    expect(UserProxy::query()->where('user_id', $user->id)->value('server_id'))->toBe($server->id);
    expect($response->json('server.identifier'))->toBe($server->uuidShort)
        ->and($response->json('server.name'))->toBe('alice-proxy')
        ->and($response->getContent())->not->toContain('velocity');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/servers')
        && $request->data()['start_on_completion'] === true
        && $request->data()['uuid'] === $server->uuid);

    $this->assertActivityFor('server:proxy.self-provision', $user, $server);
});

test('the server name follows the admin pattern and the node setting picks the node', function () {
    $other = Node::factory()->withLocation()->create();
    $otherPort = proxyAllocation($other, 30000);
    proxyAllocation($this->node, 25565);

    setProxySetting('name_pattern', 'px-{user_id}');
    setProxySetting('node', $other->id);

    $user = User::factory()->create();
    $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertCreated();

    $server = Server::query()->where('owner_id', $user->id)->sole();
    expect($server->name)->toBe('px-'.$user->id)
        ->and($server->node_id)->toBe($other->id)
        ->and($server->allocation_id)->toBe($otherPort->id);
});

test('the egg setting picks the egg by id, whatever its name', function () {
    proxyAllocation($this->node, 25565);
    $other = Egg::factory()->create([
        'name' => 'Some Other Egg',
        'startup' => './other',
        'docker_images' => ['Other' => 'ghcr.io/example/other:latest'],
    ]);
    setProxySetting('egg', $other->id);

    $user = User::factory()->create();
    $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertCreated();

    $server = Server::query()->where('owner_id', $user->id)->sole();
    expect($server->egg_id)->toBe($other->id)
        ->and($server->image)->toBe('ghcr.io/example/other:latest')
        ->and($server->startup)->toBe('./other');
});

test('a customer who has a proxy sees it and cannot create a second one', function () {
    proxyAllocation($this->node, 25565);
    proxyAllocation($this->node, 25566);
    $user = User::factory()->create();

    $created = $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertCreated();

    $this->getJson(PROXY_STATUS_URL)->assertOk()
        ->assertJsonPath('has_proxy', true)
        ->assertJsonPath('can_create', false)
        ->assertJsonPath('server.identifier', $created->json('server.identifier'))
        ->assertJsonPath('server.name', $created->json('server.name'));

    $this->postJson(PROXY_CREATE_URL)->assertStatus(409);
    expect(proxyError($this->postJson(PROXY_CREATE_URL)))->toBe(ProxyProvisioner::ALREADY_HAS_PROXY)
        ->and(Server::query()->where('owner_id', $user->id)->count())->toBe(1)
        ->and(UserProxy::query()->count())->toBe(1);
});

test('root administrators are limited to one proxy through this route as well', function () {
    proxyAllocation($this->node, 25565);
    proxyAllocation($this->node, 25566);
    $admin = User::factory()->create(['root_admin' => true]);

    $this->actingAs($admin)->postJson(PROXY_CREATE_URL)->assertCreated();
    $this->postJson(PROXY_CREATE_URL)->assertStatus(409);

    expect(Server::query()->where('owner_id', $admin->id)->count())->toBe(1);
});

test('a create that lost the race to a parallel request is refused and creates nothing', function () {
    proxyAllocation($this->node, 25565);
    $user = User::factory()->create();

    // The parallel request has already committed its proxy for this user.
    $existing = $this->createServerModel(['owner_id' => $user->id]);
    UserProxy::query()->create(['user_id' => $user->id, 'server_id' => $existing->id, 'created_at' => now()]);

    $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertStatus(409);

    expect(Server::query()->where('owner_id', $user->id)->count())->toBe(1)
        ->and(UserProxy::query()->where('user_id', $user->id)->count())->toBe(1)
        ->and(Allocation::query()->where('node_id', $this->node->id)->whereNull('server_id')->count())->toBe(1);
});

test('the database refuses a second proxy row for the same user', function () {
    $user = User::factory()->create();
    $first = $this->createServerModel(['owner_id' => $user->id]);
    $second = $this->createServerModel(['owner_id' => $user->id]);

    UserProxy::query()->create(['user_id' => $user->id, 'server_id' => $first->id, 'created_at' => now()]);

    expect(fn () => UserProxy::query()->create(['user_id' => $user->id, 'server_id' => $second->id, 'created_at' => now()]))
        ->toThrow(QueryException::class);
    expect(UserProxy::query()->count())->toBe(1);
});

test('a create while the same customer already has a create in progress is refused with 409 and persists nothing', function () {
    proxyAllocation($this->node, 25565);
    $user = User::factory()->create();
    $probe = DB::connection('probe');
    $name = ProxyProvisioner::lockName('u', $user->id);

    expect((int) $probe->selectOne('SELECT GET_LOCK(?, 0) AS got', [$name])->got)->toBe(1);
    try {
        $response = $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertStatus(409);

        expect(proxyError($response))->toBe(ProxyProvisioner::IN_PROGRESS)
            ->and(Server::query()->count())->toBe(0)
            ->and(UserProxy::query()->count())->toBe(0);
    } finally {
        $probe->select('SELECT RELEASE_LOCK(?)', [$name]);
    }
});

test('a create while another proxy is being placed on the node waits for the node lock and then refuses with 503', function () {
    proxyAllocation($this->node, 25565);
    $user = User::factory()->create();
    $probe = DB::connection('probe');
    $name = ProxyProvisioner::lockName('n', $this->node->id);

    expect((int) $probe->selectOne('SELECT GET_LOCK(?, 0) AS got', [$name])->got)->toBe(1);
    try {
        $response = $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertStatus(503);

        expect(proxyError($response))->toBe(ProxyProvisioner::NODE_BUSY)
            ->and(Server::query()->count())->toBe(0)
            ->and(UserProxy::query()->count())->toBe(0);
    } finally {
        $probe->select('SELECT RELEASE_LOCK(?)', [$name]);
    }
});

test('a node without room for the proxy refuses it with 422 and nothing is persisted', function () {
    Node::query()->whereKey($this->node->id)->update(['memory' => 100]);
    proxyAllocation($this->node, 25565);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertStatus(422);

    expect(proxyError($response))->toBe(ProxyProvisioner::NODE_FULL)
        ->and(Server::query()->count())->toBe(0)
        ->and(UserProxy::query()->count())->toBe(0);
    $this->getJson(PROXY_STATUS_URL)->assertJsonPath('reason', ProxyProvisioner::NODE_FULL);
});

test('a node in maintenance refuses new proxies with 503 and says so in the status', function () {
    proxyAllocation($this->node, 25565);
    Node::query()->whereKey($this->node->id)->update(['maintenance_mode' => true]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertStatus(503);

    expect(proxyError($response))->toBe(ProxyProvisioner::NODE_MAINTENANCE)
        ->and(Server::query()->count())->toBe(0);
    $this->getJson(PROXY_STATUS_URL)->assertJsonPath('can_create', false)->assertJsonPath('reason', ProxyProvisioner::NODE_MAINTENANCE);
});

test('only allocations inside the port range are used, and exhausting the range is refused with 422', function () {
    setProxySetting('port_min', 26000);
    setProxySetting('port_max', 26100);
    proxyAllocation($this->node, 25565);
    $inside = proxyAllocation($this->node, 26050);
    $first = User::factory()->create();
    $second = User::factory()->create();

    $this->actingAs($first)->postJson(PROXY_CREATE_URL)->assertCreated();
    expect(Server::query()->where('owner_id', $first->id)->sole()->allocation_id)->toBe($inside->id);

    $response = $this->actingAs($second)->postJson(PROXY_CREATE_URL)->assertStatus(422);

    expect(proxyError($response))->toBe(ProxyProvisioner::NO_ALLOCATION)
        ->and(Server::query()->where('owner_id', $second->id)->count())->toBe(0)
        ->and(UserProxy::query()->where('user_id', $second->id)->count())->toBe(0);
});

test('a missing or inverted port range refuses creation with 409 until the admin fixes it', function () {
    proxyAllocation($this->node, 25565);
    $user = User::factory()->create();

    setProxySetting('port_max', 25000);
    setProxySetting('port_min', 25100);
    $response = $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertStatus(409);
    expect(proxyError($response))->toBe(ProxyProvisioner::PORTS_MISSING);

    setProxySetting('port_min', null);
    setProxySetting('port_max', null);
    $this->getJson(PROXY_STATUS_URL)->assertJsonPath('reason', ProxyProvisioner::PORTS_MISSING);
    expect(Server::query()->count())->toBe(0);
});

test('the optional proxy cap refuses creation once that many proxies exist', function () {
    proxyAllocation($this->node, 25565);
    proxyAllocation($this->node, 25566);
    setProxySetting('max_proxies', 1);
    $first = User::factory()->create();
    $second = User::factory()->create();

    $this->actingAs($first)->postJson(PROXY_CREATE_URL)->assertCreated();

    $response = $this->actingAs($second)->postJson(PROXY_CREATE_URL)->assertStatus(422);
    expect(proxyError($response))->toBe(ProxyProvisioner::LIMIT_REACHED)
        ->and(Server::query()->where('owner_id', $second->id)->count())->toBe(0);
});

test('a node that refuses the server leaves nothing behind and reports 503', function () {
    proxyWings(500, 'node is busy');
    $port = proxyAllocation($this->node, 25565);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertStatus(503);

    expect(proxyError($response))->toBe(ProxyProvisioner::NODE_UNREACHABLE)
        ->and(Server::query()->count())->toBe(0)
        ->and(UserProxy::query()->count())->toBe(0)
        ->and($port->refresh()->server_id)->toBeNull();
});

test('a node that cannot be reached leaves nothing behind and reports 503', function () {
    proxyWingsUnreachable();
    $port = proxyAllocation($this->node, 25565);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertStatus(503);

    expect(proxyError($response))->toBe(ProxyProvisioner::NODE_UNREACHABLE)
        ->and(Server::query()->count())->toBe(0)
        ->and(UserProxy::query()->count())->toBe(0)
        ->and($port->refresh()->server_id)->toBeNull();
});

test('a proxy-egg server the customer already owns is adopted: the create is refused and the server becomes their proxy', function () {
    $user = User::factory()->create();
    $existing = $this->createServerModel(['owner_id' => $user->id, 'egg_id' => $this->egg->id, 'node_id' => $this->node->id]);

    $response = $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertStatus(409);

    expect(proxyError($response))->toBe(ProxyProvisioner::ALREADY_HAS_PROXY)
        ->and(UserProxy::query()->where('user_id', $user->id)->value('server_id'))->toBe($existing->id);
    $this->getJson(PROXY_STATUS_URL)->assertJsonPath('has_proxy', true)->assertJsonPath('server.identifier', $existing->uuidShort);
});

test('changing the owner of the proxy server frees the slot for the previous owner', function () {
    proxyAllocation($this->node, 25565);
    proxyAllocation($this->node, 25566);
    $previous = User::factory()->create();
    $newOwner = User::factory()->create();

    $created = $this->actingAs($previous)->postJson(PROXY_CREATE_URL)->assertCreated();
    $serverId = Server::query()->where('owner_id', $previous->id)->sole()->id;
    Server::query()->whereKey($serverId)->update(['owner_id' => $newOwner->id]);

    $this->actingAs($previous)->getJson(PROXY_STATUS_URL)->assertJsonPath('has_proxy', false)->assertJsonPath('can_create', true);

    $again = $this->actingAs($previous)->postJson(PROXY_CREATE_URL)->assertCreated();
    expect($again->json('server.identifier'))->not->toBe($created->json('server.identifier'))
        ->and(UserProxy::query()->where('user_id', $previous->id)->count())->toBe(1)
        ->and(UserProxy::query()->where('user_id', $previous->id)->value('server_id'))->not->toBe($serverId);
});

test('deleting the proxy server frees the customer to create a new one', function () {
    proxyAllocation($this->node, 25565);
    proxyAllocation($this->node, 25566);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertCreated();
    $server = Server::query()->where('owner_id', $user->id)->sole();

    // Removing the server cascades to the proxy row, as the admin panel does.
    Server::query()->whereKey($server->id)->delete();

    expect(UserProxy::query()->count())->toBe(0);
    $this->getJson(PROXY_STATUS_URL)->assertJsonPath('has_proxy', false)->assertJsonPath('can_create', true);

    $this->postJson(PROXY_CREATE_URL)->assertCreated();
    expect(Server::query()->where('owner_id', $user->id)->count())->toBe(1)
        ->and(UserProxy::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('a disabled feature refuses creation and reports why', function () {
    proxyAllocation($this->node, 25565);
    $user = User::factory()->create();

    setProxySetting('enabled', false);

    $this->actingAs($user)->getJson(PROXY_STATUS_URL)->assertOk()
        ->assertJsonPath('enabled', false)
        ->assertJsonPath('can_create', false)
        ->assertJsonPath('reason', ProxyProvisioner::DISABLED);

    $response = $this->postJson(PROXY_CREATE_URL)->assertStatus(409);
    expect(proxyError($response))->toBe(ProxyProvisioner::DISABLED)
        ->and(Server::query()->count())->toBe(0);
});

test('the feature is off until an admin enables it, and then it refuses to run without a node', function () {
    proxyAllocation($this->node, 25565);
    ExtensionSetting::query()->where('extension', ProxySettings::EXTENSION_ID)->delete();
    app()->forgetInstance(ExtensionRepository::class);
    $user = User::factory()->create();

    $this->actingAs($user)->getJson(PROXY_STATUS_URL)->assertOk()->assertJsonPath('enabled', false);
    expect(proxyError($this->postJson(PROXY_CREATE_URL)))->toBe(ProxyProvisioner::DISABLED);

    // Enabled, but no node chosen: there is no fallback to the first node.
    app(ExtensionRepository::class)->settings(ProxySettings::EXTENSION_ID)->set('enabled', true);
    $this->getJson(PROXY_STATUS_URL)->assertJsonPath('reason', ProxyProvisioner::NODE_MISSING);
    expect(proxyError($this->postJson(PROXY_CREATE_URL)))->toBe(ProxyProvisioner::NODE_MISSING)
        ->and(Server::query()->count())->toBe(0);
});

test('no free allocation is refused with 422 and nothing is persisted', function () {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertStatus(422);

    expect(proxyError($response))->toBe(ProxyProvisioner::NO_ALLOCATION)
        ->and(Server::query()->count())->toBe(0)
        ->and(UserProxy::query()->count())->toBe(0);
});

test('allocations taken on the node are skipped and the next free one is used', function () {
    $taken = proxyAllocation($this->node, 25565);
    $free = proxyAllocation($this->node, 25566);
    $someoneElse = $this->createServerModel(['node_id' => $this->node->id]);
    $taken->update(['server_id' => $someoneElse->id]);

    $user = User::factory()->create();
    $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertCreated();

    expect(Server::query()->where('owner_id', $user->id)->sole()->allocation_id)->toBe($free->id);
});

test('a missing egg is refused with 409 and nothing is persisted', function () {
    proxyAllocation($this->node, 25565);
    Egg::query()->whereKey($this->egg->id)->delete();
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertStatus(409);

    expect(proxyError($response))->toBe(ProxyProvisioner::EGG_MISSING)
        ->and(Server::query()->count())->toBe(0);
});

test('customers cannot choose the server: extra fields and owner overrides are refused and nothing is created', function () {
    proxyAllocation($this->node, 25565);
    $user = User::factory()->create();
    $other = User::factory()->create();

    $this->actingAs($user)->postJson(PROXY_CREATE_URL, ['owner_id' => $other->id, 'memory' => 8192])->assertStatus(422);
    $this->actingAs($user)->postJson(PROXY_CREATE_URL, ['confirm' => false])->assertStatus(422);

    expect(Server::query()->count())->toBe(0)
        ->and(UserProxy::query()->count())->toBe(0);
});

test('a customer with two-factor authentication required by the panel but not enabled is blocked', function () {
    proxyAllocation($this->node, 25565);
    config(['pterodactyl.auth.2fa_required' => RequireTwoFactorAuthentication::LEVEL_ALL]);
    $user = User::factory()->create(['use_totp' => false]);

    // The panel's 2FA gate answers before the route runs: a 400 carrying its own error code.
    $this->actingAs($user)->getJson(PROXY_STATUS_URL)->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'TwoFactorAuthRequiredException');
    $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertStatus(400)
        ->assertJsonPath('errors.0.code', 'TwoFactorAuthRequiredException');

    expect(Server::query()->count())->toBe(0);
});

test('an application API key cannot create a proxy', function () {
    proxyAllocation($this->node, 25565);
    $user = User::factory()->create();
    $key = ApiKey::factory()->create(['user_id' => $user->id, 'key_type' => ApiKey::TYPE_APPLICATION]);

    $this
        ->withHeader('Authorization', 'Bearer '.$key->identifier.Crypt::decrypt($key->token))
        ->postJson(PROXY_CREATE_URL)
        ->assertForbidden();

    expect(Server::query()->count())->toBe(0)
        ->and(UserProxy::query()->count())->toBe(0);
});

test('proxies are private: another customer sees and gets none of them', function () {
    proxyAllocation($this->node, 25565);
    proxyAllocation($this->node, 25566);
    $owner = User::factory()->create();
    $stranger = User::factory()->create();

    $created = $this->actingAs($owner)->postJson(PROXY_CREATE_URL)->assertCreated();

    $this->actingAs($stranger)->getJson(PROXY_STATUS_URL)->assertOk()
        ->assertJsonPath('has_proxy', false)
        ->assertJsonPath('server', null)
        ->assertJsonPath('can_create', true);
    expect($this->actingAs($stranger)->getJson(PROXY_STATUS_URL)->getContent())
        ->not->toContain($created->json('server.identifier'));

    // The stranger gets their own proxy on the next free port, never the owner's.
    $strangerCreate = $this->actingAs($stranger)->postJson(PROXY_CREATE_URL)->assertCreated();
    expect($strangerCreate->json('server.identifier'))->not->toBe($created->json('server.identifier'));
});

test('the create route is throttled to three requests a minute per customer', function () {
    proxyAllocation($this->node, 25565);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertCreated();
    $this->postJson(PROXY_CREATE_URL)->assertStatus(409);
    $this->postJson(PROXY_CREATE_URL)->assertStatus(409);
    $this->postJson(PROXY_CREATE_URL)->assertStatus(429);
});

test('the settings reject values outside their ranges', function () {
    $settings = app(ExtensionRepository::class)->settings(ProxySettings::EXTENSION_ID);
    $rules = ProxySettings::definition($settings)->validationRules();

    $valid = [
        'enabled' => true,
        'egg' => null,
        'node' => null,
        'port_min' => 25000,
        'port_max' => 31000,
        'max_proxies' => 0,
        'memory' => 128,
        'cpu' => 50,
        'disk' => 512,
        'swap' => 0,
        'io' => 500,
        'backup_limit' => 1,
        'database_limit' => 0,
        'allocation_limit' => 0,
        'name_pattern' => '{username}-proxy',
    ];

    $validator = fn (array $input) => Validator::make($input, $rules);
    expect($validator($valid)->passes())->toBeTrue();

    $invalid = [
        'memory' => 8,
        'cpu' => 1001,
        'disk' => 10,
        'swap' => -2,
        'io' => 9,
        'backup_limit' => 101,
        'database_limit' => -1,
        'allocation_limit' => 1000,
        'max_proxies' => -1,
        'egg' => 0,
        'node' => 0,
        'port_min' => 80,
        'port_max' => 70000,
        'name_pattern' => '',
    ];
    foreach ($invalid as $key => $value) {
        expect($validator([...$valid, $key => $value])->fails())->toBeTrue("expected {$key}={$value} to be refused");
    }

    // The port range must not be inverted.
    expect($validator([...$valid, 'port_min' => 30000, 'port_max' => 20000])->fails())->toBeTrue('an inverted port range must be refused')
        ->and($validator([...$valid, 'port_min' => 30000, 'port_max' => 30000])->passes())->toBeTrue('a one-port range is allowed');
});

test('the configuration falls back to safe defaults when nothing is stored', function () {
    ExtensionSetting::query()->where('extension', ProxySettings::EXTENSION_ID)->delete();
    app()->forgetInstance(ExtensionRepository::class);
    $config = proxySettings();

    expect([$config->enabled, $config->egg, $config->node])->toEqual([false, null, null])
        ->and($config->portRange())->toBeNull()
        ->and($config->maxProxies)->toBe(0)
        ->and([$config->memory, $config->cpu, $config->disk, $config->swap, $config->io])->toEqual([128, 50, 512, 0, 500])
        ->and([$config->backupLimit, $config->databaseLimit, $config->allocationLimit])->toEqual([1, 0, 0])
        ->and($config->namePattern)->toBe('{username}-proxy');
});

test('an allocation taken concurrently is retried once with the next free allocation', function () {
    $first = proxyAllocation($this->node, 25565);
    $second = proxyAllocation($this->node, 25566);
    $flaky = new FlakyCreatesServers(app(CreatesServers::class), 1);
    app()->instance(CreatesServers::class, $flaky);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertCreated();

    expect($flaky->calls)->toBe(2)
        ->and(Server::query()->where('owner_id', $user->id)->sole()->allocation_id)->toBe($second->id)
        ->and($first->refresh()->server_id)->toBeNull()
        ->and(UserProxy::query()->where('user_id', $user->id)->count())->toBe(1);
});

test('an allocation lost on the retry too is refused with 503 and nothing is persisted', function () {
    proxyAllocation($this->node, 25565);
    proxyAllocation($this->node, 25566);
    app()->instance(CreatesServers::class, new FlakyCreatesServers(app(CreatesServers::class), 5));
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertStatus(503);

    expect(proxyError($response))->toBe(ProxyProvisioner::NO_RESERVE)
        ->and(Server::query()->count())->toBe(0)
        ->and(UserProxy::query()->count())->toBe(0)
        ->and(Allocation::query()->whereNull('server_id')->count())->toBe(2);
});

test('the proxy cap counts every proxy-egg server, including ones an admin made without a recorded owner row', function () {
    proxyAllocation($this->node, 25565);
    proxyAllocation($this->node, 25566);
    setProxySetting('max_proxies', 1);
    $this->createServerModel(['egg_id' => $this->egg->id, 'node_id' => $this->node->id]);
    $user = User::factory()->create();

    $response = $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertStatus(422);

    expect(proxyError($response))->toBe(ProxyProvisioner::LIMIT_REACHED)
        ->and(Server::query()->where('owner_id', $user->id)->count())->toBe(0);
});

test('a create refused because the port range is exhausted is logged at info with ids and reason only', function () {
    Log::spy();
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertStatus(422);

    Log::shouldHaveReceived('info')
        ->withArgs(fn (string $message, array $context): bool => $context['reason'] === 'ports_exhausted'
            && $context['user_id'] === $user->id
            && $context['node_id'] === $this->node->id
            && array_keys($context) === ['reason', 'user_id', 'node_id'])
        ->once();
});

test('a failed create does not leave the customer or node locks held', function () {
    proxyWings(500, 'node is busy');
    proxyAllocation($this->node, 25565);
    $user = User::factory()->create();

    $this->actingAs($user)->postJson(PROXY_CREATE_URL)->assertStatus(503);

    $probe = DB::connection('probe');
    expect((int) $probe->selectOne('SELECT IS_FREE_LOCK(?) AS free', [ProxyProvisioner::lockName('u', $user->id)])->free)->toBe(1)
        ->and((int) $probe->selectOne('SELECT IS_FREE_LOCK(?) AS free', [ProxyProvisioner::lockName('n', $this->node->id)])->free)->toBe(1);
});
