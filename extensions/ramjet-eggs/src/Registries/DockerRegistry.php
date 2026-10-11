<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Registries;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Credentials for a private image registry. Eggs are assigned to a registry through
 * EggRegistries; Wings receives the credentials with the server configuration.
 *
 * @property int $id
 * @property string $name
 * @property string $registry
 * @property string $username
 * @property string $password
 * @property \Carbon\CarbonImmutable $created_at
 * @property \Carbon\CarbonImmutable $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, EggRegistryAssignment> $assignments
 */
final class DockerRegistry extends Model
{
    protected $table = 'ramjet_docker_registries';

    /** @var list<string> */
    protected $fillable = [
        'name',
        'registry',
        'username',
        'password',
    ];

    /**
     * The password must never be serialized anywhere (API responses, logs, exports).
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
    ];

    /** @var array<string, string> */
    protected $casts = [
        'password' => 'encrypted',
    ];

    /**
     * Validation rules for the registry fields. The registry is a lower-case host with an optional
     * port and an optional namespace path, for example "ghcr.io", "registry.example.com:5000" or
     * "ghcr.io/my-org". It must not contain a scheme. Docker Hub namespaces are written
     * "docker.io/myorg". The form request lower-cases and trims the value before validation.
     *
     * @return array<string, list<string>>
     */
    public static function validationRules(): array
    {
        return [
            'name' => ['required', 'string', 'max:191'],
            'registry' => [
                'required',
                'string',
                'max:191',
                'regex:/^(?:localhost|[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]*[a-z0-9])?)+|[a-z0-9](?:[a-z0-9-]*[a-z0-9])?(?=:))(?::[0-9]{1,5})?(?:\/[a-z0-9]+(?:(?:[._]|__|-+)[a-z0-9]+)*)*$/D',
            ],
            'username' => ['required', 'string', 'max:191'],
            'password' => ['required', 'string', 'max:1024'],
        ];
    }

    /**
     * A registry that is only a host (no namespace) sends its credentials for every image on that
     * host. The panel accepts it but warns about it.
     */
    public function matchesWholeHost(): bool
    {
        return ! str_contains($this->registry, '/');
    }

    /**
     * @return HasMany<EggRegistryAssignment, $this>
     */
    public function assignments(): HasMany
    {
        return $this->hasMany(EggRegistryAssignment::class, 'registry_id');
    }
}
