@extends('layouts.admin')

@section('title')
    Egg Sources &rarr; {{ $source->name }}
@endsection

@section('content-header')
    <h1>{{ $source->name }}<small>{{ ucfirst($source->type) }} egg source</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.egg-sources') }}">Egg Sources</a></li>
        <li class="active">{{ $source->name }}</li>
    </ol>
@endsection

@section('content')
@php
    $result = session('egg_source_sync');
@endphp
<div class="row">
    <div class="col-xs-12">
        <div class="box box-info">
            <div class="box-header with-border">
                <h3 class="box-title">Sync Status</h3>
                <div class="box-tools">
                    <form action="{{ route('admin.egg-sources.sync', $source->id) }}" method="POST" style="display:inline;">
                        {!! csrf_field() !!}
                        <button type="submit" class="btn btn-sm btn-info">Sync now</button>
                    </form>
                </div>
            </div>
            <div class="box-body">
                <p>
                    <strong>Last synced:</strong>
                    {{ $source->last_synced_at ? $source->last_synced_at->toDayDateTimeString() . ' (' . $source->last_synced_at->diffForHumans() . ')' : 'Never' }}
                </p>
                @if($source->last_sync_error)
                    <div class="alert alert-danger" style="white-space: pre-wrap;">{{ $source->last_sync_error }}</div>
                @endif
                <p class="text-muted small">
                    Sources are also synchronized automatically every hour. "Sync now" and the scheduled sync run inline, so the page waits for the result.
                    Use <code>php artisan p:egg-source:sync --id={{ $source->id }}</code> to sync from the command line.
                </p>

                @if(is_array($result) && isset($result['files']))
                    <h4>Results of the last manual sync</h4>
                    @if(empty($result['files']))
                        <p class="text-muted">No files were processed.</p>
                    @else
                        <table class="table table-condensed">
                            <tbody>
                                <tr>
                                    <th>File</th>
                                    <th>Status</th>
                                    <th>Egg</th>
                                    <th>Message</th>
                                </tr>
                                @foreach($result['files'] as $file)
                                    <tr>
                                        <td><code>{{ $file['path'] }}</code></td>
                                        <td>
                                            @if($file['status'] === 'failed')
                                                <span class="label label-danger">failed</span>
                                            @elseif($file['status'] === 'skipped')
                                                <span class="label label-default">skipped</span>
                                            @elseif($file['status'] === 'created')
                                                <span class="label label-success">created</span>
                                            @else
                                                <span class="label label-info">updated</span>
                                            @endif
                                        </td>
                                        <td>{{ $file['egg'] ?? '' }}</td>
                                        <td>{{ $file['message'] ?? '' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    @endif
                @endif
            </div>
        </div>
    </div>
</div>
<form action="{{ route('admin.egg-sources.view', $source->id) }}" method="POST">
    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Source Details</h3>
                </div>
                @include('admin.egg-sources._form', ['source' => $source])
                <div class="box-footer">
                    {!! csrf_field() !!}
                    <button type="submit" name="_method" value="PATCH" class="btn btn-primary btn-sm pull-right">Save</button>
                    <button type="submit" name="_method" value="DELETE" class="btn btn-danger btn-sm muted muted-hover" formaction="{{ route('admin.egg-sources.delete', $source->id) }}">
                        <i class="fa fa-trash-o"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
