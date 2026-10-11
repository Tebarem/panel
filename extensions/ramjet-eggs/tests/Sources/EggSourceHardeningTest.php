<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Tests\Sources;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Pterodactyl\Services\Extensions\ExtensionRepository;
use Pterodactyl\Tests\Integration\IntegrationTestCase;
use Ramjet\Eggs\Rules\EggRules;
use Ramjet\Eggs\Rules\RuleSet;
use Ramjet\Eggs\Sources\EggSource;
use Ramjet\Eggs\Sources\EggSourceAddressPolicy;
use Ramjet\Eggs\Sources\EggSourceClient;
use Ramjet\Eggs\Sources\EggSourceEgg;
use Ramjet\Eggs\Sources\EggSourceSyncService;
use Ramjet\Eggs\Sources\HostResolver;
use RuntimeException;

require_once __DIR__.'/../Support/enables-ramjet-eggs.php';

uses(IntegrationTestCase::class, DatabaseTransactions::class);

beforeEach(function (): void {
    ramjetEggsEnable();
    app()->instance(HostResolver::class, Fixtures::resolver());
    app()->make(ExtensionRepository::class)->settings('ramjet-eggs')->set('allow_private_hosts', false);
});

/** Addresses that libcurl would parse itself, names that do not resolve, and names that resolve to a private address. */
dataset('refused addresses', [
    'hex IPv4 for the metadata address' => ['http://0xa9fea9fe/egg.json'],
    'hex IPv4 for loopback' => ['http://0x7f000001/egg.json'],
    'integer IPv4 for loopback' => ['https://2852039166/egg.json'],
    'shortened IPv4' => ['https://127.1/egg.json'],
    'octal IPv4' => ['https://0177.0.0.1/egg.json'],
    'trailing dot on localhost' => ['https://localhost./egg.json'],
    'scope id on a link-local address' => ['https://[fe80::1%25eth0]/egg.json'],
    'name that does not resolve' => ['https://nxdomain.test/egg.json'],
    'name that resolves to the metadata address' => ['https://rebind.test/egg.json'],
    'name that resolves to a private address' => ['https://private.test/egg.json'],
    'IPv4-mapped metadata address' => ['https://[::ffff:169.254.169.254]/egg.json'],
    'NAT64 metadata address' => ['https://[64:ff9b::a9fe:a9fe]/egg.json'],
    'IPv4-mapped private address' => ['https://[::ffff:10.0.0.5]/egg.json'],
    'IPv4-compatible loopback' => ['https://[::127.0.0.1]/egg.json'],
    'unspecified IPv4 address' => ['https://0.0.0.0/egg.json'],
    'unspecified IPv6 address' => ['https://[::]/egg.json'],
]);

test('addresses in any other form than a plain name or literal address are refused', function (string $url): void {
    $source = Fixtures::source(['type' => EggSource::TYPE_URL, 'path' => $url, 'repository' => null, 'branch' => null]);

    $result = app(EggSourceSyncService::class)->handle($source);

    expect($result['error'])->not->toBeNull()
        ->and($result['files'])->toBe([]);
    Http::assertNothingSent();
})->with('refused addresses');

dataset('always refused addresses', [
    'metadata address' => ['http://169.254.169.254/latest/meta-data/egg.json'],
    'IPv4-mapped metadata address' => ['https://[::ffff:169.254.169.254]/egg.json'],
    'NAT64 metadata address' => ['https://[64:ff9b::a9fe:a9fe]/egg.json'],
    'name that resolves to the metadata address' => ['https://rebind.test/egg.json'],
    'name that resolves to the metadata address, by name' => ['https://nip.test/egg.json'],
    'link-local IPv6 address' => ['https://[fe80::1]/egg.json'],
]);

test('link-local and metadata addresses are refused even when private hosts are allowed', function (string $url): void {
    app()->make(ExtensionRepository::class)->settings('ramjet-eggs')->set('allow_private_hosts', true);
    $source = Fixtures::source(['type' => EggSource::TYPE_URL, 'path' => $url, 'repository' => null, 'branch' => null]);

    $result = app(EggSourceSyncService::class)->handle($source);

    expect($result['error'])->not->toBeNull();
    Http::assertNothingSent();
})->with('always refused addresses');

test('a name that resolves to public addresses is allowed and the validated addresses are returned', function (): void {
    $addresses = app(EggSourceAddressPolicy::class)->assertAllowed('https://example.test/egg.json');

    expect($addresses)->toBe([StubHostResolver::PUBLIC_ADDRESS]);
});

