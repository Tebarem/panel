@extends('layouts.admin')

@section('title')
    Docker Registries &rarr; New
@endsection

@section('content-header')
    <h1>New Docker Registry<small>Add credentials for a private Docker registry.</small></h1>
    <ol class="breadcrumb">
        <li><a href="{{ route('admin.index') }}">Admin</a></li>
        <li><a href="{{ route('admin.registries') }}">Docker Registries</a></li>
        <li class="active">New</li>
    </ol>
@endsection

@section('content')
<form action="{{ route('admin.registries.new') }}" method="POST">
    <div class="row">
        <div class="col-xs-12">
            <div class="box">
                <div class="box-header with-border">
                    <h3 class="box-title">Registry Details</h3>
                </div>
                @include('admin.registries._form', ['registry' => null])
                <div class="box-footer">
                    {!! csrf_field() !!}
                    <button type="submit" class="btn btn-success btn-sm pull-right">Create</button>
                </div>
            </div>
        </div>
    </div>
</form>
@endsection
