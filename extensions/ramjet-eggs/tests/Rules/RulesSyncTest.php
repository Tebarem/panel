<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Pterodactyl\Models\Egg;
use Pterodactyl\Models\Server;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Ramjet\Eggs\Rules\EggRules;
use Ramjet\Eggs\Rules\RuleSet;
use Ramjet\Eggs\Wings\EggServerSync;
use Ramjet\Eggs\Wings\SyncEggServers;

require_once __DIR__.'/../Support/enables-ramjet-eggs.php';

uses(IntegrationTestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    ramjetEggsEnable();
    Http::fake(['*' => Http::response(status: 204)]);
});

test('re-saving unchanged rules syncs nothing', function (): void {
    $server = $this->createServerModel();
    $rules = app(EggRules::class);

    $rules->set($server->egg, new RuleSet(['a.txt'], []));
    ramjetEggsSettle();
    Http::assertSentCount(1);

    $rules->set($server->egg->refresh(), new RuleSet(['a.txt'], []));
    ramjetEggsSettle();
    Http::assertSentCount(1);
});

test('changing rules and changing them back within one transaction syncs nothing', function (): void {
    $server = $this->createServerModel();
    $rules = app(EggRules::class);
    $egg = $server->egg;

    DB::transaction(function () use ($rules, $egg): void {
        $rules->set($egg, new RuleSet(['a.txt'], []));
        $rules->set($egg, new RuleSet([], []));
    });

    ramjetEggsSettle();
    Http::assertSentCount(0);
});

test('several changes to one egg in one transaction sync its servers once', function (): void {
    $server = $this->createServerModel();
    $rules = app(EggRules::class);
    $egg = $server->egg;

    DB::transaction(function () use ($rules, $egg): void {
        $rules->set($egg, new RuleSet(['a.txt'], []));
        $rules->set($egg, new RuleSet(['a.txt', 'b.txt'], ['eula.txt']));
    });

    ramjetEggsSettle();
    Http::assertSentCount(1);
});

test('a change to a parent egg syncs the servers of its children once', function (): void {
    $parent = $this->createServerModel()->egg;
    $child = Egg::factory()->create([
        'config_from' => $parent->id,
        'config_files' => '{}',
        'config_startup' => '{}',
        'config_logs' => null,
    ]);
    $this->createServerModel(['egg_id' => $child->id]);
    $rules = app(EggRules::class);

    DB::transaction(function () use ($rules, $parent): void {
        $rules->set($parent, new RuleSet(['a.txt'], []));
        $rules->set($parent, new RuleSet(['a.txt', 'b.txt'], []));
    });

    ramjetEggsSettle();
    Http::assertSentCount(2);
});

test('a change that does not reach a child syncs only the parent servers', function (): void {
    $parent = $this->createServerModel()->egg;
    $child = Egg::factory()->create([
        'config_from' => $parent->id,
        'config_files' => '{}',
        'config_startup' => '{}',
        'config_logs' => null,
    ]);
    $this->createServerModel(['egg_id' => $child->id]);

    // Own rules of a child do not reach Wings while it copies from its parent.
    app(EggRules::class)->set($child, new RuleSet(['own.txt'], []));

    ramjetEggsSettle();
    Http::assertSentCount(0);
});

test('a failed connection to a node skips the rest of that node\'s servers', function (): void {
    $first = $this->createServerModel();
    $second = $this->createServerModel(['node_id' => $first->node_id, 'egg_id' => $first->egg_id]);
    $other = $this->createServerModel(['egg_id' => $first->egg_id]);

    $attempted = [];
    Http::fake(function (Request $request) use ($first, &$attempted) {
        $attempted[] = $request->url();
        if (str_contains($request->url(), $first->node->fqdn)) {
            throw new ConnectionException('node unreachable');
        }

        return Http::response(status: 204);
    });

    app(EggServerSync::class)->resync(Server::query()->with('node')->whereIn('id', [$first->id, $second->id, $other->id])->orderBy('id')->get());

    // The first server fails, the second shares its node and is never attempted, the third is on another node.
    expect($attempted)->toHaveCount(2);
    expect($other->node_id)->not->toBe($first->node_id);
});

test('more than the inline limit of affected servers is queued, not synced inline', function (): void {
    Queue::fake();
    $server = $this->createServerModel();
    $egg = $server->egg;
    foreach (range(1, EggServerSync::INLINE_LIMIT) as $_) {
        $this->createServerModel(['egg_id' => $egg->id]);
    }

    app(EggRules::class)->set($egg, new RuleSet(['a.txt'], []));

    ramjetEggsSettle();
    Queue::assertPushed(SyncEggServers::class, fn (SyncEggServers $job): bool => count($job->serverIds) === EggServerSync::INLINE_LIMIT + 1);
    ramjetEggsSettle();
    Http::assertSentCount(0);
});
