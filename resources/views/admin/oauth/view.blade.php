@extends('layouts.admin')

@section('title')
    OAuth App: {{ $client->name }}
@endsection

@section('content-header')
    <h1>{{ $client->name }}<small>{{ $client->isConfidential() ? 'Confidential' : 'Public' }} OAuth application.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.oauth') }}">OAuth Apps</a></li>
        <li class="active">{{ $client->name }}</li>
    </ol>
@endsection

@section('content')
    @if (session(\Pterodactyl\Http\Controllers\Admin\OAuthClientController::SECRET_FLASH_KEY))
        <div class="row">
            <div class="col-xs-12">
                <div class="callout callout-warning">
                    <h4>Copy the client secret now</h4>
                    <p>This is the only time the secret is displayed. Store it in your application's configuration.</p>
                    <p><code style="font-size: 14px; word-break: break-all;">{{ session(\Pterodactyl\Http\Controllers\Admin\OAuthClientController::SECRET_FLASH_KEY) }}</code></p>
                </div>
            </div>
        </div>
    @endif

    <div class="row">
        <div class="col-xs-12 col-md-7">
            <form action="{{ route('admin.oauth.update', $client) }}" method="POST">
                <div class="box box-primary">
                    <div class="box-header with-border">
                        <h3 class="box-title">Settings</h3>
                    </div>

                    <div class="box-body">
                        <div class="form-group">
                            <label class="form-label">Client ID</label>
                            <input type="text" class="form-control" value="{{ $client->id }}" readonly />
                        </div>

                        <div class="form-group">
                            <label for="pName" class="form-label">Name</label>
                            <input type="text" id="pName" name="name" class="form-control" value="{{ old('name', $client->name) }}" maxlength="191" required />
                        </div>

                        <div class="form-group">
                            <label for="pRedirectUris" class="form-label">Redirect URIs</label>
                            <textarea id="pRedirectUris" name="redirect_uris" class="form-control" rows="4" required>{{ old('redirect_uris', implode("\n", $client->redirectUriList())) }}</textarea>
                            <p class="text-muted small">One absolute URL per line. Fragments are not allowed.</p>
                        </div>

                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="skip_authorization" value="1" {{ (old('skip_authorization') || (empty(old()) && $client->skip_authorization)) ? 'checked' : '' }}>
                                Skip consent screen
                            </label>
                        </div>
                    </div>

                    <div class="box-footer">
                        {!! csrf_field() !!}
                        @method('PATCH')
                        <button type="submit" class="btn btn-success btn-sm pull-right">Save</button>
                    </div>
                </div>
            </form>
        </div>

        <div class="col-xs-12 col-md-5">
            <div class="box box-default">
                <div class="box-header with-border">
                    <h3 class="box-title">Credentials</h3>
                </div>

                <div class="box-body">
                    @if ($client->isConfidential())
                        <p class="text-muted small">The client secret cannot be displayed again. Regenerating it invalidates the current secret immediately and revokes all tokens issued to this application.</p>

                        <form action="{{ route('admin.oauth.regenerate', $client) }}" method="POST" style="margin-bottom: 10px;">
                            {!! csrf_field() !!}
                            <button type="submit" class="btn btn-warning btn-sm"
                                onclick="return confirm('Regenerate the client secret? The current secret stops working immediately and all tokens for this application are revoked.');">
                                Regenerate Secret
                            </button>
                        </form>
                    @else
                        <p class="text-muted small">Public clients do not use a client secret. They rely on PKCE.</p>
                    @endif

                    <form action="{{ route('admin.oauth.revoke', $client) }}" method="POST">
                        {!! csrf_field() !!}
                        <button type="submit" class="btn btn-default btn-sm"
                            onclick="return confirm('Revoke all access and refresh tokens issued to this application? Users will need to sign in again.');">
                            Revoke All Tokens
                        </button>
                    </form>
                </div>
            </div>

            <div class="box box-danger">
                <div class="box-header with-border">
                    <h3 class="box-title">Delete</h3>
                </div>

                <div class="box-body">
                    <p class="text-muted small">Deleting an application revokes all of its tokens and removes it. This cannot be undone.</p>

                    <form action="{{ route('admin.oauth.delete', $client) }}" method="POST">
                        {!! csrf_field() !!}
                        @method('DELETE')
                        <button type="submit" class="btn btn-danger btn-sm"
                            onclick="return confirm('Delete this OAuth application? This cannot be undone.');">
                            Delete Application
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
@endsection
