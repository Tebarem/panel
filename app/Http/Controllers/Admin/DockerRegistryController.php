<?php

namespace Pterodactyl\Http\Controllers\Admin;

use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Models\DockerRegistry;
use Pterodactyl\Http\Controllers\Controller;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Services\Eggs\EggServerSyncService;
use Pterodactyl\Http\Requests\Admin\DockerRegistryFormRequest;

class DockerRegistryController extends Controller
{
    /**
     * DockerRegistryController constructor.
     */
    public function __construct(
        protected AlertsMessageBag $alert,
        protected EggServerSyncService $serverSyncService,
    ) {
    }

    /**
     * Display the list of configured Docker registries.
     */
    public function index(): View
    {
        return view('admin.registries.index', [
            'registries' => DockerRegistry::query()->withCount('eggs')->orderBy('name')->get(),
        ]);
    }

    /**
     * Display the form to create a new Docker registry.
     */
    public function create(): View
    {
        return view('admin.registries.new');
    }

    /**
     * Store a new Docker registry.
     *
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     */
    public function store(DockerRegistryFormRequest $request): RedirectResponse
    {
        $registry = DockerRegistry::query()->create($request->validated());

        $this->alert->success('Docker registry was created successfully.')->flash();
        $this->warnIfHostOnly($registry);

        return redirect()->route('admin.registries.view', $registry->id);
    }

    /**
     * Display the form to edit a Docker registry. The password is never rendered.
     */
    public function view(DockerRegistry $registry): View
    {
        return view('admin.registries.view', ['registry' => $registry]);
    }

    /**
     * Update an existing Docker registry. Servers using it are re-synced so Wings picks up the
     * new credentials.
     *
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     */
    public function update(DockerRegistryFormRequest $request, DockerRegistry $registry): RedirectResponse
    {
        $data = $request->validated();

        // The password is sent to a different destination when the registry changes, so it must be
        // entered again rather than silently reused.
        $registryChanged = ($data['registry'] ?? $registry->registry) !== $registry->registry;
        if ($registryChanged && blank($data['password'] ?? null)) {
            throw ValidationException::withMessages(['password' => 'Re-enter the password or token when changing the registry.']);
        }

        if (blank($data['password'] ?? null)) {
            unset($data['password']);
        }

        $eggIds = $registry->eggs()->pluck('id')->all();

        $registry->forceFill($data)->save();

        $this->serverSyncService->handle($eggIds);
        $this->alert->success('Docker registry was updated successfully.')->flash();
        $this->warnIfHostOnly($registry);

        return redirect()->route('admin.registries.view', $registry->id);
    }

    /**
     * Warns (without blocking) when a registry is only a host, such as "ghcr.io". Credentials for a
     * bare host are then sent for every image on that host, not only for one organization's images.
     */
    protected function warnIfHostOnly(DockerRegistry $registry): void
    {
        if (!str_contains($registry->registry, '/')) {
            $this->alert->warning(sprintf(
                'The registry "%s" matches every image on this host, so its credentials are sent for all of them. Consider scoping it to a namespace such as "%s/myorg".',
                $registry->registry,
                $registry->registry,
            ))->flash();
        }
    }

    /**
     * Delete a Docker registry. Eggs using it keep working, but without credentials.
     *
     * @throws \Exception
     */
    public function delete(DockerRegistry $registry): RedirectResponse
    {
        $eggIds = $registry->eggs()->pluck('id')->all();

        $registry->delete();

        $this->serverSyncService->handle($eggIds);
        $this->alert->success('Docker registry was deleted.')->flash();

        return redirect()->route('admin.registries');
    }
}
