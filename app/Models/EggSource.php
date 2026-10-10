<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $name
 * @property string $type
 * @property string|null $base_url
 * @property string|null $repository
 * @property string|null $branch
 * @property string|null $path
 * @property string|null $token
 * @property int $nest_id
 * @property int|null $docker_registry_id
 * @property \Carbon\Carbon|null $last_synced_at
 * @property string|null $last_sync_error
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property Nest $nest
 * @property DockerRegistry|null $dockerRegistry
 * @property \Illuminate\Database\Eloquent\Collection|\Pterodactyl\Models\Egg[] $eggs
 */
class EggSource extends Model
{
    public const TYPE_GITHUB = 'github';
    public const TYPE_GITLAB = 'gitlab';
    public const TYPE_URL = 'url';

    public const TYPES = [self::TYPE_GITHUB, self::TYPE_GITLAB, self::TYPE_URL];

    /**
     * The table associated with the model.
     */
    protected $table = 'egg_sources';

    /**
     * Fields that are mass assignable.
     */
    protected $fillable = [
        'name',
        'type',
        'base_url',
        'repository',
        'branch',
        'path',
        'token',
        'nest_id',
        'docker_registry_id',
    ];

    /**
     * The access token is write-only; it is never serialized.
     */
    protected $hidden = [
        'token',
    ];

    /**
     * Cast values to correct type.
     */
    protected $casts = [
        'nest_id' => 'integer',
        'docker_registry_id' => 'integer',
        'token' => 'encrypted',
        'last_synced_at' => 'datetime',
    ];

    /**
     * Rules verifying that the data being stored matches the expectations of the database.
     */
    public static array $validationRules = [
        'name' => 'required|string|max:191',
        'type' => 'required|string|in:github,gitlab,url',
        'base_url' => 'nullable|string|max:191',
        'repository' => 'nullable|string|max:191',
        'branch' => 'nullable|string|max:191',
        'path' => 'nullable|string|max:500',
        'token' => 'nullable|string|max:1024',
        'nest_id' => 'required|integer|exists:nests,id',
        'docker_registry_id' => 'nullable|integer',
        'last_sync_error' => 'nullable|string',
    ];

    /**
     * Returns true if an access token has been stored for this source, without exposing it.
     */
    public function hasToken(): bool
    {
        return !is_null($this->getRawOriginal('token')) && $this->getRawOriginal('token') !== '';
    }

    /**
     * @return BelongsTo<Nest, $this>
     */
    public function nest(): BelongsTo
    {
        return $this->belongsTo(Nest::class);
    }

    /**
     * @return BelongsTo<DockerRegistry, $this>
     */
    public function dockerRegistry(): BelongsTo
    {
        return $this->belongsTo(DockerRegistry::class, 'docker_registry_id');
    }

    /**
     * Eggs that were imported from or are kept in sync with this source.
     *
     * @return HasMany<Egg, $this>
     */
    public function eggs(): HasMany
    {
        return $this->hasMany(Egg::class, 'egg_source_id');
    }
}
