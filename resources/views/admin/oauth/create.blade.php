@extends('layouts.admin')

@section('title')
    Create OAuth App
@endsection

@section('content-header')
    <h1>Create OAuth App<small>Register an application that will sign users in with this panel.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.oauth') }}">OAuth Apps</a></li>
        <li class="active">Create</li>
    </ol>
@endsection

@section('content')
    <form action="{{ route('admin.oauth.store') }}" method="POST">
        <div class="row">
            <div class="col-xs-12">
                <div class="box box-primary">
                    <div class="box-body">
                        <div class="form-group">
                            <label for="pName" class="form-label">Name</label>
                            <input type="text" id="pName" name="name" class="form-control" value="{{ old('name') }}" maxlength="191" required />
                            <p class="text-muted small">The name shown to users on the consent screen.</p>
                        </div>

                        <div class="form-group">
                            <label for="pRedirectUris" class="form-label">Redirect URIs</label>
                            <textarea id="pRedirectUris" name="redirect_uris" class="form-control" rows="4" required>{{ old('redirect_uris') }}</textarea>
                            <p class="text-muted small">One absolute URL per line, for example <code>https://grafana.example.com/login/generic_oauth</code>. Fragments are not allowed.</p>
                        </div>

                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="confidential" value="1" {{ (empty(old()) || old('confidential')) ? 'checked' : '' }}>
                                Confidential client
                            </label>
                            <p class="text-muted small">Confidential clients are server-side applications and receive a client secret, shown once after creation. Leave unchecked for public clients, which must use PKCE.</p>
                        </div>

                        <div class="checkbox">
                            <label>
                                <input type="checkbox" name="skip_authorization" value="1" {{ old('skip_authorization') ? 'checked' : '' }}>
                                Skip consent screen
                            </label>
                            <p class="text-muted small">Only enable this for applications you operate. Users are not asked to approve access.</p>
                        </div>
                    </div>

                    <div class="box-footer">
                        {!! csrf_field() !!}
                        <a href="{{ route('admin.oauth') }}" class="btn btn-default btn-sm">Cancel</a>
                        <button type="submit" class="btn btn-success btn-sm pull-right">Create</button>
                    </div>
                </div>
            </div>
        </div>
    </form>
@endsection
