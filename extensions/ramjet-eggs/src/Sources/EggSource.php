<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Sources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Pterodactyl\Models\Egg;
use Pterodactyl\Support\JsonValueGuard;

/**
 * A remote location (a GitHub or GitLab directory, or one URL) whose egg JSON files are kept in sync.
 *
 * @property int $id
 * @property string $name
 * @property string $type
 * @property string|null $base_url
 * @property string|null $repository
 * @property string|null $branch
 * @property string|null $path
 * @property string|null $token
 * @property string|null $tag
 * @property int|null $docker_registry_id
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property string|null $last_sync_error
 * @property list<array{path: string, status: string, egg: string|null, message: string|null}>|null $last_sync_results
 * @property \Illuminate\Support\Carbon $created_at
 * @property \Illuminate\Support\Carbon $updated_at
 */
final class EggSource extends Model
{
    public const TYPE_GITHUB = 'github';

    public const TYPE_GITLAB = 'gitlab';

    public const TYPE_URL = 'url';

    /** @var list<string> */
    public const TYPES = [self::TYPE_GITHUB, self::TYPE_GITLAB, self::TYPE_URL];

    protected $table = 'ramjet_egg_sources';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'type',
        'base_url',
        'repository',
        'branch',
        'path',
        'token',
        'tag',
        'docker_registry_id',
    ];

    /** @var list<string> */
    protected $hidden = [
        'token',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'docker_registry_id' => 'integer',
        'token' => 'encrypted',
        'last_synced_at' => 'datetime',
        'last_sync_results' => 'array',
    ];

    /**
     * Returns true if an access token is stored, without exposing it.
     */
    public function hasToken(): bool
    {
        $raw = JsonValueGuard::nullableString($this->getRawOriginal('token'));

        return $raw !== null && $raw !== '';
    }

    /**
     * Eggs imported from this source, with the file path each one was imported from.
     *
     * @return BelongsToMany<Egg, $this>
     */
    public function eggs(): BelongsToMany
    {
        return $this->belongsToMany(Egg::class, 'ramjet_egg_source_eggs', 'source_id', 'egg_id')
            ->withPivot('path');
    }
}
