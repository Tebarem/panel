<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel as ConsoleKernel;

/*
 * Test bootstrap for the ramjet-oauth extension.
 *
 * Run with (from the panel root):
 *   vendor/bin/pest --bootstrap extensions/ramjet-oauth/tests/bootstrap.php extensions/ramjet-oauth/tests
 *
 * The panel's own bootstrap runs first. It points the application at the testing database and rebuilds it with
 * migrate:fresh, so the extension's tables are not there yet. They are applied here, the same way the panel applies
 * an extension's migrations when the extension is enabled. Tests then enable the extension through the provider
 * loader (see OAuthTestCase).
 */

// Defines $app and $kernel for the panel's test environment.
require dirname(__DIR__, 3).'/bootstrap/tests.php';

/** @var ConsoleKernel $kernel */
$kernel->call('migrate', [
    '--path' => realpath(__DIR__.'/../database/migrations'),
    '--realpath' => true,
    '--force' => true,
]);

// Test classes of this extension live in Ramjet\OAuth\Tests\ and are not part of any Composer autoload map.
spl_autoload_register(function (string $class): void {
    $prefix = 'Ramjet\\OAuth\\Tests\\';

    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $file = __DIR__.'/'.str_replace('\\', '/', mb_substr($class, mb_strlen($prefix))).'.php';
    if (is_file($file)) {
        require $file;
    }
});
