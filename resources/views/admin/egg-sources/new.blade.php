@extends('layouts.admin')

@section('title')
    Egg Sources &rarr; New
@endsection

@section('content-header')
    <h1>New Egg Source<small>Import eggs from a repository or URL.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.egg-sources') }}">Egg Sources</a></li>
        <li class="active">New</li>
    </ol>
@endsection

@section('content')
<form action="{{ route('admin.egg-sources.new') }}" method="POST">
    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Source Details</h3>
                </div>
                @include('admin.egg-sources._form', ['source' => null])
                <div class="box-footer">
                    {!! csrf_field() !!}
                    <button type="submit" class="btn btn-success btn-sm pull-right">Create</button>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
