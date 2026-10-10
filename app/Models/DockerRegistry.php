<?php

namespace Pterodactyl\Models;

use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @property int $id
 * @property string $name
 * @property string $registry
 * @property string $username
 * @property string $password
 * @property \Carbon\Carbon $created_at
 * @property \Carbon\Carbon $updated_at
 * @property \Illuminate\Database\Eloquent\Collection|\Pterodactyl\Models\Egg[] $eggs
 */
class DockerRegistry extends Model
{
    /**
     * The table associated with the model.
     */
    protected $table = 'docker_registries';

    /**
     * Fields that are mass assignable.
     */
    protected $fillable = [
        'name',
        'registry',
        'username',
        'password',
    ];

    /**
     * The password is encrypted at rest and must never be serialized anywhere (API responses,
     * views, logs). It is only ever read by the server configuration sent to Wings.
     */
    protected $hidden = [
        'password',
    ];

    /**
     * Cast values to correct type.
     */
    protected $casts = [
        'password' => 'encrypted',
    ];

    /**
     * Rules verifying that the data being stored matches the expectations of the database.
     *
     * The registry is a host with an optional port and an optional path prefix, for example
     * "ghcr.io", "registry.example.com:5000" or "ghcr.io/my-org". It must not contain a scheme.
     * Docker Hub namespaces must be written as "docker.io/myorg". Values are lower case only; the
     * form request lower cases them before validation.
     */
    public static array $validationRules = [
        'name' => 'required|string|max:191',
        'registry' => [
            'required',
            'string',
            'max:191',
            'regex:/^(?:localhost|[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+|[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?=:))(?::[0-9]{1,5})?(?:\/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)*$/D',
        ],
        'username' => 'required|string|max:191',
        'password' => 'required|string|max:1024',
    ];

    /**
     * Gets all eggs that use this registry.
     *
     * @return \Illuminate\Database\Eloquent\Relations\HasMany<\Pterodactyl\Models\Egg, $this>
     */
    public function eggs(): HasMany
    {
        return $this->hasMany(Egg::class, 'docker_registry_id');
    }
}