test('a name that resolves to nothing is refused rather than requested', function (): void {
    expect(fn () => app(EggSourceAddressPolicy::class)->assertAllowed('https://nxdomain.test/egg.json'))
        ->toThrow(RuntimeException::class, 'could not be resolved');
});

test('a private address is reachable when the extension setting allows private hosts', function (): void {
    app()->make(ExtensionRepository::class)->settings('ramjet-eggs')->set('allow_private_hosts', true);
    Http::fake(['https://private.test/egg.json' => Http::response(Fixtures::eggJson('Internal'))]);

    $source = Fixtures::source(['type' => EggSource::TYPE_URL, 'path' => 'https://private.test/egg.json', 'repository' => null, 'branch' => null]);

    expect(app(EggSourceSyncService::class)->handle($source)['files'][0]['status'])->toBe('created');
});

test('a file with more hidden patterns than the limit fails and nothing is imported', function (): void {
    $source = Fixtures::source();
    $hidden = array_map(fn (int $i): string => "pattern-$i", range(1, 501));

    Http::fake([
        Fixtures::GITHUB_TREE => Http::response(['truncated' => false, 'tree' => [['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob']]]),
        Fixtures::GITHUB_FILE_A => Http::response(Fixtures::eggJson('Minecraft', hidden: $hidden)),
    ]);

    $result = app(EggSourceSyncService::class)->handle($source);

    expect($result['files'][0]['status'])->toBe('failed')
        ->and((string) $result['files'][0]['message'])->toContain('may contain at most')
        ->and(EggSourceEgg::query()->where('source_id', $source->id)->count())->toBe(0);
});

test('a file whose union with the rules of an egg is over the limit fails and leaves the egg as it was', function (): void {
    $source = Fixtures::source();
    $first = array_map(fn (int $i): string => "old-$i", range(1, 300));

    Http::fake([
        Fixtures::GITHUB_TREE => Http::response(['truncated' => false, 'tree' => [['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob']]]),
        Fixtures::GITHUB_FILE_A => Http::response(Fixtures::eggJson('Minecraft', hidden: $first)),
    ]);
    app(EggSourceSyncService::class)->handle($source);
    $egg = Fixtures::importedEgg($source, 'eggs/minecraft/egg-a.json');

    // The admin adds rules in the panel, so the union with the next file exceeds the limit.
    $rules = app(EggRules::class);
    $rules->set($egg, new RuleSet(array_map(fn (int $i): string => "own-$i", range(1, 300)), []));
    $before = $rules->get($egg->refresh());

    Http::swap(new HttpFactory());
    Http::fake([
        Fixtures::GITHUB_TREE => Http::response(['truncated' => false, 'tree' => [['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob']]]),
        Fixtures::GITHUB_FILE_A => Http::response(Fixtures::eggJson('Minecraft', hidden: array_map(fn (int $i): string => "new-$i", range(1, 300)))),
    ]);

    $result = app(EggSourceSyncService::class)->handle($source);

    expect($result['files'][0]['status'])->toBe('failed')
        ->and($rules->get($egg->refresh()))->toEqual($before);
});

test('an unchanged blob id is not downloaded again, and a changed one is', function (): void {
    $source = Fixtures::source();

    Http::fake([
        Fixtures::GITHUB_TREE => Http::response(['truncated' => false, 'tree' => [['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob', 'sha' => 'sha-1']]]),
        Fixtures::GITHUB_FILE_A => Http::response(Fixtures::eggJson('Minecraft')),
    ]);
    app(EggSourceSyncService::class)->handle($source);

    Http::swap(new HttpFactory());
    Http::fake([
        Fixtures::GITHUB_TREE => Http::response(['truncated' => false, 'tree' => [['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob', 'sha' => 'sha-1']]]),
        Fixtures::GITHUB_FILE_A => Http::response(Fixtures::eggJson('Changed Without A New Blob')),
    ]);

    $unchanged = app(EggSourceSyncService::class)->handle($source);

    expect($unchanged['files'][0]['status'])->toBe('unchanged')
        ->and(Fixtures::importedEgg($source, 'eggs/minecraft/egg-a.json')->name)->toBe('Minecraft');
    Http::assertSentCount(1);

    Http::swap(new HttpFactory());
    Http::fake([
        Fixtures::GITHUB_TREE => Http::response(['truncated' => false, 'tree' => [['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob', 'sha' => 'sha-2']]]),
        Fixtures::GITHUB_FILE_A => Http::response(Fixtures::eggJson('Changed')),
    ]);

    $changed = app(EggSourceSyncService::class)->handle($source);

    expect($changed['files'][0]['status'])->toBe('updated')
        ->and(Fixtures::importedEgg($source, 'eggs/minecraft/egg-a.json')->name)->toBe('Changed');
});

