@extends('layouts.admin')

@section('title')
    Docker Registries
@endsection

@section('content-header')
    <h1>Docker Registries<small>Private registry credentials used to pull images for servers.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li class="active">Docker Registries</li>
    </ol>
@endsection

@section('content')
    <div class="row">
        <div class="col-xs-12">
            <div class="box box-primary">
                <div class="box-header with-border">
                    <h3 class="box-title">Registry List</h3>

                    <div class="box-tools">
                        <a href="{{ route('admin.registries.new') }}" class="btn btn-sm btn-primary">Create New</a>
                    </div>
                </div>

                <div class="box-body table-responsive no-padding">
                    <table class="table table-hover">
                        <tbody>
                            <tr>
                                <th>ID</th>
                                <th>Name</th>
                                <th>Registry</th>
                                <th>Username</th>
                                <th class="text-center">Eggs</th>
                            </tr>
                            @forelse ($registries as $registry)
                                <tr>
                                    <td><code>{{ $registry->id }}</code></td>
                                    <td><a href="{{ route('admin.registries.view', $registry->id) }}">{{ $registry->name }}</a></td>
                                    <td><code>{{ $registry->registry }}</code></td>
                                    <td>{{ $registry->username }}</td>
                                    <td class="text-center">{{ $registry->eggs_count }}</td>
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="5" class="text-center text-muted">No Docker registries have been configured.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
@endsection
