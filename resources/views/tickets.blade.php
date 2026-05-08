<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshService Tickets</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    {{-- Vite-built Vue SPA: components in resources/js/components/*.vue, registered globally on a single Vue app. --}}
    @vite('resources/js/app.js')
</head>
<body>
    <div id="app"></div>
    <script src="https://unpkg.com/vue@3.5.13/dist/vue.global.prod.js"></script>
    <script src="{{ asset('js/task-lists-view.js') }}?v={{ filemtime(public_path('js/task-lists-view.js')) }}"></script>
    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
</body>
</html>
