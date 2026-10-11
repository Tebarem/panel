<?php

declare(strict_types=1);

namespace Ramjet\Eggs\Registries\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Pterodactyl\Facades\Activity;
use Ramjet\Eggs\Registries\DockerRegistry;
use Ramjet\Eggs\Registries\Http\Requests\DockerRegistryRequest;
use Ramjet\Eggs\Registries\RegistryManager;

/**
 * Admin API for Docker registries. Responses never include a password.
 */
final class DockerRegistryController
{
    public function index(): JsonResponse
    {
        $registries = DockerRegistry::query()->withCount('assignments')->orderBy('name')->get();

        return new JsonResponse(['data' => $registries->map(fn (DockerRegistry $registry): array => $this->present($registry, $registry->assignments_count))->values()->all()]);
    }

    public function store(DockerRegistryRequest $request, RegistryManager $registries): JsonResponse
    {
        $registry = $registries->create($request->registryData());

        $this->logActivity('admin:docker-registry.create', $registry);

        return new JsonResponse(['data' => $this->present($registry, 0)], 201);
    }

    public function update(DockerRegistryRequest $request, RegistryManager $registries, int $id): JsonResponse
    {
        $registry = DockerRegistry::query()->findOrFail($id);
        $registry = $registries->update($registry, $request->registryData());

        $this->logActivity('admin:docker-registry.update', $registry);

        return new JsonResponse(['data' => $this->present($registry, $registry->assignments()->count())]);
    }

    public function destroy(RegistryManager $registries, int $id): Response
    {
        $registry = DockerRegistry::query()->findOrFail($id);
        $this->logActivity('admin:docker-registry.delete', $registry);

        $registries->delete($registry);

        return new Response('', 204);
    }

    /**
     * Records the change. Only identifying, non-secret properties are stored: never the password, and
     * not the username either.
     */
    private function logActivity(string $event, DockerRegistry $registry): void
    {
        Activity::event($event)
            ->subject($registry)
            ->property('id', $registry->id)
            ->property('name', $registry->name)
            ->property('registry', $registry->registry)
            ->log();
    }

    /**
     * @return array{id: int, name: string, registry: string, username: string, egg_count: int, warnings: list<string>, created_at: string|null, updated_at: string|null}
     */
    private function present(DockerRegistry $registry, int $eggCount): array
    {
        $warnings = [];
        if ($registry->matchesWholeHost()) {
            $warnings[] = sprintf(
                'The registry "%s" matches every image on this host, so its credentials are sent for all of them. Consider scoping it to a namespace such as "%s/myorg".',
                $registry->registry,
                $registry->registry,
            );
        }

        return [
            'id' => $registry->id,
            'name' => $registry->name,
            'registry' => $registry->registry,
            'username' => $registry->username,
            'egg_count' => $eggCount,
            'warnings' => $warnings,
            'created_at' => $registry->created_at->toIso8601String(),
            'updated_at' => $registry->updated_at->toIso8601String(),
        ];
    }
}
