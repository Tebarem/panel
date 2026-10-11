<?php

declare(strict_types=1);

// Test bootstrap for the ramjet-eggs extension. The extension is not installed in the panel's test
// database, so its classes are autoloaded from the package directory here, before the panel's own test
// bootstrap (which migrates and seeds the testing database) runs.

$packageRoot = dirname(__DIR__);

spl_autoload_register(function (string $class) use ($packageRoot): void {
    $prefixes = [
        'Ramjet\\Eggs\\Tests\\' => $packageRoot.'/tests/',
        'Ramjet\\Eggs\\' => $packageRoot.'/src/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $directory.str_replace('\\', '/', substr($class, strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }

        return;
    }
});

require dirname(__DIR__, 3).'/bootstrap/tests.php';

// The panel's bootstrap migrated core only. Apply the extension's migrations here, outside any test
// transaction: DDL inside a transaction commits it implicitly and would break per-test rollback.
$app->make(\Illuminate\Contracts\Console\Kernel::class)->call('migrate', [
    '--path' => $packageRoot.'/database/migrations',
    '--realpath' => true,
    '--force' => true,
]);
