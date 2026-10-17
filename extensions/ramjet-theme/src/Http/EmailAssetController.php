<?php

declare(strict_types=1);

namespace Ramjet\Theme\Http;

use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the emblem that email clients load. Mail is read on other hosts with no access to the panel's build, so the
 * image has a fixed URL (built asset paths are content-hashed) and is cached for a year.
 */
final class EmailAssetController
{
    /** The files in resources/email that are served; anything else is a 404 from the route constraint. */
    public const array ASSETS = ['emblem.png', 'emblem@2x.png'];

    public function __invoke(string $asset): BinaryFileResponse
    {
        return response()->file(
            dirname(__DIR__, 2).DIRECTORY_SEPARATOR.'resources'.DIRECTORY_SEPARATOR.'email'.DIRECTORY_SEPARATOR.$asset,
            [
                'Content-Type' => 'image/png',
                'Cache-Control' => 'public, max-age=31536000, immutable',
            ],
        );
    }
}
