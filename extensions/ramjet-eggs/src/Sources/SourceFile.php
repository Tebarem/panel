<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Sources;

/**
 * A JSON file found by listing a source.
 */
final readonly class SourceFile
{
    public function __construct(
        /** Repository-relative path, or the URL for the URL type. */
        public string $path,
        /** Blob id the provider reports for the file's content; null when the provider does not report one. */
        public ?string $revision,
    ) {}
}
