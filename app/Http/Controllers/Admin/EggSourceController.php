<?php

namespace Pterodactyl\Http\Controllers\Admin;

use Illuminate\View\View;
use Pterodactyl\Models\Nest;
use Pterodactyl\Models\EggSource;
use Illuminate\Http\RedirectResponse;
use Prologue\Alerts\AlertsMessageBag;
use Pterodactyl\Models\DockerRegistry;
use Pterodactyl\Http\Controllers\Controller;
use Illuminate\Validation\ValidationException;
use Pterodactyl\Http\Requests\Admin\EggSourceFormRequest;
use Pterodactyl\Services\Eggs\Sources\EggSourceSyncService;

class EggSourceController extends Controller
{
    /**
     * EggSourceController constructor.
     */
    public function __construct(
        protected AlertsMessageBag $alert,
        protected EggSourceSyncService $syncService,
    ) {
    }

    /**
     * Display all configured egg sources.
     */
    public function index(): View
    {
        return view('admin.egg-sources.index', [
            'sources' => EggSource::query()->with(['nest', 'dockerRegistry'])->withCount('eggs')->orderBy('name')->get(),
        ]);
    }

    /**
     * Display the form to create a new egg source.
     */
    public function create(): View
    {
        return view('admin.egg-sources.new', $this->formData());
    }

    /**
     * Store a new egg source.
     *
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     */
    public function store(EggSourceFormRequest $request): RedirectResponse
    {
        $data = $this->prepare($request->validated());
        unset($data['clear_token']);

        $source = EggSource::query()->create($data);

        $this->alert->success('Egg source was created successfully.')->flash();

        return redirect()->route('admin.egg-sources.view', $source->id);
    }

    /**
     * Display the form to edit an egg source. The access token is never rendered.
     */
    public function view(EggSource $source): View
    {
        return view('admin.egg-sources.view', array_merge(['source' => $source], $this->formData()));
    }

    /**
     * Update an existing egg source. A blank token keeps the stored token unless "clear_token" is set.
     *
     * @throws \Pterodactyl\Exceptions\Model\DataValidationException
     */
    public function update(EggSourceFormRequest $request, EggSource $source): RedirectResponse
    {
        $data = $this->prepare($request->validated());
        $clear = (bool) ($data['clear_token'] ?? false);
        unset($data['clear_token']);

        // A stored token is only ever sent to the destination it was entered for. If the destination
        // changes, the token must be entered again (or removed) rather than being carried over.
        $destinationChanged = $this->destination([
            'type' => $source->type,
            'base_url' => $source->base_url,
            'path' => $source->path,
        ]) !== $this->destination($data);

        if ($destinationChanged && !$clear && blank($data['token'] ?? null) && $source->hasToken()) {
            throw ValidationException::withMessages(['token' => 'Re-enter the access token (or tick remove) when changing the source location.']);
        }

        if ($clear) {
            $data['token'] = null;
        } elseif (blank($data['token'] ?? null)) {
            unset($data['token']);
        }

        $source->forceFill($data)->save();

        $this->alert->success('Egg source was updated successfully.')->flash();

        return redirect()->route('admin.egg-sources.view', $source->id);
    }

    /**
     * Run a sync for a single egg source now and show the per-file results.
     */
    public function sync(EggSource $source): RedirectResponse
    {
        $result = $this->syncService->handle($source);

        if (is_null($result['error'])) {
            $this->alert->success('Sync completed. ' . count($result['files']) . ' file(s) processed.')->flash();
        } else {
            $this->alert->danger('Sync finished with errors: ' . $result['error'])->flash();
        }

        return redirect()->route('admin.egg-sources.view', $source->id)->with('egg_source_sync', $result);
    }

    /**
     * Delete an egg source. Eggs imported from it are kept, but will no longer be synchronized.
     *
     * @throws \Exception
     */
    public function delete(EggSource $source): RedirectResponse
    {
        $source->delete();

        $this->alert->success('Egg source was deleted.')->flash();

        return redirect()->route('admin.egg-sources');
    }

    /**
     * Normalizes the submitted values: directory paths and base URLs lose their trailing slashes,
     * and fields that do not apply to the chosen source type are cleared.
     */
    protected function prepare(array $data): array
    {
        if (isset($data['base_url'])) {
            $data['base_url'] = rtrim($data['base_url'], '/');
        }

        if (($data['type'] ?? null) === EggSource::TYPE_URL) {
            $data['repository'] = null;
            $data['branch'] = null;
            $data['path'] = trim((string) $data['path']);
        } elseif (isset($data['path'])) {
            $data['path'] = trim(trim($data['path']), '/');
        }

        if (isset($data['repository'])) {
            $data['repository'] = trim($data['repository'], '/');
        }

        return $data;
    }

    /**
     * Describes where a source sends its requests and its access token: the provider, the API base URL
     * and, for single-file URL sources, the host of the URL.
     */
    protected function destination(array $attributes): string
    {
        $type = (string) ($attributes['type'] ?? '');
        $base = rtrim((string) ($attributes['base_url'] ?? ''), '/');
        $host = $type === EggSource::TYPE_URL
            ? strtolower((string) parse_url((string) ($attributes['path'] ?? ''), PHP_URL_HOST))
            : '';

        return json_encode([$type, $base, $host]);
    }

    /**
     * @return array{nests: \Illuminate\Database\Eloquent\Collection<int, Nest>, registries: \Illuminate\Database\Eloquent\Collection<int, DockerRegistry>}
     */
    protected function formData(): array
    {
        return [
            'nests' => Nest::query()->orderBy('name')->get(),
            'registries' => DockerRegistry::query()->orderBy('name')->get(),
        ];
    }
}
