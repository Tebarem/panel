@extends('layouts.admin')

@section('title')
    Docker Registries &rarr; {{ $registry->name }}
@endsection

@section('content-header')
    <h1>{{ $registry->name }}<small>{{ $registry->registry }}</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.registries') }}">Docker Registries</a></li>
        <li class="active">{{ $registry->name }}</li>
    </ol>
@endsection

@section('content')
<form action="{{ route('admin.registries.view', $registry->id) }}" method="POST">
    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Registry Details</h3>
                </div>
                @include('admin.registries._form', ['registry' => $registry])
                <div class="box-footer">
                    {!! csrf_field() !!}
                    <button type="submit" name="_method" value="PATCH" class="btn btn-primary btn-sm pull-right">Save</button>
                    <button type="submit" name="_method" value="DELETE" class="btn btn-danger btn-sm muted muted-hover" formaction="{{ route('admin.registries.delete', $registry->id) }}">
                        <i class="fa fa-trash-o"></i>
                    </button>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
