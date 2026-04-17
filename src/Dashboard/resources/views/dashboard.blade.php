<!DOCTYPE html>
<html lang="en" class="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Torque</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @fluxAppearance
    @livewireStyles
</head>
<body class="min-h-screen bg-white dark:bg-zinc-800">
    <livewire:torque.dashboard-shell :view="$view ?? null" />
    @livewireScripts
    @fluxScripts

    <script>
        // Keep the browser URL in sync with the active dashboard page so that
        // refreshes and bookmarks land on the right view, and back/forward
        // traverse the user's navigation history.
        document.addEventListener('livewire:init', () => {
            Livewire.on('torque-url-changed', ({ url }) => {
                if (typeof url === 'string' && window.location.pathname !== url) {
                    history.pushState({ torque: true }, '', url);
                }
            });

            window.addEventListener('popstate', () => {
                Livewire.dispatch('torque-url-popped', { path: window.location.pathname });
            });
        });
    </script>
</body>
</html>
