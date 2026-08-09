<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>FreshService Tickets</title>
    <link rel="stylesheet" href="{{ asset('css/app.css') }}?v={{ filemtime(public_path('css/app.css')) }}">
    {{-- Renders stored UTC timestamps in the viewer's own time zone. A plain
         script loaded FIRST so the Vue panel and the SPA shell below share one
         implementation rather than each growing its own. --}}
    <script src="{{ asset('js/local-time.js') }}?v={{ filemtime(public_path('js/local-time.js')) }}"></script>
    {{-- Vite-built Vue SPA: components in resources/js/components/*.vue, registered globally on a single Vue app. --}}
    @vite('resources/js/app.js')
</head>
<body>
    <div id="app"></div>
    {{-- Mount point for the Vue TicketDetail side-panel. Lives outside #app so the legacy SPA shell can rewrite #app freely without unmounting it. The Vue bundle mounts on demand when window.openTicketDetail(id) is called. --}}
    <div id="ticket-detail-app"></div>
    {{-- Hand-written SPA shell (Tickets + Agents tabs). Loads after Vite bundle so window.taskListsTabHTML / mountTaskListsApp are available during renderApp(). --}}
    {{-- defer so it runs after the @vite module bundle (which exposes window.taskListsTabHTML / mountTaskListsApp). --}}
    <script src="{{ asset('js/app.js') }}?v={{ filemtime(public_path('js/app.js')) }}" defer></script>
</body>
</html>
