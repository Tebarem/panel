<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Sources;

use Ramjet\Eggs\Sources\Http\EggSourceRequest;

/**
 * The validated settings of an egg source, normalized: trailing slashes removed from base URLs and directories, and
 * fields that do not apply to the chosen type cleared.
 */
final readonly class EggSourceData
{
    public function __construct(
        public string $name,
        public string $type,
        public ?string $baseUrl,
        public ?string $repository,
        public ?string $branch,
        public ?string $path,
        public ?string $token,
        public bool $clearToken,
        public ?string $tag,
        public ?int $dockerRegistryId,
    ) {}

    public static function fromRequest(EggSourceRequest $request): self
    {
        $type = $request->string('type')->value();
        $isUrl = $type === EggSource::TYPE_URL;

        $baseUrl = self::nullable($request->string('base_url')->value());
        $repository = self::nullable($request->string('repository')->value());
        $path = self::nullable($request->string('path')->value());

        return new self(
            name: trim($request->string('name')->value()),
            type: $type,
            baseUrl: ($isUrl || $baseUrl === null) ? null : rtrim($baseUrl, '/'),
            repository: ($isUrl || $repository === null) ? null : trim($repository, '/'),
            branch: $isUrl ? null : self::nullable($request->string('branch')->value()),
            path: $path === null ? null : ($isUrl ? trim($path) : trim($path, '/')),
            token: self::nullable($request->string('token')->value()),
            clearToken: $request->boolean('clear_token'),
            tag: self::nullable($request->string('tag')->value()),
            dockerRegistryId: $request->filled('docker_registry_id') ? $request->integer('docker_registry_id') : null,
        );
    }

    /**
     * Describes where the requests of a source and its token go: the provider, the API base URL and, for
     * single-file sources, the origin (scheme, host and port) of the URL.
     */
    public static function destinationOf(string $type, ?string $baseUrl, ?string $path): string
    {
        $origin = $type === EggSource::TYPE_URL ? self::originOf($path ?? '') : '';

        return json_encode([$type, rtrim($baseUrl ?? '', '/'), $origin], JSON_THROW_ON_ERROR);
    }

    /**
     * The scheme, host and port of a URL, lower-cased. Empty when the URL has no scheme or host.
     */
    public static function originOf(string $url): string
    {
        $scheme = mb_strtolower(SourceUrl::part($url, PHP_URL_SCHEME));
        $host = mb_strtolower(SourceUrl::part($url, PHP_URL_HOST));
        $port = SourceUrl::part($url, PHP_URL_PORT);

        if ($scheme === '' || $host === '') {
            return '';
        }

        return $scheme.'://'.$host.($port === '' ? '' : ':'.$port);
    }

    /** Whether the URL uses plain http. */
    public static function isPlainHttp(?string $address): bool
    {
        return $address !== null && str_starts_with(mb_strtolower($address), 'http://');
    }

    /**
     * The columns of the source that these settings set. The token is handled separately.
     *
     * @return array<string, string|int|null>
     */
    public function attributes(): array
    {
        return [
            'name' => $this->name,
            'type' => $this->type,
            'base_url' => $this->baseUrl,
            'repository' => $this->repository,
            'branch' => $this->branch,
            'path' => $this->path,
            'tag' => $this->tag,
            'docker_registry_id' => $this->dockerRegistryId,
        ];
    }

    /**
     * The address a token would be sent to: the API base URL, or the URL of a single-file source.
     */
    public function address(): ?string
    {
        return $this->type === EggSource::TYPE_URL ? $this->path : $this->baseUrl;
    }

    private static function nullable(string $value): ?string
    {
        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
