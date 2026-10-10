{{-- Shared form for creating and editing an egg source. $source is null when creating. --}}
@php
    $type = old('type', $source->type ?? 'github');
    $isUrl = $type === 'url';
@endphp
<div class="box-body">
    <div class="row">
        <div class="col-sm-6">
            <div class="form-group">
                <label for="pName" class="control-label">Name</label>
                <input type="text" id="pName" name="name" value="{{ old('name', $source->name ?? '') }}" class="form-control" />
                <p class="text-muted small">A name used to identify this source.</p>
            </div>
            <div class="form-group">
                <label for="pType" class="control-label">Type</label>
                <select id="pType" name="type" class="form-control">
                    <option value="github" {{ $type === 'github' ? 'selected' : '' }}>GitHub</option>
                    <option value="gitlab" {{ $type === 'gitlab' ? 'selected' : '' }}>GitLab</option>
                    <option value="url" {{ $type === 'url' ? 'selected' : '' }}>Single URL</option>
                </select>
                <p class="text-muted small">Repository sources import every <code>.json</code> file below the path. The URL type imports one egg JSON file.</p>
            </div>
            <div class="form-group">
                <label for="pNest" class="control-label">Nest</label>
                <select id="pNest" name="nest_id" class="form-control">
                    @foreach($nests as $nest)
                        <option value="{{ $nest->id }}" {{ (string) old('nest_id', $source->nest_id ?? '') === (string) $nest->id ? 'selected' : '' }}>{{ $nest->name }}</option>
                    @endforeach
                </select>
                <p class="text-muted small">
                    Eggs created by this source are placed in this nest. Changing the nest does not move eggs that already exist.
                </p>
            </div>
            <div class="form-group">
                <label for="pDockerRegistry" class="control-label">Docker Registry (optional)</label>
                <select id="pDockerRegistry" name="docker_registry_id" class="form-control">
                    <option value="">None</option>
                    @foreach($registries as $registry)
                        <option value="{{ $registry->id }}" {{ (string) old('docker_registry_id', $source->docker_registry_id ?? '') === (string) $registry->id ? 'selected' : '' }}>{{ $registry->name }} ({{ $registry->registry }})</option>
                    @endforeach
                </select>
                <p class="text-muted small">
                    Assigned to every egg imported from this source. A change here is applied to existing eggs on the next sync.
                    Registry credentials are readable by anyone with root access or the daemon token on nodes that run servers using these eggs.
                </p>
            </div>
        </div>
        <div class="col-sm-6">
            <div class="form-group">
                <label for="pBaseUrl" class="control-label">API Base URL (optional)</label>
                <input type="text" id="pBaseUrl" name="base_url" value="{{ old('base_url', $source->base_url ?? '') }}" placeholder="https://github.example.com/api/v3" class="form-control" />
                <p class="text-muted small">
                    Only needed for self-hosted GitHub Enterprise or GitLab. Leave blank for github.com and gitlab.com.
                    Must be https:// when an access token is used. Do not put a username or password in the address.
                </p>
            </div>
            <div class="form-group repo-field">
                <label for="pRepository" class="control-label">Repository</label>
                <input type="text" id="pRepository" name="repository" value="{{ old('repository', $source->repository ?? '') }}" placeholder="owner/repo or group/project" class="form-control" />
                <p class="text-muted small">GitHub: <code>owner/repo</code>. GitLab: the full project path, for example <code>group/subgroup/project</code>. Changing the location requires re-entering the access token.</p>
            </div>
            <div class="form-group repo-field">
                <label for="pBranch" class="control-label">Branch</label>
                <input type="text" id="pBranch" name="branch" value="{{ old('branch', $source->branch ?? '') }}" placeholder="main" class="form-control" />
            </div>
            <div class="form-group">
                <label for="pPath" class="control-label" id="pPathLabel">{{ $isUrl ? 'URL' : 'Directory Path (optional)' }}</label>
                <input type="text" id="pPath" name="path" value="{{ old('path', $source->path ?? '') }}" placeholder="{{ $isUrl ? 'https://example.com/egg-minecraft.json' : 'eggs/minecraft' }}" class="form-control" />
                <p class="text-muted small" id="pPathHelp">
                    {{ $isUrl ? 'The full address of a single egg JSON file. Do not put tokens in the URL; use the access token field instead.' : 'Directory inside the repository to search for JSON files. Leave blank to search the whole repository. Eggs are matched by their file path, so changing this later creates new eggs.' }}
                </p>
            </div>
            <div class="form-group">
                <label for="pToken" class="control-label">Access Token (optional)</label>
                <input type="password" id="pToken" name="token" value="" class="form-control" autocomplete="new-password" />
                @if(isset($source) && $source->hasToken())
                    <p class="text-muted small">
                        A token is stored. Leave blank to keep it, or tick the box below to remove it. The token is never displayed. Changing the type, API base URL or URL host requires entering the token again.
                    </p>
                    <div class="checkbox checkbox-danger no-margin-bottom">
                        <input id="pClearToken" name="clear_token" type="checkbox" value="1" />
                        <label for="pClearToken">Remove the stored token</label>
                    </div>
                @else
                    <p class="text-muted small">Required for private repositories. Stored encrypted and never displayed again. Changing the type, API base URL or URL host requires entering the token again.</p>
                @endif
            </div>
        </div>
    </div>
</div>
<script>
    (function () {
        var type = document.getElementById('pType');
        if (!type) return;

        var toggle = function () {
            var isUrl = type.value === 'url';
            document.querySelectorAll('.repo-field').forEach(function (el) {
                el.style.display = isUrl ? 'none' : '';
            });
            document.getElementById('pPathLabel').textContent = isUrl ? 'URL' : 'Directory Path (optional)';
            document.getElementById('pPath').placeholder = isUrl ? 'https://example.com/egg-minecraft.json' : 'eggs/minecraft';
        };

        type.addEventListener('change', toggle);
        toggle();
    })();
</script>
