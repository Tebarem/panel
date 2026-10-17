<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\File;
use Pterodactyl\Services\Extensions\ExtensionManifestValidator;
use Pterodactyl\Tests\TestCase;
use Ramjet\Theme\Console\EmailPreviewCommand;
use Ramjet\Theme\Http\EmailAssetController;
use Ramjet\Theme\RamjetThemeProvider;

uses(TestCase::class);

/** A fresh, empty directory under the system temp dir for one test's previews. */
function ramjetPreviewDirectory(): string
{
    return sys_get_temp_dir().DIRECTORY_SEPARATOR.'ramjet-theme-preview-'.bin2hex(random_bytes(6));
}

it('serves the emblem at two sizes with a long cache lifetime', function (string $asset, int $size): void {
    $response = (new EmailAssetController)($asset);

    expect($response->headers->get('Content-Type'))->toBe('image/png')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=31536000')
        ->and(getimagesize($response->getFile()->getPathname())[0])->toBe($size);
})->with([
    ['emblem.png', 80],
    ['emblem@2x.png', 160],
]);

it('writes every notification as HTML and plain text, with an index, and sends nothing', function (): void {
    $app = app();
    $manifest = $app->make(ExtensionManifestValidator::class)->fromDirectory(dirname(__DIR__));
    $app->register(new RamjetThemeProvider($app, $manifest), force: true);

    // The panel registers the command when the extension boots; the test application needs it added to its console kernel.
    $app->make(Kernel::class)->registerCommand(new EmailPreviewCommand);

    $directory = ramjetPreviewDirectory();

    try {
        $this->artisan('ramjet-theme:email-preview', ['directory' => $directory])->assertSuccessful();

        foreach (['account-created', 'password-reset', 'server-installed', 'added-to-server', 'removed-from-server', 'mail-test'] as $slug) {
            expect($directory.DIRECTORY_SEPARATOR.$slug.'.html')->toBeFile()
                ->and($directory.DIRECTORY_SEPARATOR.$slug.'.txt')->toBeFile();
        }

        expect($directory.DIRECTORY_SEPARATOR.'index.html')->toBeFile();
        expect(file_get_contents($directory.DIRECTORY_SEPARATOR.'account-created.html'))
            ->toContain('https://panel.ramjet.gg/extensions/ramjet-theme/email/emblem.png')
            ->not->toContain('data:image/png');
    } finally {
        File::deleteDirectory($directory);
    }
});

it('embeds the emblem as data URIs with --inline-logo', function (): void {
    $app = app();
    $manifest = $app->make(ExtensionManifestValidator::class)->fromDirectory(dirname(__DIR__));
    $app->register(new RamjetThemeProvider($app, $manifest), force: true);
    $app->make(Kernel::class)->registerCommand(new EmailPreviewCommand);

    $directory = ramjetPreviewDirectory();

    try {
        $this->artisan('ramjet-theme:email-preview', [
            'directory' => $directory,
            '--inline-logo' => true,
        ])->assertSuccessful();

        expect(file_get_contents($directory.DIRECTORY_SEPARATOR.'account-created.html'))
            ->toContain('data:image/png;base64,')
            ->not->toContain('https://panel.ramjet.gg/extensions/ramjet-theme/email/emblem');
    } finally {
        File::deleteDirectory($directory);
    }
});
