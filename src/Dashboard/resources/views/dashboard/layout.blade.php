@props(['title' => 'Torque'])
<!DOCTYPE html>
<html
    lang="en"
    x-data
    :data-theme="$store.torque.theme"
>
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title }} · Torque</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;600;700&family=JetBrains+Mono:wght@400;500;600;700&display=swap" rel="stylesheet">
    {{ \Webpatser\Torque\Torque::css() }}
    @livewireStyles(['nonce' => \Webpatser\Torque\Torque::cspNonceValue()])
</head>
<body>
    {{-- Theme + nav state live in a persisted Alpine store so the chrome (inside
         the Livewire component) and <html data-theme> stay in sync and survive
         Livewire morphs. Alpine ships with Livewire 4, so this needs no build. --}}
    <script{!! \Webpatser\Torque\Torque::cspNonceAttribute() !!}>
        document.addEventListener('alpine:init', () => {
            Alpine.store('torque', {
                theme: Alpine.$persist('dark').as('tq.theme'),
                nav: Alpine.$persist(false).as('tq.nav'),
                toggleTheme() { this.theme = this.theme === 'dark' ? 'light' : 'dark' },
                toggleNav() { this.nav = ! this.nav },
            })
        })
    </script>

    {{ $slot }}

    @livewireScripts(['nonce' => \Webpatser\Torque\Torque::cspNonceValue()])
</body>
</html>
