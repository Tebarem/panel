{{-- Shared form for creating and editing a Docker registry. $registry is null when creating. --}}
<div class="box-body">
    <div class="form-group">
        <label for="pName" class="control-label">Name</label>
        <input type="text" id="pName" name="name" value="{{ old('name', $registry->name ?? '') }}" class="form-control" />
        <p class="text-muted small">A human-readable name used to identify these credentials.</p>
    </div>
    <div class="form-group">
        <label for="pRegistry" class="control-label">Registry</label>
        <input type="text" id="pRegistry" name="registry" value="{{ old('registry', $registry->registry ?? '') }}" placeholder="ghcr.io/my-org" class="form-control" />
        <p class="text-muted small">
            The registry host, optionally followed by a path prefix, in lower case. Do not include <code>https://</code> or a trailing slash.
            Credentials are used for images whose name starts with this value, for example <code>ghcr.io/myorg/yolks:java</code>.
            Docker Hub namespaces must be written as <code>docker.io/myorg</code>.
        </p>
        <div class="alert alert-warning" id="pRegistryWarning" style="display: none;">
            A bare host such as <code>ghcr.io</code> matches every image on that host, so these credentials are sent for all of them.
            Use a namespace instead, for example <code>ghcr.io/myorg</code>.
        </div>
    </div>
    <div class="form-group">
        <label for="pUsername" class="control-label">Username</label>
        <input type="text" id="pUsername" name="username" value="{{ old('username', $registry->username ?? '') }}" class="form-control" autocomplete="off" />
    </div>
    <div class="form-group">
        <label for="pPassword" class="control-label">Password or Token</label>
        <input type="password" id="pPassword" name="password" value="" class="form-control" autocomplete="new-password" {{ isset($registry) ? '' : 'required' }} />
        <p class="text-muted small">
            @if(isset($registry))
                Leave blank to keep the existing password. Changing the registry requires entering the password again.
                The stored password is never displayed.
            @else
                The password or access token is encrypted before it is stored and cannot be viewed again after saving.
            @endif
            Anyone with root access or the daemon token on a node that runs servers using these eggs can read these credentials.
            Use a token with the minimum read-only scope.
        </p>
    </div>
</div>
<script>
    (function () {
        var input = document.getElementById('pRegistry');
        var warning = document.getElementById('pRegistryWarning');
        if (!input || !warning) return;

        var update = function () {
            var value = input.value.trim();
            warning.style.display = (value !== '' && value.indexOf('/') === -1) ? '' : 'none';
        };

        input.addEventListener('input', update);
        update();
    })();
</script>
