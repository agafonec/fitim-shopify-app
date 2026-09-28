@extends('shopify-app::layouts.default')

@section('styles')
    @routes
    <link href="https://fonts.googleapis.com/css2?family=Rubik:ital,wght@0,300..900;1,300..900&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
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