test('a sync that finds its source already running is refused without touching the provider', function (): void {
    $source = Fixtures::source();
    $lock = Cache::lock(EggSourceSyncService::lockKey($source), 60);
    expect($lock->get())->toBeTrue();

    Http::fake();
    $result = app(EggSourceSyncService::class)->handle($source);
    $lock->release();

    expect((string) $result['error'])->toContain('already running');
    Http::assertNothingSent();
});

test('an unchanged second sync sends no request to Wings, and a changed one does', function (): void {
    $url = 'https://example.test/eggs/minecraft.json';
    $source = Fixtures::source(['type' => EggSource::TYPE_URL, 'path' => $url, 'repository' => null, 'branch' => null]);

    Http::fake([$url => Http::response(Fixtures::eggJson('Minecraft'))]);
    app(EggSourceSyncService::class)->handle($source);

    $egg = Fixtures::importedEgg($source, $url);
    $server = $this->createServerModel(['egg_id' => $egg->id]);

    Http::swap(new HttpFactory());
    Http::fake([$url => Http::response(Fixtures::eggJson('Minecraft')), '*' => Http::response(status: 204)]);
    app(EggSourceSyncService::class)->handle($source);

    Http::assertNotSent(fn ($request) => str_contains($request->url(), $server->uuid));

    // The same check proves the Wings request is recognised: a changed rule does reach the node.
    Http::swap(new HttpFactory());
    Http::fake([$url => Http::response(Fixtures::eggJson('Minecraft', ['/plugins/New.jar'])), '*' => Http::response(status: 204)]);
    app(EggSourceSyncService::class)->handle($source);

    Http::assertSent(fn ($request) => str_contains($request->url(), $server->uuid));
});

test('a listing that is over the file limit stops paging at once', function (): void {
    $source = Fixtures::source([
        'type' => EggSource::TYPE_GITLAB,
        'base_url' => 'https://gitlab.example.com',
        'repository' => 'acme/eggs',
        'branch' => 'main',
        'path' => 'eggs',
    ]);

    $page = array_map(fn (int $i): array => ['path' => "eggs/egg-$i.json", 'type' => 'blob'], range(1, EggSourceClient::MAX_FILES + 1));

    Http::fake([
        'https://gitlab.example.com/api/v4/projects/acme%2Feggs/repository/tree*' => Http::sequence()
            ->push($page, 200, ['X-Next-Page' => '2'])
            ->push([['path' => 'eggs/egg-x.json', 'type' => 'blob']], 200, ['X-Next-Page' => '']),
    ]);

    $result = app(EggSourceSyncService::class)->handle($source);

    expect((string) $result['error'])->toContain('limit');
    Http::assertSentCount(1);
});

test('a listing larger than the listing limit is refused', function (): void {
    $source = Fixtures::source();
    Http::fake([
        Fixtures::GITHUB_TREE => Http::response(str_repeat('x', EggSourceClient::LISTING_MAX_BYTES + 1)),
    ]);

    $result = app(EggSourceSyncService::class)->handle($source);

    expect((string) $result['error'])->toContain('10 MiB');
});

test('files whose path has an empty, "." or ".." segment are never listed or requested', function (): void {
    $source = Fixtures::source(['path' => 'eggs']);

    Http::fake([
        Fixtures::GITHUB_TREE => Http::response([
            'truncated' => false,
            'tree' => [
                ['path' => 'eggs/../secret.json', 'type' => 'blob'],
                ['path' => 'eggs/./minecraft/x.json', 'type' => 'blob'],
                ['path' => 'eggs/minecraft/egg-a.json', 'type' => 'blob'],
            ],
        ]),
        Fixtures::GITHUB_FILE_A => Http::response(Fixtures::eggJson('Minecraft')),
    ]);

    $result = app(EggSourceSyncService::class)->handle($source);

    expect(array_column($result['files'], 'path'))->toBe(['eggs/minecraft/egg-a.json']);
    Http::assertSentCount(2);
});

test('a directory with a "." or ".." segment is refused', function (): void {
    $source = Fixtures::source(['path' => 'eggs/../etc']);

    $result = app(EggSourceSyncService::class)->handle($source);

    expect((string) $result['error'])->toContain('must not contain');
    Http::assertNothingSent();
});
