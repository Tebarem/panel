@extends('layouts.admin')

@section('title')
    OAuth Apps
@endsection

@section('content-header')
    <h1>OAuth Apps<small>Applications that can sign users in with this panel (OAuth2 / OpenID Connect).</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">OAuth Apps</li>
    </ol>
@endsection

@section('content')
    <div class="row">
        <div class="col-xs-12">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Applications</h3>

                    <div class="box-tools">
                        <a class="btn btn-sm btn-primary" href="{{ route('admin.oauth.new') }}">Create New</a>
                    </div>
                </div>

                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <tbody>
                            <tr>
                                <th>Name</th>
                                <th>Client ID</th>
                                <th>Type</th>
                                <th>Redirect URIs</th>
                                <th class="text-center">Skip Consent</th>
                                <th>Created</th>
                            </tr>

                            @forelse ($clients as $client)
                                <tr>
                                    <td><a href="{{ route('admin.oauth.view', $client) }}">{{ $client->name }}</a></td>
                                    <td><code>{{ $client->id }}</code></td>
                                    <td>{{ $client->isConfidential() ? 'Confidential' : 'Public' }}</td>
                                    <td>
                                        @foreach ($client->redirectUriList() as $uri)
                                            <div><code>{{ $uri }}</code></div>
                                        @endforeach
                                    </td>
                                    <td class="text-center">{{ $client->skip_authorization ? 'Yes' : 'No' }}</td>
                                    <td>{{ $client->created_at?->toDateTimeString() }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="6" class="text-center text-muted">No OAuth applications have been created yet.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
