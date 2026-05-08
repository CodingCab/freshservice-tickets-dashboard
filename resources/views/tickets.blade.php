<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshService Tickets</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    {{-- Vite-built Vue bundle for the Task Lists tab. Vue is bundled from local node_modules. --}}
    @vite('resources/js/task-lists.js')
</head>
<body>
    <div id="app"></div>
    {{-- Hand-written SPA shell (Tickets + Agents tabs). Loads after Vite bundle so window.taskListsTabHTML is available during renderApp(). --}}
    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}"></script>
</body>
</html>
