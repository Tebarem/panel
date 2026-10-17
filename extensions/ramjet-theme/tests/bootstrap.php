<?php

declare(strict_types=1);

// Test bootstrap for the ramjet-theme extension. The extension's classes are autoloaded from the package directory
// (its manifest is not in the panel's test extensions directory), then the panel's own bootstrap runs.
//
// Run from the panel root:
//   SKIP_MIGRATIONS=1 vendor/bin/pest -c extensions/ramjet-theme/tests/phpunit.xml
// The panel's bootstrap refuses a database whose name lacks "test"; the tests here never query it.

$packageRoot = dirname(__DIR__);

spl_autoload_register(function (string $class) use ($packageRoot): void {
    $prefixes = [
        'Ramjet\\Theme\\Tests\\' => $packageRoot.'/tests/',
        'Ramjet\\Theme\\' => $packageRoot.'/src/',
    ];

    foreach ($prefixes as $prefix => $directory) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }

        $file = $directory.str_replace('\\', '/', mb_substr($class, mb_strlen($prefix))).'.php';
        if (is_file($file)) {
            require $file;
        }

        return;
    }
});

require dirname(__DIR__, 3).'/bootstrap/tests.php';
