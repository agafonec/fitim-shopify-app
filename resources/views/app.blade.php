@extends('shopify-app::layouts.default')
@section('styles')
    @routes
    @vite(['resources/js/app.js', "resources/js/Pages/{$page['component']}.vue"])
    @inertiaHead
@endsection

@section('content')
    <!-- You are: (shop domain name) -->
    @inertia
@endsection

@section('scripts')
    @parent
    <script>
        // actions.TitleBar.create(app, { title: 'Welcome' });
    </script>
@endsection
