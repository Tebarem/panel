@extends('layouts.admin')

@section('title')
    Egg Sources
@endsection

@section('content-header')
    <h1>Egg Sources<small>Import and keep eggs in sync with a GitHub, GitLab or URL source.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Egg Sources</li>
    </ol>
@endsection

@section('content')
    <div class="row">
        <div class="col-xs-12">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Egg Source List</h3>

                    <div class="box-tools">
                        <a href="{{ route('admin.egg-sources.new') }}" class="btn btn-sm btn-primary">Create New</a>
                    </div>
                </div>

                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <tbody>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Location</th>
                                <th>Nest</th>
                                <th class="text-center">Eggs</th>
                                <th>Last Synced</th>
                                <th>Status</th>
                            </tr>
                            @forelse ($sources as $source)
                                <tr>
                                    <td><code>{{ $source->id }}</code></td>
                                    <td><a href="{{ route('admin.egg-sources.view', $source->id) }}">{{ $source->name }}</a></td>
                                    <td>{{ ucfirst($source->type) }}</td>
                                    <td><code>{{ $source->type === 'url' ? $source->path : trim($source->repository . '@' . $source->branch . ':' . $source->path, ':@') }}</code></td>
                                    <td>{{ $source->nest->name ?? '—' }}</td>
                                    <td class="text-center">{{ $source->eggs_count }}</td>
                                    <td>{{ $source->last_synced_at ? $source->last_synced_at->diffForHumans() : 'Never' }}</td>
                                    <td>
                                        @if($source->last_sync_error)
                                            <span class="label label-danger">Error</span>
                                        @elseif($source->last_synced_at)
                                            <span class="label label-success">OK</span>
                                        @else
                                            <span class="label label-default">Not synced</span>
                                        @endif
                                    </td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="8" class="text-center text-muted">No egg sources have been configured.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
