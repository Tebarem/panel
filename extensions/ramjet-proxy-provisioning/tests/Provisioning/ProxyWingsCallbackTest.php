<?php

declare(strict_types=1);

use Illuminate\Http\Client\Factory;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Models\Allocation;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\ExtensionSetting;
use Pterodactyl\Models\Node;
use Pterodactyl\Models\User;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Ramjet\ProxyProvisioning\Models\UserProxy;
use Ramjet\ProxyProvisioning\ProxySettings;

require_once __DIR__.'/../Support/enables-ramjet-proxy.php';

/**
 * Deliberately NOT transactional. Wings reads the server back through the panel while the create call is still in
 * flight, on another database connection; a test inside an open transaction would hide the row from that read and
 * prove nothing. Every row written here is removed in the finally block.
 */
uses(IntegrationTestCase::class);

const CALLBACK_CREATE_URL = '/api/client/extensions/ramjet-proxy-provisioning/create';

test('the server row is committed before Wings is asked to create it, so its callback into the panel can read it', function () {
    ramjetProxyEnable();
    config(['database.connections.probe' => config('database.connections.mysql')]);

    $egg = Egg::factory()->create(['name' => 'Callback Proxy', 'startup' => './proxy', 'docker_images' => ['Ramjet Proxy' => '127.0.0.1:5000/ramjet/proxy:stable']]);
    $node = Node::factory()->withLocation()->create();
    $allocation = Allocation::factory()->create(['node_id' => $node->id, 'port' => 25565]);
    $user = User::factory()->create();

    $settings = app(ExtensionRepository::class)->settings(ProxySettings::EXTENSION_ID);
    $settings->set('enabled', true);
    $settings->set('egg', $egg->id);
    $settings->set('node', $node->id);
    $settings->set('port_min', 25000);
    $settings->set('port_max', 26000);

    $observed = [];
    $factory = new Factory;
    Http::swap($factory);
    $factory->fake(function (Request $request) use (&$observed, $factory) {
        if ($request->method() === 'POST' && str_ends_with($request->url(), '/api/servers')) {
            // What Wings does on the callback: a read through a separate connection, while the create is in flight.
            $observed['row_visible_to_second_connection'] = DB::connection('probe')
                ->table('servers')
                ->where('uuid', $request->data()['uuid'])
                ->exists();
            $observed['start_on_completion'] = $request->data()['start_on_completion'];

            return $observed['row_visible_to_second_connection']
                ? $factory->response('', 204)
                : $factory->response('{"error":"not found"}', 500);
        }

        return $factory->response('', 204);
    });

    try {
        $this->actingAs($user)->postJson(CALLBACK_CREATE_URL)->assertCreated();

        expect($observed['row_visible_to_second_connection'] ?? false)->toBeTrue()
            ->and($observed['start_on_completion'] ?? null)->toBeTrue()
            ->and(UserProxy::query()->where('user_id', $user->id)->exists())->toBeTrue();
    } finally {
        UserProxy::query()->where('user_id', $user->id)->delete();
        DB::table('server_variables')->whereIn('server_id', DB::table('servers')->where('owner_id', $user->id)->pluck('id'))->delete();
        DB::table('servers')->where('owner_id', $user->id)->delete();
        DB::table('allocations')->where('node_id', $node->id)->delete();
        DB::table('nodes')->where('id', $node->id)->delete();
        DB::table('locations')->where('id', $node->location_id)->delete();
        DB::table('egg_variables')->where('egg_id', $egg->id)->delete();
        DB::table('eggs')->where('id', $egg->id)->delete();
        DB::table('users')->where('id', $user->id)->delete();
        ExtensionSetting::query()->where('extension', ProxySettings::EXTENSION_ID)->delete();
        app()->forgetInstance(ExtensionRepository::class);
    }
});
