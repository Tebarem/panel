<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Registries;

use Ramjet\Eggs\Wings\EggServerSync;

/**
 * Creates, updates and deletes Docker registries. A change to what Wings receives (the host,
 * username or password) re-syncs every server whose egg uses the registry.
 */
final readonly class RegistryManager
{
    public function __construct(private EggServerSync $serverSync) {}

    public function create(RegistryData $data): DockerRegistry
    {
        return DockerRegistry::query()->create([
            'name' => $data->name,
            'registry' => $data->registry,
            'username' => $data->username,
            'password' => $data->password,
        ]);
    }

    /**
     * Updates a registry. A null password keeps the stored one; the form request makes sure a
     * changed host always comes with a password.
     */
    public function update(DockerRegistry $registry, RegistryData $data): DockerRegistry
    {
        $eggIds = $this->eggIdsFor($registry);

        $attributes = [
            'name' => $data->name,
            'registry' => $data->registry,
            'username' => $data->username,
        ];
        if ($data->password !== null) {
            $attributes['password'] = $data->password;
        }

        $registry->forceFill($attributes);
        $changed = $registry->isDirty(['registry', 'username', 'password']);
        $registry->save();

        if ($changed && $eggIds !== []) {
            $this->serverSync->eggsChanged($eggIds);
        }

        return $registry;
    }

    /** Eggs keep working without the registry, so their servers are re-synced without credentials. */
    public function delete(DockerRegistry $registry): void
    {
        $eggIds = $this->eggIdsFor($registry);

        $registry->delete();

        if ($eggIds !== []) {
            $this->serverSync->eggsChanged($eggIds);
        }
    }

    /** @return list<int> */
    public function eggIdsFor(DockerRegistry $registry): array
    {
        // SAFETY: egg_id is an unsigned integer column, so every value read here is an int.
        /** @var list<int> $ids */
        $ids = $registry->assignments()->pluck('egg_id')->values()->all();

        return $ids;
    }
}
