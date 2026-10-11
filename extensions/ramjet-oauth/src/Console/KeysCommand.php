<?php

declare(strict_types=1);

namespace Ramjet\OAuth\Console;

use Illuminate\Console\Command;
use Laravel\Passport\Passport;
use RuntimeException;

/**
 * Generates the RSA key pair that signs OAuth access tokens and OpenID Connect ID tokens, unless the keys are
 * already provided (PASSPORT_PRIVATE_KEY / PASSPORT_PUBLIC_KEY) or present in storage.
 *
 * The file modes match what Passport accepts: Passport refuses a key file readable by other users (see
 * League\OAuth2\Server\CryptKey), so a wrong mode would break every token and userinfo request.
 */
class KeysCommand extends Command
{
    private const PRIVATE_KEY_MODE = 0o600;

    private const PUBLIC_KEY_MODE = 0o660;

    protected $signature = 'ramjet-oauth:keys {--force : Replace existing key files. Every issued token and ID token stops verifying.}';

    protected $description = 'Generate the OAuth signing keys in storage, if they are missing';

    public function handle(): int
    {
        if (is_string(config('passport.private_key')) && config('passport.private_key') !== '') {
            $this->components->info('The signing keys are provided through PASSPORT_PRIVATE_KEY and PASSPORT_PUBLIC_KEY. Nothing to generate.');

            return self::SUCCESS;
        }

        $privatePath = Passport::keyPath('oauth-private.key');
        $publicPath = Passport::keyPath('oauth-public.key');

        $privateExists = file_exists($privatePath);
        $publicExists = file_exists($publicPath);

        if (! $this->option('force')) {
            if ($privateExists && $publicExists) {
                $this->components->info('The OAuth signing keys already exist. Use --force to replace them.');

                return self::SUCCESS;
            }

            // A lone key cannot sign tokens that the other one verifies. Refuse rather than mix two key pairs.
            if ($privateExists || $publicExists) {
                $this->components->error(sprintf(
                    'Only one of the OAuth key files exists ([%s]). Remove it, or run with --force to replace both keys.',
                    $privateExists ? $privatePath : $publicPath,
                ));

                return self::FAILURE;
            }
        }

        try {
            [$private, $public] = $this->generate();
        } catch (RuntimeException $exception) {
            $this->components->error($exception->getMessage());

            return self::FAILURE;
        }

        $directory = dirname($privatePath);
        if (! is_dir($directory) && ! mkdir($directory, 0o755, true) && ! is_dir($directory)) {
            $this->components->error("Unable to create the key directory [{$directory}].");

            return self::FAILURE;
        }

        // Files created while the umask is restrictive are never readable by other users, even for an instant.
        $previousUmask = umask(0o077);
        try {
            $written = file_put_contents($privatePath, $private) !== false
                && file_put_contents($publicPath, $public) !== false;
        } finally {
            umask($previousUmask);
        }

        if (! $written) {
            $this->components->error('Unable to write the key files.');

            return self::FAILURE;
        }

        // Existing files keep their old mode when overwritten, so the modes are set explicitly. Windows has no
        // equivalent of these modes.
        if (DIRECTORY_SEPARATOR === '/') {
            @chmod($privatePath, self::PRIVATE_KEY_MODE);
            @chmod($publicPath, self::PUBLIC_KEY_MODE);
        }

        $this->components->info("OAuth signing keys written to [{$privatePath}] and [{$publicPath}].");

        return self::SUCCESS;
    }

    /**
     * @return array{0: string, 1: string} the PEM encoded private and public key
     *
     * @throws RuntimeException
     */
    private function generate(): array
    {
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_RSA, 'private_key_bits' => 4096]);
        if ($key === false) {
            throw new RuntimeException('Unable to generate an RSA key pair. Check that the OpenSSL extension is available.');
        }

        $private = '';
        if (! openssl_pkey_export($key, $private)) {
            throw new RuntimeException('Unable to export the private key.');
        }

        $details = openssl_pkey_get_details($key);
        if (! is_array($details) || ! isset($details['key'])) {
            throw new RuntimeException('Unable to read the public key.');
        }

        return [$private, $details['key']];
    }
}
