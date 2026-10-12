<?php

declare(strict_types=1);

namespace Ramjet\Theme;

use Pterodactyl\Extensions\ExtensionProvider;

/**
 * The theme is CSS (src/client/theme.css). The provider only server-renders the browser
 * toolbar colour, so it is right before any script or stylesheet loads.
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
    }
}
