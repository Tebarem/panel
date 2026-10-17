<?php

declare(strict_types=1);

use Illuminate\Mail\Markdown;
use Illuminate\Notifications\Messages\MailMessage;
use Pterodactyl\Services\Extensions\ExtensionManifestValidator;
use Pterodactyl\Tests\TestCase;
use Ramjet\Theme\Email\PreviewNotifications;
use Ramjet\Theme\RamjetThemeProvider;

uses(TestCase::class);

const RAMJET_EMAIL_SLUGS = [
    'account-created',
    'password-reset',
    'server-installed',
    'added-to-server',
    'removed-from-server',
    'mail-test',
];

/** Boots the theme's provider on the test application, as the panel does for an enabled extension. */
function bootRamjetTheme(): void
{
    $app = app();
    $manifest = $app->make(ExtensionManifestValidator::class)->fromDirectory(dirname(__DIR__));

    $app->register(new RamjetThemeProvider($app, $manifest), force: true);
}

/**
 * Renders one of the panel's notifications the way MailChannel does: the HTML part from MailMessage::render(), and the
 * text part from Markdown::renderText() with the mail's view data.
 *
 * @return array{message: MailMessage, html: string, text: string}
 */
function renderRamjetSample(string $slug, string $userName = 'Ada Lovelace', string $serverName = 'Survival Realm'): array
{
    $sample = collect(PreviewNotifications::samples($userName, $serverName))->firstWhere('slug', $slug);
    $message = $sample['notification']->toMail($sample['notifiable']);

    return [
        'message' => $message,
        'html' => (string) $message->render(),
        'text' => (string) app(Markdown::class)->renderText($message->markdown, $message->data()),
    ];
}

it('renders every notification with the brand palette and the emblem', function (string $slug): void {
    bootRamjetTheme();

    $rendered = renderRamjetSample($slug);

    expect($rendered['html'])
        ->toContain('#0a0b0d')
        ->toContain('#ff7a1a')
        ->toContain('#eceef1')
        ->toContain('prefers-color-scheme: dark')
        ->toContain('https://panel.ramjet.gg/extensions/ramjet-theme/email/emblem.png')
        ->toContain('alt="Ramjet"')
        ->not->toContain('#F2F4F6'); // core's template background
})->with(RAMJET_EMAIL_SLUGS);

it('puts the call to action in the HTML and the plain text', function (string $slug): void {
    bootRamjetTheme();

    $rendered = renderRamjetSample($slug);
    $url = $rendered['message']->actionUrl;

    if ($url === null) {
        expect($rendered['html'])->not->toContain('trouble clicking');

        return;
    }

    expect($rendered['html'])
        ->toContain('href="'.e($url).'"')
        ->toContain('trouble clicking');
    expect($rendered['text'])->toContain($url);
})->with(RAMJET_EMAIL_SLUGS);

it('uses the error colour for error-level mail', function (): void {
    bootRamjetTheme();

    $rendered = renderRamjetSample('removed-from-server');

    expect($rendered['message']->level)->toBe('error')
        ->and($rendered['html'])->toContain('#ff6b6b');
});

it('sends the plain-text template as the text part, not the HTML', function (string $slug): void {
    bootRamjetTheme();

    $text = renderRamjetSample($slug)['text'];

    expect($text)
        ->toContain('Ramjet')
        ->toContain('https://ramjet.gg')
        ->toContain('You received this email because you have an account on Ramjet.')
        ->not->toContain('<table')
        ->not->toContain('<html')
        ->not->toContain('<td');
})->with(RAMJET_EMAIL_SLUGS);

it('escapes a malicious server or user name in the HTML part', function (string $slug): void {
    bootRamjetTheme();

    $payload = '<script>alert(1)</script>';
    $rendered = renderRamjetSample($slug, $payload, $payload);

    expect($rendered['html'])->not->toContain('<script>');

    // Mails that name the user or the server must show the payload as text. Password reset names neither.
    if ($slug !== 'password-reset') {
        expect($rendered['html'])->toContain('&lt;script&gt;alert(1)&lt;/script&gt;');
    }
})->with(RAMJET_EMAIL_SLUGS);

it('keeps core\'s template in use when the theme is not booted', function (): void {
    $rendered = renderRamjetSample('added-to-server');

    expect($rendered['html'])
        ->toContain('#F2F4F6')
        ->not->toContain('emblem.png')
        ->not->toContain('#0a0b0d');
});

it('routes the emblem at a stable URL', function (): void {
    bootRamjetTheme();

    // Found by iterating: RouteCollection's name lookup misses a name applied after the route was added.
    $routes = collect(app('router')->getRoutes()->getRoutes());
    $route = $routes->first(fn ($route): bool => $route->getName() === 'extensions.ramjet-theme.web.email.asset');

    expect($route)->not->toBeNull()
        ->and($route->uri())->toBe('extensions/ramjet-theme/email/{asset}');
});
