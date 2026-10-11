<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Rules\Sharing;

use Illuminate\Http\UploadedFile;
use InvalidArgumentException;
use Pterodactyl\Exceptions\Service\InvalidFileUploadException;
use Ramjet\Eggs\Rules\FilePatterns;

/**
 * Reads the `file_hidden` list from an uploaded egg export. The core parser ignores unknown keys,
 * so the extension reads its own key from the same file.
 */
final class HiddenFileRulesFromUpload
{
    /**
     * Returns null when the file has no `file_hidden` key. Exports made before the key existed
     * leave the egg's hidden rules untouched, as in 1.x.
     *
     * @return list<string>|null
     *
     * @throws InvalidFileUploadException
     */
    public static function read(UploadedFile $file): ?array
    {
        if ($file->getError() !== UPLOAD_ERR_OK || ! $file->isFile()) {
            // The core parser reports the problem with the upload itself.
            return null;
        }

        $contents = file_get_contents($file->getRealPath());
        if ($contents === false) {
            return null;
        }

        $decoded = json_decode($contents, true);
        if (! is_array($decoded) || ! array_key_exists('file_hidden', $decoded)) {
            return null;
        }

        try {
            return FilePatterns::fromList($decoded['file_hidden'], 'file_hidden');
        } catch (InvalidArgumentException $exception) {
            throw new InvalidFileUploadException($exception->getMessage(), previous: $exception);
        }
    }
}
