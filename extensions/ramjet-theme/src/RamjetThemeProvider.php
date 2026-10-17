<?php

declare(strict_types=1);

namespace Ramjet\Theme;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Mail\Markdown;
use Pterodactyl\Extensions\ExtensionProvider;
use Ramjet\Theme\Console\EmailPreviewCommand;
use Ramjet\Theme\Email\TextPartMarkdown;

/**
 * The theme is CSS (src/client/theme.css). The provider also server-renders the browser
 * toolbar colour, so it is right before any script or stylesheet loads, and it themes the
 * notification emails (resources/views/notifications, resources/email).
 */
class RamjetThemeProvider extends ExtensionProvider
{
    /** Equal to --theme-color in theme.css (the --background hex); `npm run contrast` checks it. */
    private const string THEME_COLOR = '#0a0b0d';

    public function boot(): void
    {
        $this->registerHeadTags([
            ['tag' => 'meta', 'name' => 'theme-color', 'content' => self::THEME_COLOR],
        ]);

        // The extension's notifications/email.blade.php and email-plain.blade.php take precedence over core's copies in
        // resources/views/vendor/notifications. Prepended, not loaded: the lookup only sees this while the provider boots,
        // so disabling the extension restores core's templates.
        $this->app->make('view')->prependNamespace('notifications', $this->extensionPath('resources', 'views', 'notifications'));

        // Laravel renders a mail's text part from the same view as its HTML part. The text part goes through the plain
        // template instead, so a message's plain-text body is not the HTML source.
        $this->app->extend(Markdown::class, fn (Markdown $markdown, Application $app): Markdown => new TextPartMarkdown($app->make('view'), [
            'theme' => $app->make('config')->get('mail.markdown.theme', 'default'),
            'paths' => $app->make('config')->get('mail.markdown.paths', []),
            'extensions' => $app->make('config')->get('mail.markdown.extensions', []),
        ]));

        $this->registerWebRoutes($this->extensionPath('routes', 'web.php'));
        $this->registerCommands([EmailPreviewCommand::class]);
    }
}
