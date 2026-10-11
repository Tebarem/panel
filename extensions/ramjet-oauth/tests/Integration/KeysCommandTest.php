<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Tests\Integration;

use Illuminate\Support\Str;
use Laravel\Passport\Passport;
use League\OAuth2\Server\CryptKey;
use Pterodactyl\Models\User;
use Ramjet\OAuth\Tests\OAuthTestCase;

class KeysCommandTest extends OAuthTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();

        $this->directory = sys_get_temp_dir().DIRECTORY_SEPARATOR.'ramjet-oauth-keys-'.Str::random(12);
        mkdir($this->directory, 0o700, true);
        Passport::loadKeysFrom($this->directory);
        config(['passport.private_key' => null, 'passport.public_key' => null]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->directory.'/*') ?: [] as $file) {
            @unlink($file);
        }
        @rmdir($this->directory);
        Passport::loadKeysFrom(storage_path());
        Passport::$validateKeyPermissions = true;

        parent::tearDown();
    }

    public function test_generates_missing_keys_and_keeps_existing_ones(): void
    {
        $this->artisan('ramjet-oauth:keys')->assertSuccessful();

        $private = file_get_contents($this->directory.'/oauth-private.key');
        $public = file_get_contents($this->directory.'/oauth-public.key');
        $this->assertStringContainsString('PRIVATE KEY', (string) $private);
        $this->assertStringContainsString('PUBLIC KEY', (string) $public);

        // Running it again must not replace keys that tokens depend on.
        $this->artisan('ramjet-oauth:keys')->assertSuccessful();
        $this->assertSame($private, file_get_contents($this->directory.'/oauth-private.key'));
    }

    public function test_force_replaces_the_keys(): void
    {
        $this->artisan('ramjet-oauth:keys')->assertSuccessful();
        $before = file_get_contents($this->directory.'/oauth-private.key');

        $this->artisan('ramjet-oauth:keys', ['--force' => true])->assertSuccessful();

        $this->assertNotSame($before, file_get_contents($this->directory.'/oauth-private.key'));
    }

    public function test_refuses_to_run_when_only_one_key_file_exists(): void
    {
        file_put_contents($this->directory.'/oauth-private.key', 'stale');

        $this->artisan('ramjet-oauth:keys')->assertFailed();

        // The lone file is left alone, and no partner key is written next to it.
        $this->assertSame('stale', file_get_contents($this->directory.'/oauth-private.key'));
        $this->assertFileDoesNotExist($this->directory.'/oauth-public.key');
    }

    public function test_force_replaces_a_lone_key_file(): void
    {
        file_put_contents($this->directory.'/oauth-private.key', 'stale');

        $this->artisan('ramjet-oauth:keys', ['--force' => true])->assertSuccessful();

        $this->assertStringContainsString('PRIVATE KEY', (string) file_get_contents($this->directory.'/oauth-private.key'));
        $this->assertFileExists($this->directory.'/oauth-public.key');
    }

    public function test_key_files_have_the_modes_passport_accepts(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('File modes are not enforced on Windows.');
        }

        $this->artisan('ramjet-oauth:keys')->assertSuccessful();

        $this->assertSame('600', decoct(fileperms($this->directory.'/oauth-private.key') & 0o777));
        $this->assertSame('660', decoct(fileperms($this->directory.'/oauth-public.key') & 0o777));
    }

    /**
     * Passport checks the key file modes (validateKeyPermissions) and refuses files other users can read. A key pair
     * with the wrong mode makes every token and userinfo request fail, so the generated files must pass the check.
     */
    public function test_generated_keys_pass_passports_permission_check_and_tokens_work(): void
    {
        if (DIRECTORY_SEPARATOR === '\\') {
            $this->markTestSkipped('File modes are not enforced on Windows.');
        }

        Passport::$validateKeyPermissions = true;
        $this->artisan('ramjet-oauth:keys')->assertSuccessful();

        // Any permission warning is raised as an exception by the test error handler.
        $this->assertInstanceOf(CryptKey::class, new CryptKey('file://'.$this->directory.'/oauth-private.key', null, true));
        $this->assertInstanceOf(CryptKey::class, new CryptKey('file://'.$this->directory.'/oauth-public.key', null, true));

        $user = User::factory()->create();
        $token = $this->issueAccessToken($user, ['openid']);

        $this->withToken($token)
            ->getJson('/oauth/userinfo')
            ->assertOk()
            ->assertJsonPath('sub', $user->uuid);
    }
}
